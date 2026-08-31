<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\QuranFinalTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PdfBrandingService;
use App\Services\QuranProgressionService;
use App\Services\XlsxExportService;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminExportController extends Controller
{
    public function courses(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('courses.view'), 403);

        $query = Course::query()
            ->with('academicYear')
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

        match ($request->string('status')->value()) {
            'active' => $query->where('is_active', true)->whereNull('course_finished_at'),
            'inactive' => $query->where('is_active', false)->whereNull('course_finished_at'),
            'finished' => $query->whereNotNull('course_finished_at'),
            default => null,
        };

        if ($request->filled('academic_year_id') && $request->string('academic_year_id')->value() !== 'all') {
            $query->where('academic_year_id', $request->integer('academic_year_id'));
        }

        return $this->streamXlsx('courses', ['Name', 'Description', 'Starts On', 'Ends On', 'Academic Year', 'Groups', 'Points', 'Status'], $query->get()->map(fn (Course $course) => [
            $course->name,
            $course->description,
            $course->starts_on?->format('d-m-Y'),
            $course->ends_on?->format('d-m-Y'),
            $course->academicYear?->name,
            $course->groups_count,
            $course->awards_points ? 'Enabled' : 'Disabled',
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
                    ->where('parent_number', 'like', '%'.$search.'%')
                    ->orWhere('father_name', 'like', '%'.$search.'%')
                    ->orWhere('mother_name', 'like', '%'.$search.'%')
                    ->orWhere('father_phone', 'like', '%'.$search.'%')
                    ->orWhere('mother_phone', 'like', '%'.$search.'%')
                    ->orWhere('home_phone', 'like', '%'.$search.'%');
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->string('status')->value() === 'active');
        }

        return $this->streamXlsx('parents', ['Parent No.', 'Father', 'Mother', 'Username', 'Password', 'Students', 'Primary Phone', 'Status'], $query->get()->map(fn (ParentProfile $parent) => [
            $parent->parent_number,
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
            $student->full_name,
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
            ->with(['accessRole', 'course', 'user'])
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
                    ->orWhereHas('accessRole', fn ($roleQuery) => $roleQuery->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$search.'%'));
            });
        }

        if (in_array($request->string('status')->value(), ['active', 'inactive', 'blocked', 'pending', 'declined'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        if (in_array($request->string('helping')->value(), ['helping', 'not_helping'], true)) {
            $query->where('is_helping', $request->string('helping')->value() === 'helping');
        }

        return $this->streamXlsx('teachers', ['Teacher', 'Username', 'Password', 'Phone', 'Access Role', 'Course', 'Groups', 'Helping Now', 'Status'], $query->get()->map(fn (Teacher $teacher) => [
            trim($teacher->first_name.' '.$teacher->last_name),
            $teacher->user?->username,
            $teacher->user?->issued_password,
            $teacher->phone,
            $teacher->accessRole?->name,
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

        if ($request->filled('course_id') && $request->string('course_id')->value() !== 'all') {
            $query->where('course_id', $request->integer('course_id'));
        }

        return $this->streamXlsx('groups', ['Group', 'Course', 'Teacher', 'Academic Year', 'Students', 'Status'], $query->get()->map(fn (Group $group) => [
            $group->name,
            $group->course?->name,
            $group->teacher ? trim($group->teacher->first_name.' '.$group->teacher->last_name) : null,
            $group->academicYear?->name,
            $group->enrollments_count,
            $group->course_finished_at ? 'Finished' : ($group->is_active ? 'Active' : 'Inactive'),
        ])->all());
    }

    public function groupRoster(Request $request, Group $group, AccessScopeService $scopes): StreamedResponse
    {
        abort_unless($request->user()?->can('groups.view'), 403);

        ['group' => $group, 'enrollments' => $enrollments] = $this->groupRosterPayload($request, $group, $scopes);

        $rows = $enrollments
            ->map(fn (Enrollment $enrollment) => [
                $group->name,
                $group->course?->name,
                $enrollment->student?->full_name ?? '',
                $enrollment->student?->student_number,
                $enrollment->student?->user?->phone,
                $enrollment->student?->gradeLevel?->name,
                $enrollment->student?->parentProfile?->parent_number,
                $enrollment->student?->parentProfile?->father_name,
                $enrollment->student?->parentProfile?->mother_name,
                $enrollment->student?->parentProfile?->father_phone,
                $enrollment->student?->parentProfile?->mother_phone,
                $enrollment->student?->parentProfile?->home_phone,
                $enrollment->enrolled_at?->format('d-m-Y'),
                ucfirst($enrollment->status),
            ])
            ->all();

        return $this->streamXlsx('group-roster-'.$group->id, [
            'Group',
            'Course',
            'Student',
            'Student Number',
            'Student Phone',
            'Grade',
            'Parent Number',
            'Father Name',
            'Mother Name',
            'Father Phone',
            'Mother Phone',
            'Home Phone',
            'Enrolled At',
            'Status',
        ], $rows);
    }

    public function groupRosterPdf(Request $request, Group $group, AccessScopeService $scopes): Response
    {
        abort_unless($request->user()?->can('groups.view'), 403);

        ['group' => $group, 'enrollments' => $enrollments] = $this->groupRosterPayload($request, $group, $scopes);

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf(PdfOptions::make([
            'autoLangToFont' => false,
            'autoScriptToLang' => false,
            'format' => 'A4',
            'margin_bottom' => 18,
            'margin_left' => 8,
            'margin_right' => 8,
            'setAutoTopMargin' => 'stretch',
            'autoMarginPadding' => 4,
        ]));
        $mpdf->autoLangToFont = false;
        $mpdf->autoScriptToLang = false;
        $mpdf->useSubstitutions = true;
        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML(view('exports.group-roster-pdf', [
            'enrollments' => $enrollments,
            'group' => $group,
            'logoImage' => app(PdfBrandingService::class)->logoSource(),
        ])->render());

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('exports.pdf.group_roster'),
                $group->name,
                $group->course?->name,
            ], 'group-roster-'.$group->id.'.pdf'),
            'Content-Type' => 'application/pdf',
        ]);
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

        if ($request->filled('course_id') && $request->string('course_id')->value() !== 'all') {
            $query->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', $request->integer('course_id')));
        }

        if ($request->filled('group_id') && $request->string('group_id')->value() !== 'all') {
            $query->where('group_id', $request->integer('group_id'));
        }

        return $this->streamXlsx('enrollments', ['Student', 'Group', 'Course', 'Enrolled At', 'Left At', 'Status'], $query->get()->map(fn (Enrollment $enrollment) => [
            $enrollment->student?->full_name,
            $enrollment->group?->name,
            $enrollment->group?->course?->name,
            $enrollment->enrolled_at?->format('d-m-Y'),
            $enrollment->left_at?->format('d-m-Y'),
            ucfirst($enrollment->status),
        ])->all());
    }

    public function eligibleAwqafStudents(Request $request, AccessScopeService $scopes, QuranProgressionService $progression): StreamedResponse
    {
        abort_unless($request->user()?->can('quran-awqaf-tests.view') || $request->user()?->can('quran-tests.view'), 403);

        $studentIds = Student::query()
            ->whereHas('enrollments', fn ($query) => $query->where('status', 'active'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->pluck('id');

        $passedFinalStudentIds = QuranFinalTest::query()
            ->where('status', 'passed')
            ->pluck('student_id')
            ->merge(
                QuranTest::query()
                    ->where('status', 'passed')
                    ->whereHas('type', fn ($query) => $query->where('code', 'final'))
                    ->pluck('student_id')
            )
            ->unique()
            ->intersect($studentIds)
            ->values();

        $rows = Student::query()
            ->with('parentProfile')
            ->whereIn('id', $passedFinalStudentIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Student $student) use ($progression) {
                $eligibleJuzCount = $progression->eligibleAwqafJuzIdsForStudent($student->id)->count();

                return [
                    'student' => $student->full_name,
                    'father_name' => $student->parentProfile?->father_name,
                    'birth_year' => $student->birth_date?->format('Y'),
                    'eligible_juz_count' => $eligibleJuzCount,
                ];
            })
            ->filter(fn (array $row) => $row['eligible_juz_count'] > 0)
            ->values();

        return $this->streamXlsx('eligible-awqaf-students', [
            'Full Name',
            'Father Name',
            'Year Of Birth',
            'Number Of Ajza',
        ], $rows->map(fn (array $row) => [
            $row['student'],
            $row['father_name'],
            $row['birth_year'],
            $row['eligible_juz_count'],
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

        match ($request->string('profile')->value()) {
            'student' => $query->whereHas('studentProfile'),
            'parent' => $query->whereHas('parentProfile'),
            'teacher' => $query->whereHas('teacherProfile'),
            default => null,
        };

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

    protected function groupRosterPayload(Request $request, Group $group, AccessScopeService $scopes): array
    {
        $group = $scopes->scopeGroups(
            Group::query()->with(['course', 'teacher', 'academicYear']),
            $request->user()
        )->findOrFail($group->id);

        $enrollments = $scopes->scopeEnrollments(
            Enrollment::query()
                ->with(['student.parentProfile', 'student.gradeLevel', 'student.quranCurrentJuz', 'student.user'])
                ->where('group_id', $group->id),
            $request->user()
        )
            ->orderBy('status')
            ->orderBy('enrolled_at')
            ->get();

        return [
            'enrollments' => $enrollments,
            'group' => $group,
        ];
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
