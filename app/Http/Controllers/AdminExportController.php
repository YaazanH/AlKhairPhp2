<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminExportController extends Controller
{
    public function courses(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('courses.view'), 403);

        $query = Course::query()
            ->withCount('groups')
            ->orderBy('name');

        if (filled($request->string('search')->value())) {
            $search = $request->string('search')->value();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->string('status')->value() === 'active');
        }

        return $this->streamXlsx('courses', ['Name', 'Description', 'Starts On', 'Ends On', 'Groups', 'Status'], $query->get()->map(fn (Course $course) => [
            $course->name,
            $course->description,
            $course->starts_on?->format('Y-m-d'),
            $course->ends_on?->format('Y-m-d'),
            $course->groups_count,
            $course->is_active ? 'Active' : 'Inactive',
        ])->all());
    }

    public function parents(Request $request, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('parents.view'), 403);

        $query = $scopes->scopeParents(ParentProfile::query(), $request->user())
            ->with('user')
            ->withCount('students')
            ->orderBy('father_name');

        if (filled($request->string('search')->value())) {
            $search = $request->string('search')->value();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('father_name', 'like', '%'.$search.'%')
                    ->orWhere('mother_name', 'like', '%'.$search.'%')
                    ->orWhere('father_phone', 'like', '%'.$search.'%')
                    ->orWhere('mother_phone', 'like', '%'.$search.'%')
                    ->orWhere('home_phone', 'like', '%'.$search.'%');
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->string('status')->value() === 'active');
        }

        return $this->streamXlsx('parents', ['Father', 'Mother', 'Username', 'Password', 'Students', 'Primary Phone', 'Status'], $query->get()->map(fn (ParentProfile $parent) => [
            $parent->father_name,
            $parent->mother_name,
            $parent->user?->username,
            $parent->user?->issued_password,
            $parent->students_count,
            $parent->father_phone ?: ($parent->mother_phone ?: $parent->home_phone),
            $parent->is_active ? 'Active' : 'Inactive',
        ])->all());
    }

    public function students(Request $request, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('students.view'), 403);

        $query = $scopes->scopeStudents(Student::query(), $request->user())
            ->with(['parentProfile', 'gradeLevel', 'quranCurrentJuz', 'user'])
            ->withCount('enrollments')
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (filled($request->string('search')->value())) {
            $search = $request->string('search')->value();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('student_number', 'like', '%'.$search.'%')
                    ->orWhere('school_name', 'like', '%'.$search.'%')
                    ->orWhereHas('parentProfile', fn ($parentQuery) => $parentQuery
                        ->where('father_name', 'like', '%'.$search.'%')
                        ->orWhere('mother_name', 'like', '%'.$search.'%'));
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive', 'graduated', 'blocked'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        return $this->streamXlsx('students', ['Student', 'Student Number', 'Username', 'Password', 'Parent', 'School', 'Grade', 'Current Juz', 'Enrollments', 'Status'], $query->get()->map(fn (Student $student) => [
            trim($student->first_name.' '.$student->last_name),
            $student->student_number,
            $student->user?->username,
            $student->user?->issued_password,
            $student->parentProfile?->father_name,
            $student->school_name,
            $student->gradeLevel?->name,
            $student->quranCurrentJuz?->juz_number,
            $student->enrollments_count,
            ucfirst($student->status),
        ])->all());
    }

    public function teachers(Request $request, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('teachers.view'), 403);

        $query = $scopes->scopeTeachers(Teacher::query(), $request->user())
            ->with(['jobTitle', 'course', 'user'])
            ->withCount(['assignedGroups', 'assistedGroups'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (filled($request->string('search')->value())) {
            $search = $request->string('search')->value();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('job_title', 'like', '%'.$search.'%')
                    ->orWhereHas('jobTitle', fn ($titleQuery) => $titleQuery->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$search.'%'));
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive', 'blocked'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        if (in_array($request->string('helping')->value(), ['helping', 'not_helping'], true)) {
            $query->where('is_helping', $request->string('helping')->value() === 'helping');
        }

        return $this->streamXlsx('teachers', ['Teacher', 'Username', 'Password', 'Phone', 'Job Title', 'Course', 'Groups', 'Helping Now', 'Status'], $query->get()->map(fn (Teacher $teacher) => [
            trim($teacher->first_name.' '.$teacher->last_name),
            $teacher->user?->username,
            $teacher->user?->issued_password,
            $teacher->phone,
            $teacher->jobTitle?->name ?: $teacher->job_title,
            $teacher->course?->name,
            $teacher->assigned_groups_count + $teacher->assisted_groups_count,
            $teacher->is_helping ? 'Yes' : 'No',
            ucfirst($teacher->status),
        ])->all());
    }

    public function groups(Request $request, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('groups.view'), 403);

        $query = $scopes->scopeGroups(Group::query(), $request->user())
            ->with(['course', 'teacher', 'assistantTeacher', 'academicYear'])
            ->withCount('enrollments')
            ->orderByDesc('is_active')
            ->orderBy('name');

        if (filled($request->string('search')->value())) {
            $search = $request->string('search')->value();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('academicYear', fn ($yearQuery) => $yearQuery->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('teacher', fn ($teacherQuery) => $teacherQuery
                        ->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%'))
                    ->orWhereHas('assistantTeacher', fn ($teacherQuery) => $teacherQuery
                        ->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%'));
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->string('status')->value() === 'active');
        }

        return $this->streamXlsx('groups', ['Group', 'Course', 'Teacher', 'Academic Year', 'Students', 'Status'], $query->get()->map(fn (Group $group) => [
            $group->name,
            $group->course?->name,
            $group->teacher ? trim($group->teacher->first_name.' '.$group->teacher->last_name) : null,
            $group->academicYear?->name,
            $group->enrollments_count,
            $group->is_active ? 'Active' : 'Inactive',
        ])->all());
    }

    public function enrollments(Request $request, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('enrollments.view'), 403);

        $query = $scopes->scopeEnrollments(Enrollment::query(), $request->user())
            ->with(['student', 'group.course'])
            ->orderByDesc('enrolled_at');

        if (filled($request->string('search')->value())) {
            $search = $request->string('search')->value();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->whereHas('student', fn ($studentQuery) => $studentQuery
                        ->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%'))
                    ->orWhereHas('group', fn ($groupQuery) => $groupQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$search.'%')));
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'completed', 'cancelled'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        return $this->streamXlsx('enrollments', ['Student', 'Group', 'Course', 'Enrolled At', 'Left At', 'Status'], $query->get()->map(fn (Enrollment $enrollment) => [
            $enrollment->student ? trim($enrollment->student->first_name.' '.$enrollment->student->last_name) : null,
            $enrollment->group?->name,
            $enrollment->group?->course?->name,
            $enrollment->enrolled_at?->format('Y-m-d'),
            $enrollment->left_at?->format('Y-m-d'),
            ucfirst($enrollment->status),
        ])->all());
    }

    public function users(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('users.view'), 403);

        $query = User::query()
            ->with(['roles', 'permissions', 'teacherProfile', 'parentProfile', 'studentProfile'])
            ->orderBy('name');

        if (filled($request->string('search')->value())) {
            $search = $request->string('search')->value();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('role') && Role::query()->where('name', $request->string('role')->value())->exists()) {
            $query->role($request->string('role')->value());
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->string('status')->value() === 'active');
        }

        return $this->streamXlsx('users', ['Name', 'Username', 'Password', 'Email', 'Phone', 'Roles', 'Direct Permissions', 'Profile', 'Status'], $query->get()->map(fn (User $user) => [
            $user->name,
            $user->username,
            $user->issued_password,
            $user->email,
            $user->phone,
            $user->getRoleNames()->implode(', '),
            $user->getDirectPermissions()->pluck('name')->implode(', '),
            $this->userProfileLabel($user),
            $user->is_active ? 'Active' : 'Inactive',
        ])->all());
    }

    protected function streamXlsx(string $filename, array $headers, array $rows): StreamedResponse
    {
        return app(XlsxExportService::class)->download($filename, $headers, $rows);
    }

    protected function userProfileLabel(User $user): ?string
    {
        if ($user->teacherProfile) {
            return 'Teacher';
        }

        if ($user->parentProfile) {
            return 'Parent';
        }

        if ($user->studentProfile) {
            return 'Student';
        }

        return null;
    }
}
