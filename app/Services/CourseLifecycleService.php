<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\StudentAttendanceDay;
use App\Models\Teacher;
use App\Models\TeacherAttendanceRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseLifecycleService
{
    public function finish(Course $course): void
    {
        DB::transaction(function () use ($course): void {
            $finishedAt = now();
            $wasDefault = $course->is_default;
            $wasAwardingPoints = $course->finished_at
                ? $course->course_finished_was_awarding_points
                : $course->awards_points;

            $course->forceFill([
                'finished_at' => $course->finished_at ?: $finishedAt,
                'is_active' => false,
                'is_default' => false,
                'awards_points' => false,
                'course_finished_was_awarding_points' => (bool) $wasAwardingPoints,
            ])->save();

            $groupIds = Group::query()
                ->where('course_id', $course->id)
                ->pluck('id');

            $teacherIds = Teacher::query()
                ->where('course_id', $course->id)
                ->pluck('id')
                ->merge(Group::query()->whereIn('id', $groupIds)->pluck('teacher_id'))
                ->merge(Group::query()->whereIn('id', $groupIds)->pluck('assistant_teacher_id'))
                ->filter()
                ->unique()
                ->values();

            Group::query()
                ->whereIn('id', $groupIds)
                ->whereNull('course_finished_at')
                ->get()
                ->each(function (Group $group) use ($finishedAt): void {
                    $group->forceFill([
                        'course_finished_at' => $finishedAt,
                        'course_finished_was_active' => $group->is_active,
                        'is_active' => false,
                    ])->save();
                });

            Enrollment::query()
                ->whereIn('group_id', $groupIds)
                ->whereNull('course_finished_at')
                ->get()
                ->each(function (Enrollment $enrollment) use ($finishedAt): void {
                    $wasActive = $enrollment->status === 'active';

                    $enrollment->forceFill([
                        'course_finished_at' => $finishedAt,
                        'course_finished_previous_status' => $enrollment->status,
                        'course_finished_previous_left_at' => $enrollment->left_at,
                        'left_at' => $wasActive ? $finishedAt->toDateString() : $enrollment->left_at,
                        'status' => $wasActive ? 'completed' : $enrollment->status,
                    ])->save();
                });

            Assessment::query()
                ->where('is_active', true)
                ->where(function ($query) use ($groupIds): void {
                    $query->whereIn('group_id', $groupIds)
                        ->orWhereHas('groups', fn ($groups) => $groups->whereIn('groups.id', $groupIds));
                })
                ->update([
                    'course_finished_at' => $finishedAt,
                    'is_active' => false,
                ]);

            $studentAttendanceDays = StudentAttendanceDay::query()
                ->where('course_id', $course->id)
                ->whereNull('course_finished_at')
                ->get();

            foreach ($studentAttendanceDays as $attendanceDay) {
                $attendanceDay->forceFill([
                    'course_finished_at' => $finishedAt,
                    'course_finished_was_open' => $attendanceDay->status === 'open',
                    'status' => 'closed',
                ])->save();
                $attendanceDay->groupAttendanceDays()->update(['status' => 'closed']);
            }

            if ($teacherIds->isNotEmpty()) {
                TeacherAttendanceRecord::query()
                    ->whereIn('teacher_id', $teacherIds)
                    ->whereNull('course_finished_at')
                    ->when($course->starts_on, fn ($query) => $query->whereHas('attendanceDay', fn ($dayQuery) => $dayQuery->whereDate('attendance_date', '>=', $course->starts_on)))
                    ->when($course->ends_on, fn ($query) => $query->whereHas('attendanceDay', fn ($dayQuery) => $dayQuery->whereDate('attendance_date', '<=', $course->ends_on)))
                    ->update([
                        'archived_course_id' => $course->id,
                        'course_finished_at' => $finishedAt,
                    ]);
            }

            if ($wasDefault) {
                Course::query()
                    ->whereKeyNot($course->id)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->first()
                    ?->update(['is_default' => true]);
            }
        });
    }

    public function reactivate(Course $course): void
    {
        $course->loadMissing('academicYear');

        if ($course->academicYear && ! $course->academicYear->is_active) {
            throw ValidationException::withMessages([
                'course' => __('crud.courses.errors.finished_academic_year'),
            ]);
        }

        DB::transaction(function () use ($course): void {
            $groupIds = Group::query()
                ->where('course_id', $course->id)
                ->pluck('id');

            Group::query()
                ->whereIn('id', $groupIds)
                ->whereNotNull('course_finished_at')
                ->get()
                ->each(function (Group $group): void {
                    $group->forceFill([
                        'course_finished_at' => null,
                        'is_active' => $group->course_finished_was_active ?? true,
                        'course_finished_was_active' => null,
                    ])->save();
                });

            Enrollment::query()
                ->whereIn('group_id', $groupIds)
                ->whereNotNull('course_finished_at')
                ->get()
                ->each(function (Enrollment $enrollment): void {
                    $enrollment->forceFill([
                        'course_finished_at' => null,
                        'course_finished_previous_status' => null,
                        'course_finished_previous_left_at' => null,
                        'left_at' => $enrollment->course_finished_previous_left_at,
                        'status' => $enrollment->course_finished_previous_status ?: 'active',
                    ])->save();
                });

            Assessment::query()
                ->whereNotNull('course_finished_at')
                ->where(function ($query) use ($groupIds): void {
                    $query->whereIn('group_id', $groupIds)
                        ->orWhereHas('groups', fn ($groups) => $groups->whereIn('groups.id', $groupIds));
                })
                ->update([
                    'course_finished_at' => null,
                    'is_active' => true,
                ]);

            StudentAttendanceDay::query()
                ->where('course_id', $course->id)
                ->whereNotNull('course_finished_at')
                ->get()
                ->each(function (StudentAttendanceDay $attendanceDay): void {
                    $status = $attendanceDay->course_finished_was_open ? 'open' : 'closed';

                    $attendanceDay->groupAttendanceDays()->update(['status' => $status]);
                    $attendanceDay->forceFill([
                        'course_finished_at' => null,
                        'course_finished_was_open' => false,
                        'status' => $status,
                    ])->save();
                });

            TeacherAttendanceRecord::query()
                ->where('archived_course_id', $course->id)
                ->whereNotNull('course_finished_at')
                ->update([
                    'archived_course_id' => null,
                    'course_finished_at' => null,
                ]);

            $course->forceFill([
                'finished_at' => null,
                'is_active' => true,
                'awards_points' => (bool) $course->course_finished_was_awarding_points,
                'course_finished_was_awarding_points' => null,
            ])->save();
        });
    }

    public function archiveSummary(Course $course): array
    {
        $groupIds = Group::query()->where('course_id', $course->id)->pluck('id');

        return [
            'groups' => Group::query()->whereIn('id', $groupIds)->whereNotNull('course_finished_at')->count(),
            'enrollments' => Enrollment::query()->whereIn('group_id', $groupIds)->whereNotNull('course_finished_at')->count(),
            'assessments' => Assessment::query()->whereNotNull('course_finished_at')->where(function ($query) use ($groupIds): void {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereHas('groups', fn ($groups) => $groups->whereIn('groups.id', $groupIds));
            })->count(),
            'student_attendance' => StudentAttendanceDay::query()->where('course_id', $course->id)->whereNotNull('course_finished_at')->count(),
            'teacher_attendance' => TeacherAttendanceRecord::query()->where('archived_course_id', $course->id)->whereNotNull('course_finished_at')->count(),
        ];
    }
}
