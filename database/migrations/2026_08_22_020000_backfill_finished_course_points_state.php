<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('courses')
            ->whereNotNull('finished_at')
            ->whereNull('course_finished_was_awarding_points')
            ->orderBy('id')
            ->each(function (object $course): void {
                DB::table('courses')->where('id', $course->id)->update([
                    'course_finished_was_awarding_points' => (bool) $course->awards_points,
                    'awards_points' => false,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('courses')
            ->whereNotNull('finished_at')
            ->whereNotNull('course_finished_was_awarding_points')
            ->orderBy('id')
            ->each(function (object $course): void {
                DB::table('courses')->where('id', $course->id)->update([
                    'awards_points' => (bool) $course->course_finished_was_awarding_points,
                    'course_finished_was_awarding_points' => null,
                ]);
            });
    }
};
