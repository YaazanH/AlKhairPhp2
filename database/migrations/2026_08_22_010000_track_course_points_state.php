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
            $table->boolean('course_finished_was_awarding_points')->nullable()->after('awards_points');
        });

        $currentAcademicYearId = DB::table('academic_years')
            ->where('is_current', true)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->value('id');

        $currentAcademicYearId ??= DB::table('academic_years')
            ->where('is_active', true)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->value('id');

        if ($currentAcademicYearId) {
            DB::table('academic_years')->update(['is_current' => false]);
            DB::table('academic_years')->where('id', $currentAcademicYearId)->update(['is_current' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('course_finished_was_awarding_points');
        });
    }
};
