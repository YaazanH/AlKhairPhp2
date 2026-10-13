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
            $table->unsignedTinyInteger('curriculum_days_per_week')->default(1)->after('ends_on');
        });

        DB::table('courses')->orderBy('id')->each(function (object $course): void {
            $scheduledDays = DB::table('course_schedules')
                ->where('course_id', $course->id)
                ->distinct()
                ->count('day_of_week');

            DB::table('courses')->where('id', $course->id)->update([
                'curriculum_days_per_week' => max(1, min(7, $scheduledDays)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('curriculum_days_per_week');
        });
    }
};
