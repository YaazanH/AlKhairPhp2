<?php

use App\Support\ScheduleTimeSlots;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_schedules', function (Blueprint $table): void {
            $table->string('time_slot', 50)->nullable()->after('day_of_week');
        });

        DB::table('group_schedules')->orderBy('id')->each(function (object $schedule): void {
            DB::table('group_schedules')->where('id', $schedule->id)->update([
                'time_slot' => ScheduleTimeSlots::closest($schedule->starts_at),
            ]);
        });

        Schema::create('course_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->string('time_slot', 50);
            $table->timestamps();
            $table->unique(['course_id', 'day_of_week', 'time_slot'], 'course_schedule_slot_unique');
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->dropUnique(['academic_year_id', 'name']);
            $table->unique(['course_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropUnique(['course_id', 'name']);
            $table->unique(['academic_year_id', 'name']);
        });

        Schema::dropIfExists('course_schedules');

        Schema::table('group_schedules', function (Blueprint $table): void {
            $table->dropColumn('time_slot');
        });
    }
};
