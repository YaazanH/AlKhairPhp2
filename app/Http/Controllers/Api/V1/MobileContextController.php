<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsMobileRecords;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Session context, home-screen counters and reference lists for the mobile app.
 *
 * `me` tells the app which role family to render and which profile ids the
 * account is linked to; `summary` replaces the seven `per_page=1` probe
 * requests the app currently fires to count records; `lookups` supplies the
 * option lists the group form needs. Everything is scoped by
 * AccessScopeService and excludes financial data.
 */
class MobileContextController extends Controller
{
    use PresentsMobileRecords;

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['roles', 'parentProfile', 'studentProfile', 'teacherProfile']);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => (bool) $user->is_active,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
                'profiles' => [
                    'parent_id' => $user->parentProfile?->id,
                    'student_id' => $user->studentProfile?->id,
                    'teacher_id' => $user->teacherProfile?->id,
                ],
                // Present so a student account can open straight onto its own
                // record without first guessing an id.
                'student' => $user->studentProfile
                    ? $this->studentDetail($user->studentProfile->loadMissing([
                        'gradeLevel',
                        'quranCurrentJuz',
                        'enrollments.group.course',
                        'enrollments.group.teacher',
                    ]))
                    : null,
                'teacher' => $this->teacherSummary($user->teacherProfile),
                'is_unrestricted' => app(AccessScopeService::class)->isUnrestricted($user),
            ],
        ]);
    }

    /**
     * Home-screen counters for staff roles, in one request.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $scopes = app(AccessScopeService::class);
        $today = Carbon::today()->toDateString();

        $can = fn (string $permission): bool => (bool) $user?->can($permission);

        $counts = [
            'students' => $can('students.view')
                ? $scopes->scopeStudents(Student::query(), $user)->count()
                : null,
            'groups' => $can('groups.view')
                ? $scopes->scopeGroups(Group::query(), $user)->count()
                : null,
            'active_groups' => $can('groups.view')
                ? $scopes->scopeGroups(Group::query(), $user)->where('is_active', true)->count()
                : null,
            'enrollments' => $can('enrollments.view')
                ? $scopes->scopeEnrollments(Enrollment::query(), $user)->count()
                : null,
            'active_enrollments' => $can('enrollments.view')
                ? $scopes->scopeEnrollments(Enrollment::query(), $user)->where('status', 'active')->count()
                : null,
            'assessments' => $can('assessments.view')
                ? $scopes->scopeAssessments(Assessment::query(), $user)->count()
                : null,
        ];

        $attendanceToday = null;

        if ($can('attendance.student.view')) {
            $records = $scopes
                ->scopeStudentAttendanceRecords(StudentAttendanceRecord::query(), $user)
                ->whereHas('attendanceDay', fn (Builder $query) => $query->whereDate('attendance_date', $today))
                ->with('status')
                ->get();

            $attendanceToday = [
                'date' => $today,
                'recorded' => $records->count(),
                'present' => $records->filter(fn (StudentAttendanceRecord $record): bool => (bool) $record->status?->is_present)->count(),
                'absent' => $records->filter(fn (StudentAttendanceRecord $record): bool => ! $record->status?->is_present)->count(),
            ];
        }

        return response()->json([
            'data' => $counts + ['attendance_today' => $attendanceToday],
        ]);
    }

    /**
     * Reference lists for pickers. Teachers and groups are access-scoped;
     * courses, academic years, grade levels and attendance statuses are shared
     * reference data.
     */
    public function lookups(Request $request): JsonResponse
    {
        $user = $request->user();
        $scopes = app(AccessScopeService::class);

        return response()->json([
            'data' => [
                'academic_years' => AcademicYear::query()
                    ->where('is_active', true)
                    ->orderByDesc('starts_on')
                    ->get()
                    ->map(fn (AcademicYear $year): array => [
                        'id' => $year->id,
                        'name' => $year->name,
                        'is_current' => (bool) $year->is_current,
                    ])->values(),
                'courses' => Course::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Course $course): array => [
                        'id' => $course->id,
                        'name' => $course->name,
                        'is_default' => (bool) $course->is_default,
                    ])->values(),
                'grade_levels' => GradeLevel::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (GradeLevel $level): array => [
                        'id' => $level->id,
                        'name' => $level->name,
                    ])->values(),
                'attendance_statuses' => AttendanceStatus::query()
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (AttendanceStatus $status): array => [
                        'id' => $status->id,
                        'name' => $status->name,
                        'code' => $status->code,
                        'color' => $status->color,
                        'is_present' => (bool) $status->is_present,
                        'is_default' => (bool) $status->is_default,
                    ])->values(),
                'teachers' => $user?->can('teachers.view')
                    ? $scopes->scopeTeachers(Teacher::query(), $user)
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                        ->get()
                        ->map(fn (Teacher $teacher): ?array => $this->teacherSummary($teacher))
                        ->values()
                    : [],
            ],
        ]);
    }

    /**
     * Single group with its roster — the app currently rebuilds this from a
     * list item plus a separate enrollments call.
     */
    public function group(Request $request, Group $group): JsonResponse
    {
        $user = $request->user();

        abort_unless($user?->can('groups.view'), 403);
        abort_unless(app(AccessScopeService::class)->canAccessGroup($user, $group), 404);

        $group->load(['academicYear', 'course', 'teacher', 'assistantTeacher']);

        $enrollments = app(AccessScopeService::class)
            ->scopeEnrollments(Enrollment::query(), $user)
            ->with(['student.parentProfile', 'group.course', 'group.teacher'])
            ->where('group_id', $group->id)
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();

        // Scalar `teacher` / `course` / `academic_year` and the enrollment rows
        // deliberately mirror GET /groups and GET /enrollments so the app parses
        // this with the models it already has. The *_id fields are additional,
        // and let the group edit form prefill its pickers.
        return response()->json([
            'data' => [
                'id' => $group->id,
                'name' => $group->name,
                'is_active' => (bool) $group->is_active,
                'capacity' => $group->capacity,
                'monthly_fee' => $this->decimal($group->monthly_fee),
                'starts_on' => $this->date($group->starts_on),
                'ends_on' => $this->date($group->ends_on),
                'academic_year' => $group->academicYear?->name,
                'academic_year_id' => $group->academic_year_id,
                'course' => $group->course?->name,
                'course_id' => $group->course_id,
                'grade_level_id' => $group->grade_level_id,
                'teacher' => $group->teacher ? trim($group->teacher->first_name.' '.$group->teacher->last_name) : null,
                'teacher_id' => $group->teacher_id,
                'teacher_phone' => $group->teacher?->phone,
                'assistant_teacher' => $group->assistantTeacher ? trim($group->assistantTeacher->first_name.' '.$group->assistantTeacher->last_name) : null,
                'assistant_teacher_id' => $group->assistant_teacher_id,
                'enrollments_count' => $enrollments->count(),
                'enrollments' => $enrollments->map(fn (Enrollment $enrollment): array => [
                    'enrolled_at' => $this->date($enrollment->enrolled_at),
                    'final_points' => $enrollment->final_points_cached,
                    'group' => $enrollment->group ? [
                        'course_name' => $enrollment->group->course?->name,
                        'id' => $enrollment->group->id,
                        'name' => $enrollment->group->name,
                    ] : null,
                    'id' => $enrollment->id,
                    'left_at' => $this->date($enrollment->left_at),
                    'memorized_pages' => $enrollment->memorized_pages_cached,
                    'status' => $enrollment->status,
                    'student' => $enrollment->student ? [
                        'full_name' => $enrollment->student->full_name,
                        'id' => $enrollment->student->id,
                        'parent_name' => $enrollment->student->parentProfile?->father_name,
                    ] : null,
                ])->values(),
            ],
        ]);
    }
}
