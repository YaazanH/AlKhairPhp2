<?php

namespace App\Services;

use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StudentAttendanceDayService
{
    /**
     * @param  Collection<int, Group>  $groups
     */
    public function createOrSyncDay(string $attendanceDate, Collection $groups, ?User $actor = null, ?string $notes = null, string $status = 'open', ?int $defaultAttendanceStatusId = null): StudentAttendanceDay
    {
        return DB::transaction(function () use ($attendanceDate, $groups, $actor, $notes, $status, $defaultAttendanceStatusId): StudentAttendanceDay {
            $attendanceDate = Carbon::parse($attendanceDate)->toDateString();
            $day = StudentAttendanceDay::query()
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
                $day = StudentAttendanceDay::query()->create([
                    'attendance_date' => $attendanceDate,
                    'status' => $status,
                    'notes' => $notes ?: null,
                    'created_by' => $actor?->id,
                ]);
            }

            $groups
                ->unique('id')
                ->each(function (Group $group) use ($attendanceDate, $day, $actor, $status): void {
                    $groupDay = GroupAttendanceDay::query()
                        ->where('group_id', $group->id)
                        ->whereDate('attendance_date', $attendanceDate)
                        ->first() ?? new GroupAttendanceDay(['group_id' => $group->id]);

                    $groupDay->student_attendance_day_id = $day->id;
                    $groupDay->attendance_date = $attendanceDate;
                    $groupDay->created_by ??= $actor?->id;

                    if (! $groupDay->exists) {
                        $groupDay->status = $status;
                    }

                    $groupDay->save();
                });

            GroupAttendanceDay::query()
                ->whereDate('attendance_date', $attendanceDate)
                ->whereNull('student_attendance_day_id')
                ->update([
                    'student_attendance_day_id' => $day->id,
                ]);

            if ($defaultAttendanceStatusId) {
                $this->applyDefaultStudentStatus($groups, $attendanceDate, $defaultAttendanceStatusId, $actor);
            }

            return $this->syncAggregateStatus($day);
        });
    }

    public function syncAggregateStatus(StudentAttendanceDay $day): StudentAttendanceDay
    {
        $hasOpenSessions = $day->groupAttendanceDays()
            ->where('status', '!=', 'closed')
            ->exists();

        $day->update([
            'status' => $hasOpenSessions ? 'open' : 'closed',
        ]);

        return $day->fresh(['groupAttendanceDays']);
    }

    /**
     * @param  Collection<int, Group>  $groups
     */
    protected function applyDefaultStudentStatus(Collection $groups, string $attendanceDate, int $attendanceStatusId, ?User $actor): void
    {
        $groupIds = $groups->pluck('id')->filter()->unique()->values();

        if ($groupIds->isEmpty()) {
            return;
        }

        $status = AttendanceStatus::query()
            ->whereKey($attendanceStatusId)
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->first();

        if (! $status) {
            return;
        }

        $groupDays = GroupAttendanceDay::query()
            ->whereIn('group_id', $groupIds->all())
            ->whereDate('attendance_date', $attendanceDate)
            ->get()
            ->keyBy('group_id');

        $enrollments = Enrollment::query()
            ->with('student')
            ->whereIn('group_id', $groupIds->all())
            ->where('status', 'active')
            ->get();

        $ledger = app(PointLedgerService::class);

        foreach ($enrollments as $enrollment) {
            $groupDay = $groupDays->get($enrollment->group_id);

            if (! $groupDay) {
                continue;
            }

            $record = StudentAttendanceRecord::query()->firstOrNew([
                'group_attendance_day_id' => $groupDay->id,
                'enrollment_id' => $enrollment->id,
            ]);

            if ($record->exists && $record->attendance_status_id) {
                continue;
            }

            $record->fill([
                'attendance_status_id' => $status->id,
                'notes' => __('workflow.student_attendance.messages.default_status_note', ['status' => $status->name]),
            ])->save();

            $ledger->voidSourceTransactions('student_attendance_record', $record->id, __('workflow.student_attendance.messages.void_reason'));
            $ledger->recordAttendanceStatusPoints(
                $enrollment,
                'student_attendance_record',
                $record->id,
                $status,
                __('workflow.student_attendance.messages.automatic_points', ['status' => $status->name]),
            );
            $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
        }
    }
}
