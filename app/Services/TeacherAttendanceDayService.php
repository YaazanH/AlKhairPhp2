<?php

namespace App\Services;

use App\Models\AttendanceStatus;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherAttendanceDayService
{
    /**
     * @param  Collection<int, Teacher>  $teachers
     */
    public function createOrSyncDay(string $attendanceDate, Collection $teachers, ?User $actor = null, ?string $notes = null, string $status = 'open', ?int $defaultAttendanceStatusId = null): TeacherAttendanceDay
    {
        return DB::transaction(function () use ($attendanceDate, $teachers, $actor, $notes, $status, $defaultAttendanceStatusId): TeacherAttendanceDay {
            $attendanceDate = Carbon::parse($attendanceDate)->toDateString();

            $day = TeacherAttendanceDay::query()
                ->whereDate('attendance_date', $attendanceDate)
                ->first();

            if ($day) {
                $day->fill([
                    'attendance_date' => $attendanceDate,
                    'status' => $status,
                    'notes' => $notes ?: null,
                    'created_by' => $day->created_by ?? $actor?->id,
                ])->save();
            } else {
                $day = TeacherAttendanceDay::query()->create([
                    'attendance_date' => $attendanceDate,
                    'status' => $status,
                    'notes' => $notes ?: null,
                    'created_by' => $actor?->id,
                ]);
            }

            $defaultStatus = $this->resolveDefaultStatus($defaultAttendanceStatusId);

            $teachers
                ->filter(fn ($teacher) => $teacher instanceof Teacher)
                ->unique('id')
                ->each(function (Teacher $teacher) use ($day, $defaultStatus): void {
                    $record = TeacherAttendanceRecord::query()->firstOrNew([
                        'teacher_attendance_day_id' => $day->id,
                        'teacher_id' => $teacher->id,
                    ]);

                    if (! $record->exists) {
                        $record->attendance_status_id = $defaultStatus?->id;
                        $record->notes = $defaultStatus
                            ? __('workflow.teacher_attendance.messages.default_status_note', ['status' => $defaultStatus->name])
                            : null;
                        $record->save();

                        return;
                    }

                    if (! $record->attendance_status_id && $defaultStatus) {
                        $record->update([
                            'attendance_status_id' => $defaultStatus->id,
                            'notes' => $record->notes ?: __('workflow.teacher_attendance.messages.default_status_note', ['status' => $defaultStatus->name]),
                        ]);
                    }
                });

            return $day->fresh(['records.teacher.accessRole', 'records.status']);
        });
    }

    protected function resolveDefaultStatus(?int $attendanceStatusId): ?AttendanceStatus
    {
        if (! $attendanceStatusId) {
            return null;
        }

        return AttendanceStatus::query()
            ->whereKey($attendanceStatusId)
            ->where('is_active', true)
            ->whereIn('scope', ['teacher', 'both'])
            ->first();
    }
}
