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

        return $this->streamCsv('courses', ['Name', 'Description', 'Groups', 'Status'], $query->get()->map(fn (Course $course) => [
            $course->name,
            $course->description,
            $course->groups_count,
            $course->is_active ? 'Active' : 'Inactive',
        ])->all());
    }

    public function parents(Request $request, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('parents.view'), 403);

        $query = $scopes->scopeParents(ParentProfile::query(), $request->user())
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

        return $this->streamCsv('parents', ['Father', 'Mother', 'Students', 'Primary Phone', 'Status'], $query->get()->map(fn (ParentProfile $parent) => [
            $parent->father_name,
            $parent->mother_name,
            $parent->students_count,
            $parent->father_phone ?: ($parent->mother_phone ?: $parent->home_phone),
            $parent->is_active ? 'Active' : 'Inactive',
        ])->all());
    }

    public function students(Request $request, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('students.view'), 403);

        $query = $scopes->scopeStudents(Student::query(), $request->user())
            ->with(['parentProfile', 'gradeLevel', 'quranCurrentJuz'])
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

        return $this->streamCsv('students', ['Student', 'Student Number', 'Parent', 'School', 'Grade', 'Current Juz', 'Enrollments', 'Status'], $query->get()->map(fn (Student $student) => [
            trim($student->first_name.' '.$student->last_name),
            $student->student_number,
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
                    ->orWhere('job_title', 'like', '%'.$search.'%');
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive', 'blocked'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        return $this->streamCsv('teachers', ['Teacher', 'Phone', 'Job Title', 'Groups', 'Status'], $query->get()->map(fn (Teacher $teacher) => [
            trim($teacher->first_name.' '.$teacher->last_name),
            $teacher->phone,
            $teacher->job_title,
            $teacher->assigned_groups_count + $teacher->assisted_groups_count,
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

        return $this->streamCsv('groups', ['Group', 'Course', 'Teacher', 'Academic Year', 'Students', 'Status'], $query->get()->map(fn (Group $group) => [
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

        return $this->streamCsv('enrollments', ['Student', 'Group', 'Course', 'Enrolled At', 'Left At', 'Status'], $query->get()->map(fn (Enrollment $enrollment) => [
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

        return $this->streamCsv('users', ['Name', 'Username', 'Email', 'Phone', 'Roles', 'Direct Permissions', 'Profile', 'Status'], $query->get()->map(fn (User $user) => [
            $user->name,
            $user->username,
            $user->email,
            $user->phone,
            $user->getRoleNames()->implode(', '),
            $user->getDirectPermissions()->pluck('name')->implode(', '),
            $this->userProfileLabel($user),
            $user->is_active ? 'Active' : 'Inactive',
        ])->all());
    }

    protected function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
