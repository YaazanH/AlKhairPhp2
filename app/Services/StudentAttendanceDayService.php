<?php

namespace App\Services;

use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StudentAttendanceDayService
{
    /**
     * @param  Collection<int, Group>  $groups
     */
    public function createOrSyncDay(string $attendanceDate, Collection $groups, ?User $actor = null, ?string $notes = null, string $status = 'open', ?int $defaultAttendanceStatusId = null, ?int $courseId = null): StudentAttendanceDay
    {
        return DB::transaction(function () use ($attendanceDate, $groups, $actor, $notes, $status, $defaultAttendanceStatusId, $courseId): StudentAttendanceDay {
            $attendanceDate = Carbon::parse($attendanceDate)->toDateString();
            $courseId = $this->resolveCourseId($groups, $courseId);

            $day = StudentAttendanceDay::query()
                ->whereDate('attendance_date', $attendanceDate)
                ->where('course_id', $courseId)
                ->first();

            if ($day) {
                $day->fill([
                    'attendance_date' => $attendanceDate,
                    'course_id' => $courseId,
                    'notes' => $notes ?: null,
                    'created_by' => $day->created_by ?? $actor?->id,
                ])->save();
            } else {
                $day = StudentAttendanceDay::query()->create([
                    'attendance_date' => $attendanceDate,
                    'course_id' => $courseId,
                    'status' => $status,
                    'notes' => $notes ?: null,
                    'created_by' => $actor?->id,
                ]);
            }

            $groups
                ->unique('id')
                ->each(function (Group $group) use ($attendanceDate, $day, $actor): void {
                    $groupDay = GroupAttendanceDay::query()
                        ->where('group_id', $group->id)
                        ->whereDate('attendance_date', $attendanceDate)
                        ->first() ?? new GroupAttendanceDay(['group_id' => $group->id]);

                    $groupDay->student_attendance_day_id = $day->id;
                    $groupDay->attendance_date = $attendanceDate;
                    $groupDay->created_by ??= $actor?->id;

                    $groupDay->status = $day->status;

                    $groupDay->save();
                });

            GroupAttendanceDay::query()
                ->whereDate('attendance_date', $attendanceDate)
                ->whereNull('student_attendance_day_id')
                ->whereIn('group_id', function ($query) use ($courseId) {
                    $query->select('id')
                        ->from('groups')
                        ->where('course_id', $courseId);
                })
                ->update([
                    'student_attendance_day_id' => $day->id,
                ]);

            if ($defaultAttendanceStatusId) {
                $this->applyDefaultStudentStatus($groups, $attendanceDate, $defaultAttendanceStatusId, $actor);
            }

            return $this->syncAggregateStatus($day);
        });
    }

    /**
     * @param  Collection<int, Group>  $groups
     */
    protected function resolveCourseId(Collection $groups, ?int $explicitCourseId): int
    {
        $derivedCourseIds = $groups
            ->pluck('course_id')
            ->filter()
            ->map(fn ($courseId) => (int) $courseId)
            ->unique()
            ->values();

        if ($derivedCourseIds->count() > 1) {
            throw new InvalidArgumentException('Student attendance days must be created for a single course.');
        }

        $derivedCourseId = $derivedCourseIds->first();

        if ($explicitCourseId && $derivedCourseId && $explicitCourseId !== $derivedCourseId) {
            throw new InvalidArgumentException('The selected course does not match the supplied groups.');
        }

        $courseId = $explicitCourseId ?: $derivedCourseId;

        if (! $courseId) {
            throw new InvalidArgumentException('Student attendance days require a course context.');
        }

        return (int) $courseId;
    }

    public function syncAggregateStatus(StudentAttendanceDay $day): StudentAttendanceDay
    {
        $day->groupAttendanceDays()->update([
            'status' => $day->status,
        ]);

        return $day->fresh(['groupAttendanceDays']);
    }

    public function setDayStatus(StudentAttendanceDay $day, string $status): StudentAttendanceDay
    {
        if ($day->fresh()->course_finished_at) {
            throw new InvalidArgumentException(__('workflow.student_attendance.messages.archived_day_locked'));
        }

        if (! in_array($status, ['open', 'closed'], true)) {
            throw new InvalidArgumentException('Student attendance day status must be open or closed.');
        }

        return DB::transaction(function () use ($day, $status): StudentAttendanceDay {
            $day->groupAttendanceDays()->update([
                'status' => $status,
            ]);

            $day->update([
                'status' => $status,
            ]);

            return $day->fresh(['groupAttendanceDays']);
        });
    }

    public function recordEnrollmentStatus(StudentAttendanceDay $day, Enrollment $enrollment, AttendanceStatus $status, ?string $notes = null): StudentAttendanceRecord
    {
        $this->assertAttendanceDayOpen($day->fresh());

        $groupDay = GroupAttendanceDay::query()
            ->where('student_attendance_day_id', $day->id)
            ->where('group_id', $enrollment->group_id)
            ->first();

        if (! $groupDay) {
            throw new InvalidArgumentException(__('workflow.student_attendance.messages.enrollment_not_in_day'));
        }

        return DB::transaction(function () use ($day, $enrollment, $groupDay, $notes, $status): StudentAttendanceRecord {
            $record = StudentAttendanceRecord::query()->updateOrCreate(
                [
                    'group_attendance_day_id' => $groupDay->id,
                    'enrollment_id' => $enrollment->id,
                ],
                [
                    'attendance_status_id' => $status->id,
                    'notes' => $notes,
                    'quick_attendance_added_at' => null,
                    'quick_attendance_previous' => null,
                ],
            );

            $ledger = app(PointLedgerService::class);
            $ledger->voidSourceTransactions('student_attendance_record', $record->id, __('workflow.student_attendance.messages.void_reason'));
            $ledger->recordAttendanceStatusPoints(
                $enrollment,
                'student_attendance_record',
                $record->id,
                $status,
                __('workflow.student_attendance.messages.automatic_points', ['status' => $status->name]),
            );
            $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
            $this->syncAggregateStatus($day);

            return $record->fresh(['status']);
        });
    }

    public function recordQuickEnrollmentStatus(StudentAttendanceDay $day, Enrollment $enrollment, AttendanceStatus $status): StudentAttendanceRecord
    {
        return DB::transaction(function () use ($day, $enrollment, $status): StudentAttendanceRecord {
            $day = StudentAttendanceDay::query()->lockForUpdate()->findOrFail($day->id);
            $this->assertAttendanceDayOpen($day);

            if ($enrollment->status !== 'active' || (int) $enrollment->group->course_id !== (int) $day->course_id) {
                throw new InvalidArgumentException(__('workflow.student_attendance.messages.enrollment_not_in_day'));
            }

            if (! $day->groupAttendanceDays()->where('group_id', $enrollment->group_id)->exists()) {
                $this->createOrSyncDay($day->attendance_date->toDateString(), collect([$enrollment->group]), auth()->user(), $day->notes, $day->status, courseId: $day->course_id);
            }

            $previous = StudentAttendanceRecord::query()
                ->whereHas('attendanceDay', fn ($query) => $query->where('student_attendance_day_id', $day->id))
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()->first();
            $snapshot = $previous?->quick_attendance_previous ?? [
                'exists' => $previous !== null,
                'attendance_status_id' => $previous?->attendance_status_id,
                'notes' => $previous?->notes,
            ];
            $addedAt = $previous?->quick_attendance_added_at ?? now();
            $record = $this->recordEnrollmentStatus($day, $enrollment, $status);
            $record->update([
                'quick_attendance_added_at' => $addedAt,
                'quick_attendance_previous' => $snapshot,
            ]);

            return $record;
        });
    }

    public function undoQuickEnrollmentStatus(StudentAttendanceDay $day, Enrollment $enrollment): void
    {
        DB::transaction(function () use ($day, $enrollment): void {
            $day = StudentAttendanceDay::query()->lockForUpdate()->findOrFail($day->id);
            $this->assertAttendanceDayOpen($day);
            $record = StudentAttendanceRecord::query()
                ->whereHas('attendanceDay', fn ($query) => $query->where('student_attendance_day_id', $day->id))
                ->where('enrollment_id', $enrollment->id)
                ->whereNotNull('quick_attendance_added_at')
                ->lockForUpdate()->first();

            if (! $record) {
                return;
            }

            $previous = $record->quick_attendance_previous;
            $status = AttendanceStatus::find($previous['attendance_status_id'] ?? null);
            if (($previous['exists'] ?? false) && $status) {
                $this->recordEnrollmentStatus($day, $enrollment, $status, $previous['notes'] ?? null);

                return;
            }

            $ledger = app(PointLedgerService::class);
            $ledger->voidSourceTransactions('student_attendance_record', $record->id, __('workflow.student_attendance.messages.void_reason'));
            if ($previous['exists'] ?? false) {
                $record->update([
                    'attendance_status_id' => null,
                    'notes' => $previous['notes'] ?? null,
                    'quick_attendance_added_at' => null,
                    'quick_attendance_previous' => null,
                ]);
            } else {
                $record->delete();
            }
            $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
        });
    }

    protected function assertAttendanceDayOpen(StudentAttendanceDay $day): void
    {
        if ($day->course_finished_at) {
            throw new InvalidArgumentException(__('workflow.student_attendance.messages.archived_day_locked'));
        }
        if ($day->status === 'closed') {
            throw new InvalidArgumentException(__('workflow.student_attendance.messages.closed_day_locked'));
        }
    }

    public function fillMissingStatuses(StudentAttendanceDay $day, ?int $attendanceStatusId = null, ?User $actor = null): StudentAttendanceDay
    {
        $statusId = $attendanceStatusId
            ?: AttendanceStatus::query()
                ->where('is_default', true)
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->value('id')
            ?: AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->value('id');

        if (! $statusId) {
            return $day;
        }

        $groups = Group::query()
            ->whereIn('id', $day->groupAttendanceDays()->pluck('group_id'))
            ->get();

        $this->applyDefaultStudentStatus(
            $groups,
            $day->attendance_date->toDateString(),
            (int) $statusId,
            $actor,
        );

        return $day->fresh(['groupAttendanceDays.records.status']);
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
