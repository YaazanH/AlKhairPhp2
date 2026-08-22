<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->timestamp('finished_at')->nullable()->after('ends_on');
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->timestamp('course_finished_at')->nullable()->after('is_active');
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->timestamp('course_finished_at')->nullable()->after('left_at');
        });

        Schema::table('assessments', function (Blueprint $table): void {
            $table->timestamp('course_finished_at')->nullable()->after('is_active');
        });

        $fallbackAcademicYearId = DB::table('academic_years')
            ->orderByDesc('is_current')
            ->orderByDesc('starts_on')
            ->value('id');

        DB::table('courses')
            ->select('id')
            ->orderBy('id')
            ->each(function (object $course) use ($fallbackAcademicYearId): void {
                $academicYearId = DB::table('groups')
                    ->select('academic_year_id', DB::raw('COUNT(*) as group_count'))
                    ->where('course_id', $course->id)
                    ->whereNotNull('academic_year_id')
                    ->groupBy('academic_year_id')
                    ->orderByDesc('group_count')
                    ->value('academic_year_id');

                DB::table('courses')->where('id', $course->id)->update([
                    'academic_year_id' => $academicYearId ?: $fallbackAcademicYearId,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table): void {
            $table->dropColumn('course_finished_at');
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('course_finished_at');
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('course_finished_at');
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropColumn('finished_at');
        });
    }
};
