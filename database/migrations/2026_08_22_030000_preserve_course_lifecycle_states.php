<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->boolean('course_finished_was_active')->nullable()->after('course_finished_at');
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->string('course_finished_previous_status', 30)->nullable()->after('course_finished_at');
            $table->date('course_finished_previous_left_at')->nullable()->after('course_finished_previous_status');
        });

        DB::table('groups')
            ->whereNotNull('course_finished_at')
            ->whereNull('course_finished_was_active')
            ->update(['course_finished_was_active' => true]);

        DB::table('enrollments')
            ->whereNotNull('course_finished_at')
            ->whereNull('course_finished_previous_status')
            ->update([
                'course_finished_previous_status' => 'active',
                'course_finished_previous_left_at' => null,
            ]);

        DB::table('courses')
            ->whereNotNull('finished_at')
            ->orderBy('id')
            ->each(function (object $course): void {
                $groupIds = DB::table('groups')->where('course_id', $course->id)->pluck('id');

                DB::table('groups')
                    ->whereIn('id', $groupIds)
                    ->whereNull('course_finished_at')
                    ->update([
                        'course_finished_at' => $course->finished_at,
                        'course_finished_was_active' => false,
                    ]);

                DB::table('enrollments')
                    ->whereIn('group_id', $groupIds)
                    ->whereNull('course_finished_at')
                    ->orderBy('id')
                    ->each(function (object $enrollment) use ($course): void {
                        DB::table('enrollments')->where('id', $enrollment->id)->update([
                            'course_finished_at' => $course->finished_at,
                            'course_finished_previous_status' => $enrollment->status,
                            'course_finished_previous_left_at' => $enrollment->left_at,
                        ]);
                    });
            });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn([
                'course_finished_previous_status',
                'course_finished_previous_left_at',
            ]);
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('course_finished_was_active');
        });
    }
};
