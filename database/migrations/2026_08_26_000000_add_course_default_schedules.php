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
        if (! Schema::hasColumn('group_schedules', 'time_slot')) {
            Schema::table('group_schedules', function (Blueprint $table): void {
                $table->string('time_slot', 50)->nullable()->after('day_of_week');
            });
        }

        DB::table('group_schedules')
            ->whereNull('time_slot')
            ->orderBy('id')
            ->each(function (object $schedule): void {
                DB::table('group_schedules')->where('id', $schedule->id)->update([
                    'time_slot' => ScheduleTimeSlots::closest($schedule->starts_at),
                ]);
            });

        if (! Schema::hasTable('course_schedules')) {
            Schema::create('course_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('day_of_week');
                $table->string('time_slot', 50);
                $table->timestamps();
                $table->unique(['course_id', 'day_of_week', 'time_slot'], 'course_schedule_slot_unique');
            });
        }

        // MySQL may use the composite unique index as the supporting index for
        // the academic_year_id foreign key. Give that foreign key a dedicated
        // index before replacing the uniqueness rule.
        if (! Schema::hasIndex('groups', ['academic_year_id'])) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->index('academic_year_id', 'groups_academic_year_id_index');
            });
        }

        if (Schema::hasIndex('groups', 'groups_academic_year_id_name_unique')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->dropUnique('groups_academic_year_id_name_unique');
            });
        }

        if (! Schema::hasIndex('groups', 'groups_course_id_name_unique')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->unique(['course_id', 'name'], 'groups_course_id_name_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('groups', ['course_id'])) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->index('course_id', 'groups_course_id_index');
            });
        }

        if (Schema::hasIndex('groups', 'groups_course_id_name_unique')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->dropUnique('groups_course_id_name_unique');
            });
        }

        if (! Schema::hasIndex('groups', 'groups_academic_year_id_name_unique')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->unique(['academic_year_id', 'name'], 'groups_academic_year_id_name_unique');
            });
        }

        if (Schema::hasIndex('groups', 'groups_academic_year_id_index')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->dropIndex('groups_academic_year_id_index');
            });
        }

        Schema::dropIfExists('course_schedules');

        if (Schema::hasColumn('group_schedules', 'time_slot')) {
            Schema::table('group_schedules', function (Blueprint $table): void {
                $table->dropColumn('time_slot');
            });
        }
    }
};
