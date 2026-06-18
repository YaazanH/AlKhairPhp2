<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attendance_days', function (Blueprint $table) {
            $table->foreignId('course_id')
                ->nullable()
                ->after('attendance_date')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('student_attendance_days', function (Blueprint $table) {
            $table->dropUnique('student_attendance_days_attendance_date_unique');
        });

        $this->splitExistingAttendanceDaysByCourse();
        $this->attachDetachedGroupAttendanceDays();

        Schema::table('student_attendance_days', function (Blueprint $table) {
            $table->unique(['attendance_date', 'course_id'], 'student_attendance_days_date_course_unique');
        });
    }

    public function down(): void
    {
        Schema::table('student_attendance_days', function (Blueprint $table) {
            $table->dropUnique('student_attendance_days_date_course_unique');
        });

        $dates = DB::table('student_attendance_days')
            ->select('attendance_date', DB::raw('MIN(id) as keep_id'))
            ->groupBy('attendance_date')
            ->get();

        foreach ($dates as $date) {
            $duplicateIds = DB::table('student_attendance_days')
                ->where('attendance_date', $date->attendance_date)
                ->where('id', '!=', $date->keep_id)
                ->pluck('id');

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::table('group_attendance_days')
                ->whereIn('student_attendance_day_id', $duplicateIds->all())
                ->update([
                    'student_attendance_day_id' => $date->keep_id,
                ]);

            DB::table('student_attendance_days')
                ->whereIn('id', $duplicateIds->all())
                ->delete();
        }

        Schema::table('student_attendance_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
            $table->unique('attendance_date');
        });
    }

    protected function splitExistingAttendanceDaysByCourse(): void
    {
        $days = DB::table('student_attendance_days')
            ->orderBy('id')
            ->get();

        foreach ($days as $day) {
            $courseIds = DB::table('group_attendance_days')
                ->join('groups', 'groups.id', '=', 'group_attendance_days.group_id')
                ->where('group_attendance_days.student_attendance_day_id', $day->id)
                ->whereNotNull('groups.course_id')
                ->distinct()
                ->orderBy('groups.course_id')
                ->pluck('groups.course_id')
                ->map(fn ($courseId) => (int) $courseId)
                ->values();

            if ($courseIds->isEmpty()) {
                continue;
            }

            $primaryCourseId = $courseIds->shift();

            DB::table('student_attendance_days')
                ->where('id', $day->id)
                ->update(['course_id' => $primaryCourseId]);

            foreach ($courseIds as $courseId) {
                $newDayId = DB::table('student_attendance_days')->insertGetId([
                    'attendance_date' => $day->attendance_date,
                    'course_id' => $courseId,
                    'status' => $day->status,
                    'notes' => $day->notes,
                    'created_by' => $day->created_by,
                    'created_at' => $day->created_at,
                    'updated_at' => $day->updated_at,
                ]);

                DB::table('group_attendance_days')
                    ->where('student_attendance_day_id', $day->id)
                    ->whereIn('group_id', function ($query) use ($courseId) {
                        $query->select('id')
                            ->from('groups')
                            ->where('course_id', $courseId);
                    })
                    ->update([
                        'student_attendance_day_id' => $newDayId,
                    ]);
            }
        }
    }

    protected function attachDetachedGroupAttendanceDays(): void
    {
        $detachedDays = DB::table('group_attendance_days')
            ->join('groups', 'groups.id', '=', 'group_attendance_days.group_id')
            ->select([
                'group_attendance_days.id',
                'group_attendance_days.attendance_date',
                'group_attendance_days.status',
                'group_attendance_days.notes',
                'group_attendance_days.created_by',
                'group_attendance_days.created_at',
                'group_attendance_days.updated_at',
                'groups.course_id',
            ])
            ->whereNull('group_attendance_days.student_attendance_day_id')
            ->whereNotNull('groups.course_id')
            ->orderBy('group_attendance_days.id')
            ->get();

        foreach ($detachedDays as $groupDay) {
            $studentAttendanceDayId = DB::table('student_attendance_days')
                ->where('attendance_date', $groupDay->attendance_date)
                ->where('course_id', $groupDay->course_id)
                ->value('id');

            if (! $studentAttendanceDayId) {
                $studentAttendanceDayId = DB::table('student_attendance_days')->insertGetId([
                    'attendance_date' => $groupDay->attendance_date,
                    'course_id' => $groupDay->course_id,
                    'status' => $groupDay->status ?? 'open',
                    'notes' => $groupDay->notes,
                    'created_by' => $groupDay->created_by,
                    'created_at' => $groupDay->created_at,
                    'updated_at' => $groupDay->updated_at,
                ]);
            }

            DB::table('group_attendance_days')
                ->where('id', $groupDay->id)
                ->update([
                    'student_attendance_day_id' => $studentAttendanceDayId,
                ]);
        }
    }
};
