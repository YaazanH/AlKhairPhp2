<?php

namespace App\Services;

use App\Models\AttendanceStatus;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceExclusion;
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
    public function createOrSyncDay(string $attendanceDate, Collection $teachers, ?User $actor = null, ?string $notes = null, string $status = 'open', ?int $defaultAttendanceStatusId = null, ?int $courseId = null): TeacherAttendanceDay
    {
        return DB::transaction(function () use ($attendanceDate, $teachers, $actor, $notes, $status, $defaultAttendanceStatusId, $courseId): TeacherAttendanceDay {
            $attendanceDate = Carbon::parse($attendanceDate)->toDateString();

            $day = TeacherAttendanceDay::query()
                ->whereDate('attendance_date', $attendanceDate)
                ->where('course_id', $courseId)
                ->first();

            if ($day) {
                $day->fill([
                    'attendance_date' => $attendanceDate,
                    'course_id' => $courseId ?: $day->course_id,
                    'status' => $status,
                    'notes' => $notes ?: null,
                    'created_by' => $day->created_by ?? $actor?->id,
                ])->save();
            } else {
                $day = TeacherAttendanceDay::query()->create([
                    'attendance_date' => $attendanceDate,
                    'course_id' => $courseId,
                    'status' => $status,
                    'notes' => $notes ?: null,
                    'created_by' => $actor?->id,
                ]);
            }

            $defaultStatus = $this->resolveDefaultStatus($defaultAttendanceStatusId);

            $includedTeachers = app(AccessScopeService::class)->scopeTeachers(
                Teacher::query()->where('status', 'active')->whereIn('id', function ($query) use ($attendanceDate): void {
                    $query->select('teacher_id')->from('teacher_attendance_inclusions')->whereDate('starts_on', '<=', $attendanceDate);
                }), $actor
            )->get();

            $excludedIds = TeacherAttendanceExclusion::query()->pluck('teacher_id')->all();
            $teachers->merge($includedTeachers)
                ->filter(fn ($teacher) => $teacher instanceof Teacher)
                ->reject(fn ($teacher) => in_array($teacher->id, $excludedIds))
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

    public function addTeacherFromDay(TeacherAttendanceDay $day, Teacher $teacher, ?User $actor): void
    {
        abort_unless($teacher->status === 'active', 422);
        abort_if($day->fresh()->status === 'closed', 409);

        DB::transaction(function () use ($day, $teacher, $actor): void {
            TeacherAttendanceExclusion::query()->where('teacher_id', $teacher->id)->delete();
            $startsOn = DB::table('teacher_attendance_inclusions')->where('teacher_id', $teacher->id)->value('starts_on');
            DB::table('teacher_attendance_inclusions')->updateOrInsert(['teacher_id' => $teacher->id], [
                'starts_on' => min($startsOn ?: $day->attendance_date->toDateString(), $day->attendance_date->toDateString()),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $status = $this->resolveDefaultStatus(null);
            $days = app(AccessScopeService::class)->scopeTeacherAttendanceDays(
                TeacherAttendanceDay::query()->whereDate('attendance_date', '>=', $day->attendance_date)
                    ->where(fn ($query) => $query->whereNull('course_id')->orWhereHas('course', fn ($course) => $course->whereNull('finished_at'))),
                $actor
            )->get();
            foreach ($days as $futureDay) {
                TeacherAttendanceRecord::query()->firstOrCreate([
                    'teacher_attendance_day_id' => $futureDay->id, 'teacher_id' => $teacher->id,
                ], ['attendance_status_id' => $status?->id]);
            }
        });
    }

    public function scheduledTeachers(string $date, ?int $courseId, ?User $actor): Collection
    {
        $scopes = app(AccessScopeService::class);
        $teacherIds = $scopes->scopeGroups(Group::query()
            ->where('is_active', true)->where('course_id', $courseId)
            ->whereHas('schedules', fn ($query) => $query->where('is_active', true)->where('day_of_week', Carbon::parse($date)->dayOfWeek)), $actor)
            ->get()->flatMap(fn ($group) => [$group->teacher_id, $group->assistant_teacher_id])->filter();

        return $scopes->scopeTeachers(Teacher::query()->where('status', 'active')
            ->where(fn ($query) => $query->whereIn('id', $teacherIds)
                ->orWhere(fn ($helper) => $helper->where('is_helping', true)
                    ->whereDoesntHave('assignedGroups', fn ($group) => $group->where('is_active', true))
                    ->whereDoesntHave('assistedGroups', fn ($group) => $group->where('is_active', true))))
            ->whereNotIn('id', TeacherAttendanceExclusion::query()->select('teacher_id')), $actor)->get();
    }

    protected function resolveDefaultStatus(?int $attendanceStatusId): ?AttendanceStatus
    {
        return AttendanceStatus::query()
            ->when($attendanceStatusId, fn ($query) => $query->whereKey($attendanceStatusId))
            ->when(! $attendanceStatusId, fn ($query) => $query->orderByDesc('is_default')->orderByDesc('is_present')->orderBy('name'))
            ->where('is_active', true)
            ->whereIn('scope', ['teacher', 'both'])
            ->first();
    }

    public function fillMissingStatuses(TeacherAttendanceDay $day, ?int $attendanceStatusId = null): TeacherAttendanceDay
    {
        $status = $this->resolveDefaultStatus($attendanceStatusId);

        if (! $status) {
            return $day;
        }

        $day->records()
            ->whereNull('attendance_status_id')
            ->get()
            ->each(fn (TeacherAttendanceRecord $record) => $record->update([
                'attendance_status_id' => $status->id,
                'notes' => $record->notes ?: __('workflow.teacher_attendance.messages.default_status_note', ['status' => $status->name]),
            ]));

        return $day->fresh(['records.status']);
    }
}
