<?php

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\PrintTemplate;
use App\Models\QuranFinalTest;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use App\Services\AccessScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $selectedManagerStudentId = null;

    public function with(): array
    {
        $user = Auth::user();
        $dashboardRole = $this->resolveDashboardRole();

        return match ($dashboardRole) {
            'manager' => $this->managerData($user),
            'teacher' => $this->teacherData($user),
            'parent' => $this->parentData($user),
            'student' => $this->studentData($user),
            default => $this->unassignedData($user),
        };
    }

    protected function resolveDashboardRole(): string
    {
        $user = Auth::user();

        if (! $user) {
            return 'unassigned';
        }

        if ($this->canUseManagerDashboard($user)) {
            return 'manager';
        }

        if ($user->teacherProfile || $user->can('dashboard.teacher.view')) {
            return 'teacher';
        }

        if ($user->parentProfile || $user->can('dashboard.parent.view')) {
            return 'parent';
        }

        if ($user->studentProfile || $user->can('dashboard.student.view')) {
            return 'student';
        }

        return 'unassigned';
    }

    protected function canUseManagerDashboard($user): bool
    {
        return $user->can('dashboard.admin.view')
            || $user->can('dashboard.manager.view')
            || $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    protected function managerData($user): array
    {
        $defaultCourse = Course::query()->where('is_default', true)->where('is_active', true)->first();
        $courseId = $defaultCourse?->id;
        $activeEnrollments = Enrollment::query()
            ->where('status', 'active')
            ->when($courseId, fn ($query) => $query->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', $courseId)), fn ($query) => $query->whereRaw('1 = 0'));
        $groups = Group::query()
            ->where('is_active', true)
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId), fn ($query) => $query->whereRaw('1 = 0'))
            ->withCount(['enrollments as active_students_count' => fn ($query) => $query->where('status', 'active')])
            ->withSum(['enrollments as memorized_pages_total' => fn ($query) => $query->where('status', 'active')], 'memorized_pages_cached')
            ->orderBy('name')
            ->get();

        $groupDistribution = $groups->map(fn (Group $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'students' => (int) $group->active_students_count,
        ]);

        $trendDates = GroupAttendanceDay::query()
            ->when($courseId, fn ($query) => $query->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', $courseId)), fn ($query) => $query->whereRaw('1 = 0'))
            ->select('attendance_date')
            ->distinct()
            ->orderByDesc('attendance_date')
            ->limit(5)
            ->pluck('attendance_date')
            ->map(fn ($date) => Carbon::parse($date))
            ->reverse()
            ->values();
        $trendStart = $trendDates->first()?->toDateString();
        $trendFinish = $trendDates->last()?->toDateString();
        $memorizedByDate = MemorizationSession::query()
            ->join('enrollments', 'enrollments.id', '=', 'memorization_sessions.enrollment_id')
            ->join('groups', 'groups.id', '=', 'enrollments.group_id')
            ->whereNull('enrollments.deleted_at')
            ->whereNull('groups.deleted_at')
            ->where('memorization_sessions.entry_type', 'new')
            ->when($trendStart, fn ($query) => $query->whereDate('memorization_sessions.recorded_on', '>=', $trendStart), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($trendFinish, fn ($query) => $query->whereDate('memorization_sessions.recorded_on', '<=', $trendFinish))
            ->when($courseId, fn ($query) => $query->where('groups.course_id', $courseId), fn ($query) => $query->whereRaw('1 = 0'))
            ->selectRaw('memorization_sessions.recorded_on as activity_date, SUM(memorization_sessions.pages_count) as total_pages')
            ->groupBy('memorization_sessions.recorded_on')
            ->get()
            ->mapWithKeys(fn ($row) => [Carbon::parse($row->activity_date)->toDateString() => (int) $row->total_pages]);
        $attendanceByDate = StudentAttendanceRecord::query()
            ->whereHas('status', fn ($query) => $query->where('is_present', true))
            ->whereHas('attendanceDay', fn ($query) => $query->when($trendStart && $trendFinish, fn ($dayQuery) => $dayQuery->whereBetween('attendance_date', [$trendStart, $trendFinish]), fn ($dayQuery) => $dayQuery->whereRaw('1 = 0'))
                ->when($courseId, fn ($dayQuery) => $dayQuery->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', $courseId)), fn ($dayQuery) => $dayQuery->whereRaw('1 = 0')))
            ->with('attendanceDay:id,attendance_date')
            ->get(['id', 'group_attendance_day_id', 'enrollment_id'])
            ->groupBy(fn (StudentAttendanceRecord $record) => $record->attendanceDay?->attendance_date?->toDateString())
            ->map(fn ($records) => $records->pluck('enrollment_id')->unique()->count());
        $dailyTrend = $trendDates->map(fn (Carbon $date) => [
            'date' => $date->toDateString(),
            'label' => $date->format('d-m'),
            'pages' => (int) ($memorizedByDate[$date->toDateString()] ?? 0),
            'attendance' => (int) ($attendanceByDate[$date->toDateString()] ?? 0),
        ]);

        $groupPageTotals = $groups->map(function (Group $group): array {
            return [
                'id' => $group->id,
                'name' => $group->name,
                'pages' => (int) $group->memorized_pages_total,
            ];
        })->sortByDesc('pages')->take(4)->values();

        $studentTotals = (clone $activeEnrollments)
            ->selectRaw('student_id, SUM(final_points_cached) as points, SUM(memorized_pages_cached) as pages')
            ->groupBy('student_id')
            ->orderByDesc('points')
            ->orderByDesc('pages')
            ->limit(3)
            ->get();
        $students = Student::query()->whereIn('id', $studentTotals->pluck('student_id'))->get()->keyBy('id');
        $leaderboard = $studentTotals->values()->map(fn ($row, int $index) => [
            'rank' => $index + 1,
            'student' => $students->get($row->student_id),
            'points' => (int) $row->points,
            'pages' => (int) $row->pages,
        ])->filter(fn (array $row) => $row['student'])->values();

        $selectedStudent = null;
        if ($courseId && $this->selectedManagerStudentId) {
            $selectedStudentModel = Student::query()
                ->whereKey($this->selectedManagerStudentId)
                ->whereHas('enrollments', fn ($query) => $query->where('status', 'active')->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', $courseId)))
                ->first();

            if ($selectedStudentModel) {
                $selectedEnrollments = Enrollment::query()
                    ->where('student_id', $selectedStudentModel->id)
                    ->where('status', 'active')
                    ->whereHas('group', fn ($query) => $query->where('course_id', $courseId));
                $selectedStudent = [
                    'student' => $selectedStudentModel,
                    'points' => (int) (clone $selectedEnrollments)->sum('final_points_cached'),
                    'pages' => (int) (clone $selectedEnrollments)->sum('memorized_pages_cached'),
                    'final_tests' => QuranFinalTest::query()
                        ->where('student_id', $selectedStudentModel->id)
                        ->whereHas('enrollment.group', fn ($query) => $query->where('course_id', $courseId))
                        ->count(),
                ];
            } else {
                $this->selectedManagerStudentId = null;
            }
        }

        return [
            'dashboardRole' => 'manager',
            'heading' => __('dashboard.manager.heading'),
            'subheading' => __('dashboard.manager.subheading'),
            'intro' => __('dashboard.manager.intro'),
            'profileName' => $user->name,
            'profileJob' => __('dashboard.roles.manager'),
            'currentAcademicYearName' => $defaultCourse?->name ?: __('dashboard.manager.profile_meta_no_course'),
            'profileMeta' => $defaultCourse
                ? __('dashboard.manager.profile_meta_default_course', ['course' => $defaultCourse->name])
                : __('dashboard.manager.profile_meta_no_course'),
            'stats' => [
                ['label' => __('dashboard.manager.stats.enrolled_students.label'), 'value' => (clone $activeEnrollments)->distinct('student_id')->count('student_id'), 'hint' => __('dashboard.manager.stats.enrolled_students.hint')],
                ['label' => __('dashboard.manager.stats.active_groups.label'), 'value' => $groups->count(), 'hint' => __('dashboard.manager.stats.active_groups.hint')],
                ['label' => __('dashboard.manager.stats.total_points.label'), 'value' => (int) (clone $activeEnrollments)->sum('final_points_cached'), 'hint' => __('dashboard.manager.stats.total_points.hint')],
                ['label' => __('dashboard.manager.stats.memorized_pages.label'), 'value' => (int) (clone $activeEnrollments)->sum('memorized_pages_cached'), 'hint' => __('dashboard.manager.stats.memorized_pages.hint')],
            ],
            'cards' => [
                [
                    'title' => __('dashboard.manager.cards.people.title'),
                    'body' => __('dashboard.manager.cards.people.body'),
                    'links' => collect([
                        ['label' => __('ui.nav.students'), 'route' => auth()->user()->can('students.view') ? route('students.index') : null],
                        ['label' => __('ui.nav.groups'), 'route' => auth()->user()->can('groups.view') ? route('groups.index') : null],
                        ['label' => __('ui.nav.enrollments'), 'route' => auth()->user()->can('enrollments.view') ? route('enrollments.index') : null],
                    ])->filter(fn (array $link) => $link['route']),
                ],
                [
                    'title' => __('dashboard.manager.cards.tracking.title'),
                    'body' => __('dashboard.manager.cards.tracking.body'),
                    'links' => collect([
                        ['label' => __('ui.nav.reports'), 'route' => auth()->user()->can('reports.view') ? route('reports.index') : null],
                        ['label' => __('ui.nav.assessments'), 'route' => auth()->user()->can('assessments.view') ? route('assessments.index') : null],
                        ['label' => __('ui.nav.invoices'), 'route' => auth()->user()->can('invoices.view') ? route('invoices.index') : null],
                    ])->filter(fn (array $link) => $link['route']),
                ],
            ],
            'recordsHeading' => __('dashboard.manager.records.heading'),
            'recordsEmpty' => __('dashboard.manager.records.empty'),
            'records' => collect(),
            'defaultCourse' => $defaultCourse,
            'groupDistribution' => $groupDistribution,
            'dailyTrend' => $dailyTrend,
            'leaderboard' => $leaderboard,
            'groupPageTotals' => $groupPageTotals,
            'selectedManagerStudent' => $selectedStudent,
        ];
    }

    public function showManagerStudent(int $studentId): void
    {
        abort_unless($this->canUseManagerDashboard(Auth::user()), 403);
        $defaultCourseId = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        abort_unless($defaultCourseId && Student::query()->whereKey($studentId)->whereHas('enrollments', fn ($query) => $query->where('status', 'active')->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', $defaultCourseId)))->exists(), 404);

        $this->selectedManagerStudentId = $studentId;
    }

    public function closeManagerStudent(): void
    {
        $this->selectedManagerStudentId = null;
    }

    protected function teacherData($user): array
    {
        $teacher = $user->teacherProfile?->load('accessRole');

        if (! $teacher) {
            return $this->missingProfileData(
                'teacher',
                __('dashboard.missing_profile.teacher.heading'),
                __('dashboard.missing_profile.teacher.message'),
            );
        }

        $groupsQuery = app(AccessScopeService::class)->scopeGroups(Group::query(), $user);

        $groups = (clone $groupsQuery)
            ->with(['course', 'academicYear'])
            ->withCount(['enrollments' => fn ($query) => $query->where('status', 'active')])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->take(8)
            ->get();

        $groupIds = (clone $groupsQuery)->pluck('id');
        $allAssignedGroups = (clone $groupsQuery)->count();
        $activeAssignedGroups = (clone $groupsQuery)->where('is_active', true)->count();
        $currentYearGroupCount = (clone $groupsQuery)
            ->whereHas('academicYear', fn ($query) => $query->where('is_current', true))
            ->count();

        $accessRoleName = $teacher->accessRole?->name;
        $accessRoleLabel = $accessRoleName
            ? ((__('ui.roles.'.$accessRoleName) === 'ui.roles.'.$accessRoleName)
                ? \Illuminate\Support\Str::of($accessRoleName)->replace('_', ' ')->headline()->toString()
                : __('ui.roles.'.$accessRoleName))
            : __('dashboard.roles.teacher');

        return [
            'dashboardRole' => 'teacher',
            'heading' => __('dashboard.teacher.heading'),
            'subheading' => __('dashboard.teacher.subheading'),
            'intro' => __('dashboard.teacher.intro'),
            'profileName' => $teacher->first_name.' '.$teacher->last_name,
            'profileJob' => $accessRoleLabel,
            'currentAcademicYearName' => AcademicYear::query()->where('is_current', true)->value('name') ?: __('dashboard.manager.profile_meta_no_year'),
            'profileMeta' => $accessRoleLabel,
            'stats' => [
                ['label' => __('dashboard.teacher.stats.assigned_groups.label'), 'value' => $allAssignedGroups, 'hint' => __('dashboard.teacher.stats.assigned_groups.hint')],
                ['label' => __('dashboard.teacher.stats.active_groups.label'), 'value' => $activeAssignedGroups, 'hint' => __('dashboard.teacher.stats.active_groups.hint')],
                ['label' => __('dashboard.teacher.stats.active_students.label'), 'value' => $groupIds->isEmpty() ? 0 : Enrollment::whereIn('group_id', $groupIds)->where('status', 'active')->count(), 'hint' => __('dashboard.teacher.stats.active_students.hint')],
                ['label' => __('dashboard.teacher.stats.current_year_groups.label'), 'value' => $currentYearGroupCount, 'hint' => __('dashboard.teacher.stats.current_year_groups.hint')],
            ],
            'cards' => [
                [
                    'title' => __('dashboard.teacher.cards.workflow.title'),
                    'body' => __('dashboard.teacher.cards.workflow.body'),
                    'links' => collect([
                        ['label' => __('ui.nav.groups'), 'route' => auth()->user()->can('groups.view') ? route('groups.index') : null],
                        ['label' => __('ui.nav.enrollments'), 'route' => auth()->user()->can('enrollments.view') ? route('enrollments.index') : null],
                        ['label' => __('ui.nav.teacher_attendance'), 'route' => auth()->user()->can('attendance.teacher.view') ? route('teachers.attendance') : null],
                        ['label' => __('ui.nav.assessments'), 'route' => auth()->user()->can('assessments.view') ? route('assessments.index') : null],
                        ['label' => __('ui.nav.student_notes'), 'route' => auth()->user()->can('student-notes.view') ? route('student-notes.index') : null],
                    ])->filter(fn (array $link) => $link['route']),
                ],
            ],
            'recordsHeading' => __('dashboard.teacher.records.heading'),
            'recordsEmpty' => __('dashboard.teacher.records.empty'),
            'records' => $groups->map(fn (Group $group) => [
                'title' => $group->name,
                'subtitle' => trim(($group->course?->name ?: __('dashboard.common.no_course')).' | '.($group->academicYear?->name ?: __('dashboard.common.no_year'))),
                'meta' => __('dashboard.common.active_students', ['count' => $group->enrollments_count]),
            ]),
        ];
    }

    protected function parentData($user): array
    {
        $parent = $user->parentProfile?->load(['students.gradeLevel']);

        if (! $parent) {
            return $this->missingProfileData(
                'parent',
                __('dashboard.missing_profile.parent.heading'),
                __('dashboard.missing_profile.parent.message'),
            );
        }

        $students = app(AccessScopeService::class)
            ->scopeStudents(Student::query()->with(['gradeLevel']), $user)
            ->orderBy('first_name')
            ->withCount(['enrollments' => fn ($query) => $query->where('status', 'active')])
            ->get();

        $studentIds = $students->pluck('id');
        $activeEnrollmentsQuery = $studentIds->isEmpty()
            ? Enrollment::query()->whereRaw('1 = 0')
            : app(AccessScopeService::class)
                ->scopeEnrollments(Enrollment::query(), $user)
                ->whereIn('student_id', $studentIds)
                ->where('status', 'active');
        $activeEnrollmentCount = $studentIds->isEmpty() ? 0 : (clone $activeEnrollmentsQuery)->count();
        $activeEnrollmentPoints = $studentIds->isEmpty() ? 0 : (int) (clone $activeEnrollmentsQuery)->sum('final_points_cached');
        $activeEnrollmentPages = $studentIds->isEmpty() ? 0 : (int) (clone $activeEnrollmentsQuery)->sum('memorized_pages_cached');

        return [
            'dashboardRole' => 'parent',
            'heading' => __('dashboard.parent.heading'),
            'subheading' => __('dashboard.parent.subheading'),
            'intro' => __('dashboard.parent.intro'),
            'profileName' => $parent->father_name,
            'profileJob' => __('dashboard.roles.parent'),
            'currentAcademicYearName' => AcademicYear::query()->where('is_current', true)->value('name') ?: __('dashboard.manager.profile_meta_no_year'),
            'profileMeta' => $parent->father_phone ?: ($parent->mother_phone ?: __('dashboard.parent.profile_meta_no_phone')),
            'stats' => [
                ['label' => __('dashboard.parent.stats.students.label'), 'value' => $students->count(), 'hint' => __('dashboard.parent.stats.students.hint')],
                ['label' => __('dashboard.parent.stats.active_enrollments.label'), 'value' => $activeEnrollmentCount, 'hint' => __('dashboard.parent.stats.active_enrollments.hint')],
                ['label' => __('dashboard.parent.stats.cached_points.label'), 'value' => $activeEnrollmentPoints, 'hint' => __('dashboard.parent.stats.cached_points.hint')],
                ['label' => __('dashboard.parent.stats.memorized_pages.label'), 'value' => $activeEnrollmentPages, 'hint' => __('dashboard.parent.stats.memorized_pages.hint')],
            ],
            'cards' => [
                [
                    'title' => __('dashboard.parent.cards.family.title'),
                    'body' => __('dashboard.parent.cards.family.body'),
                    'links' => collect([
                        ['label' => __('crud.common.actions.progress'), 'route' => auth()->user()->can('students.view') && $students->count() === 1 ? route('students.progress', $students->first()) : null],
                        ['label' => __('ui.nav.students'), 'route' => auth()->user()->can('students.view') ? route('students.index') : null],
                        ['label' => __('ui.nav.enrollments'), 'route' => auth()->user()->can('enrollments.view') ? route('enrollments.index') : null],
                        ['label' => __('ui.nav.family_activities'), 'route' => auth()->user()->can('activities.responses.view') ? route('activities.family') : null],
                        ['label' => __('ui.nav.invoices'), 'route' => auth()->user()->can('invoices.view') ? route('invoices.index') : null],
                    ])->filter(fn (array $link) => $link['route']),
                ],
            ],
            'recordsHeading' => __('dashboard.parent.records.heading'),
            'recordsEmpty' => __('dashboard.parent.records.empty'),
            'records' => $students->map(fn (Student $student) => [
                'title' => $student->first_name.' '.$student->last_name,
                'subtitle' => trim(($student->gradeLevel?->name ?: __('dashboard.common.no_grade')).' | '.($student->school_name ?: __('dashboard.common.no_school'))),
                'meta' => __('dashboard.common.active_enrollments', ['count' => $student->enrollments_count]),
            ]),
        ];
    }

    protected function studentData($user): array
    {
        $student = $user->studentProfile?->load(['gradeLevel', 'parentProfile', 'quranCurrentJuz']);

        if (! $student) {
            return $this->missingProfileData(
                'student',
                __('dashboard.missing_profile.student.heading'),
                __('dashboard.missing_profile.student.message'),
            );
        }

        $enrollmentsQuery = app(AccessScopeService::class)
            ->scopeEnrollments(Enrollment::query(), $user)
            ->where('student_id', $student->id);

        $enrollments = (clone $enrollmentsQuery)
            ->with(['group.course', 'group.teacher'])
            ->orderByDesc('enrolled_at')
            ->take(8)
            ->get();

        $allEnrollments = (clone $enrollmentsQuery)->get();
        $activeEnrollments = $allEnrollments->where('status', 'active')->values();
        $studentCardPreviews = $this->studentDashboardCardPreviews($student, $user);

        return [
            'dashboardRole' => 'student',
            'heading' => __('dashboard.student.heading'),
            'subheading' => __('dashboard.student.subheading'),
            'intro' => __('dashboard.student.intro'),
            'profileName' => $student->first_name.' '.$student->last_name,
            'profileJob' => $student->gradeLevel?->name ?: __('dashboard.roles.student'),
            'currentAcademicYearName' => AcademicYear::query()->where('is_current', true)->value('name') ?: __('dashboard.manager.profile_meta_no_year'),
            'profileMeta' => $student->gradeLevel?->name ?: ($student->school_name ?: __('dashboard.student.profile_meta_no_grade')),
            'stats' => [
                ['label' => __('dashboard.student.stats.enrollments.label'), 'value' => $allEnrollments->count(), 'hint' => __('dashboard.student.stats.enrollments.hint')],
                ['label' => __('dashboard.student.stats.active_enrollments.label'), 'value' => $activeEnrollments->count(), 'hint' => __('dashboard.student.stats.active_enrollments.hint')],
                ['label' => __('dashboard.student.stats.cached_points.label'), 'value' => (int) $activeEnrollments->sum('final_points_cached'), 'hint' => __('dashboard.student.stats.cached_points.hint')],
                ['label' => __('dashboard.student.stats.memorized_pages.label'), 'value' => (int) $activeEnrollments->sum('memorized_pages_cached'), 'hint' => __('dashboard.student.stats.memorized_pages.hint')],
                ['label' => __('dashboard.student.stats.current_juz.label'), 'value' => $student->quranCurrentJuz?->juz_number ?: '-', 'hint' => __('dashboard.student.stats.current_juz.hint')],
            ],
            'cards' => [
                [
                    'title' => __('dashboard.student.cards.student.title'),
                    'body' => __('dashboard.student.cards.student.body'),
                    'links' => collect([
                        ['label' => __('crud.common.actions.progress'), 'route' => auth()->user()->can('students.view') ? route('students.progress', $student) : null],
                        ['label' => __('ui.nav.students'), 'route' => auth()->user()->can('students.view') ? route('students.index') : null],
                        ['label' => __('ui.nav.enrollments'), 'route' => auth()->user()->can('enrollments.view') ? route('enrollments.index') : null],
                    ])->filter(fn (array $link) => $link['route']),
                ],
            ],
            'recordsHeading' => __('dashboard.student.records.heading'),
            'recordsEmpty' => __('dashboard.student.records.empty'),
            'records' => $enrollments->map(fn (Enrollment $enrollment) => [
                'title' => $enrollment->group?->name ?: __('dashboard.common.no_group'),
                'subtitle' => trim(($enrollment->group?->course?->name ?: __('dashboard.common.no_course')).' | '.$enrollment->status),
                'meta' => __('dashboard.common.points_pages', ['points' => $enrollment->final_points_cached, 'pages' => $enrollment->memorized_pages_cached]),
            ]),
            'studentCardPreviews' => $studentCardPreviews,
        ];
    }

    protected function studentDashboardCardPreviews(Student $student, $user)
    {
        $templateMap = AppSetting::groupValues('general')->get('student_dashboard_card_templates');

        if (! is_array($templateMap) || $templateMap === []) {
            return collect();
        }

        $activeEnrollments = app(AccessScopeService::class)
            ->scopeEnrollments(Enrollment::query(), $user)
            ->with(['group.course', 'group.academicYear', 'group.teacher'])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get()
            ->unique('group_id')
            ->values();

        if ($activeEnrollments->isEmpty()) {
            return collect();
        }

        $templateIds = collect($templateMap)
            ->only($activeEnrollments->pluck('group_id')->map(fn ($id) => (string) $id)->all())
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($templateIds->isEmpty()) {
            return collect();
        }

        $templates = PrintTemplate::query()
            ->where('is_active', true)
            ->whereIn('id', $templateIds)
            ->get()
            ->keyBy('id');

        if ($templates->isEmpty()) {
            return collect();
        }

        $student->loadMissing(['user', 'parentProfile', 'gradeLevel', 'enrollments.group']);
        $renderService = app(PrintTemplateRenderService::class);

        return $activeEnrollments
            ->map(function (Enrollment $enrollment) use ($templateMap, $templates, $student, $renderService) {
                $templateId = (int) ($templateMap[(string) $enrollment->group_id] ?? 0);
                /** @var PrintTemplate|null $template */
                $template = $templates->get($templateId);

                if (! $template) {
                    return null;
                }

                $contextStudent = clone $student;
                $contextStudent->setRelation('enrollments', collect([$enrollment]));
                $renderContext = array_filter([
                    'student' => $contextStudent,
                    'parent' => $contextStudent->parentProfile,
                    'teacher' => $enrollment->group?->teacher,
                    'user' => $contextStudent->user,
                ]);

                return [
                    'group' => $enrollment->group,
                    'template' => $template,
                    'rendered' => $renderService->render($template, $renderContext),
                ];
            })
            ->filter()
            ->values();
    }

    protected function unassignedData($user): array
    {
        return [
            'dashboardRole' => 'unassigned',
            'heading' => __('dashboard.unassigned.heading'),
            'subheading' => __('dashboard.unassigned.subheading'),
            'intro' => __('dashboard.unassigned.intro'),
            'profileName' => $user->name,
            'profileJob' => __('dashboard.roles.unassigned'),
            'currentAcademicYearName' => AcademicYear::query()->where('is_current', true)->value('name') ?: __('dashboard.manager.profile_meta_no_year'),
            'profileMeta' => $user->email ?: ($user->username ?: __('dashboard.common.no_identifier')),
            'stats' => [],
            'cards' => [
                [
                    'title' => __('dashboard.unassigned.cards.next.title'),
                    'body' => __('dashboard.unassigned.cards.next.body'),
                    'links' => collect(),
                ],
            ],
            'recordsHeading' => __('dashboard.unassigned.records.heading'),
            'recordsEmpty' => __('dashboard.unassigned.records.empty'),
            'records' => collect(),
        ];
    }

    protected function missingProfileData(string $role, string $heading, string $message): array
    {
        return [
            'dashboardRole' => $role,
            'heading' => $heading,
            'subheading' => __('dashboard.missing_profile.subheading'),
            'intro' => $message,
            'profileName' => Auth::user()->name,
            'profileJob' => __('dashboard.roles.'.$role),
            'currentAcademicYearName' => AcademicYear::query()->where('is_current', true)->value('name') ?: __('dashboard.manager.profile_meta_no_year'),
            'profileMeta' => Auth::user()->email ?: (Auth::user()->username ?: __('dashboard.common.no_identifier')),
            'stats' => [],
            'cards' => [
                [
                    'title' => __('dashboard.missing_profile.card_title'),
                    'body' => __('dashboard.missing_profile.card_body'),
                    'links' => collect(),
                ],
            ],
            'recordsHeading' => __('dashboard.missing_profile.records_heading'),
            'recordsEmpty' => __('dashboard.missing_profile.records_empty'),
            'records' => collect(),
        ];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="dashboard-split grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_22rem] xl:items-start">
            <div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ $heading }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ $currentAcademicYearName }}</p>
                @if ($dashboardRole === 'unassigned')
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-neutral-300">{{ $intro }}</p>
                @endif

            </div>

            <aside class="surface-panel surface-panel--soft p-4 lg:p-5">
                <div class="flex items-center gap-4">
                    <div class="dashboard-profile-photo flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-3xl border border-white/10 bg-white/5">
                        <x-user-avatar :user="auth()->user()" size="lg" />
                    </div>
                    <div class="min-w-0">
                        <div class="mt-2 truncate text-xl font-semibold text-white">{{ $profileName }}</div>
                        <p class="mt-1 truncate text-sm leading-6 text-neutral-300">{{ $profileJob }}</p>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    @if (! empty($stats))
        <div class="{{ $dashboardRole === 'manager' ? 'dashboard-manager-highlights gap-3' : 'gap-4' }} grid md:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                <article class="stat-card">
                    <div class="flex items-start justify-between gap-4">
                        <div class="kpi-label">{{ $stat['label'] }}</div>
                        <span class="badge-soft {{ $loop->even ? 'badge-soft--emerald' : '' }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <div class="metric-value mt-3">{{ is_numeric($stat['value']) ? number_format($stat['value']) : $stat['value'] }}</div>
                </article>
            @endforeach
        </div>
    @endif

    <div>
        @if ($dashboardRole === 'manager')
            @php
                $pieTotal = (int) $groupDistribution->sum('students');
                $pieOffset = 0.0;
                $chartColor = fn (int $index) => sprintf('hsl(%.1f 72%% 58%%)', fmod(145 + ($index * 137.508), 360));
                $trendMax = max(1, (int) $dailyTrend->max(fn (array $day) => max($day['pages'], $day['attendance'])));
                $trendX = fn (int $index) => 58 + ($index * (332 / max($dailyTrend->count() - 1, 1)));
                $trendY = fn (int $value) => 178 - (($value / $trendMax) * 128);
                $pagesLine = $dailyTrend->values()->map(fn (array $day, int $index) => $trendX($index).','.$trendY($day['pages']))->implode(' ');
                $attendanceLine = $dailyTrend->values()->map(fn (array $day, int $index) => $trendX($index).','.$trendY($day['attendance']))->implode(' ');
                $podiumOrder = collect([3, 1, 2])->map(fn (int $rank) => $leaderboard->firstWhere('rank', $rank))->filter();
                $barMax = max(1, (int) $groupPageTotals->max('pages'));
            @endphp

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="surface-panel p-5 lg:p-6">
                    <div class="eyebrow">{{ __('dashboard.manager.analytics.groups_eyebrow') }}</div>
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.group_distribution') }}</h2>
                    @if ($pieTotal > 0)
                        <div class="mt-6 grid items-center gap-6 lg:grid-cols-[18rem_1fr]">
                            <svg viewBox="0 0 42 42" class="mx-auto h-64 w-64 -rotate-90 overflow-visible lg:h-72 lg:w-72" role="img" aria-label="{{ __('dashboard.manager.analytics.group_distribution') }}">
                                @foreach ($groupDistribution as $index => $group)
                                    @php($portion = ($group['students'] / $pieTotal) * 100)
                                    @if ($portion > 0)
                                        <circle cx="21" cy="21" r="15.9155" fill="transparent" stroke="{{ $chartColor($index) }}" stroke-width="8" stroke-dasharray="{{ $portion }} {{ 100 - $portion }}" stroke-dashoffset="{{ -$pieOffset }}" class="dashboard-chart-segment origin-center transition-all duration-200 hover:scale-105 hover:stroke-[10]">
                                            <title>{{ $group['name'] }} · {{ trans_choice('dashboard.manager.analytics.students_count', $group['students'], ['count' => number_format($group['students'])]) }} · {{ number_format($portion, 1) }}%</title>
                                        </circle>
                                    @endif
                                    @php($pieOffset += $portion)
                                @endforeach
                            </svg>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                                @foreach ($groupDistribution as $index => $group)
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="flex min-w-0 items-center gap-2"><i class="h-3 w-3 shrink-0 rounded-full" style="background: {{ $chartColor($index) }}"></i><span class="truncate">{{ $group['name'] }}</span></span>
                                        <strong class="text-white">{{ number_format($group['students']) }}</strong>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="admin-empty-state mt-5">{{ __('dashboard.manager.analytics.no_group_students') }}</div>
                    @endif
                </article>

                <article class="surface-panel p-5 lg:p-6">
                    <div class="eyebrow">{{ __('dashboard.manager.analytics.last_five_attendance_days') }}</div>
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.daily_activity') }}</h2>
                    <div class="mt-4 flex flex-wrap gap-4 text-xs text-neutral-300">
                        <span class="flex items-center gap-2"><i class="h-2.5 w-6 rounded-full bg-emerald-400"></i>{{ __('dashboard.manager.analytics.memorized_pages') }}</span>
                        <span class="flex items-center gap-2"><i class="h-2.5 w-6 rounded-full bg-sky-400"></i>{{ __('dashboard.manager.analytics.students_attended') }}</span>
                    </div>
                    <svg viewBox="0 0 440 220" class="mt-3 h-80 w-full overflow-visible lg:h-96" role="img" aria-label="{{ __('dashboard.manager.analytics.daily_activity') }}">
                        <line x1="58" y1="42" x2="58" y2="178" stroke="rgba(255,255,255,.3)" stroke-width="1.5" />
                        <line x1="58" y1="178" x2="400" y2="178" stroke="rgba(255,255,255,.3)" stroke-width="1.5" />
                        @foreach ([0, .25, .5, .75, 1] as $ratio)
                            @php($gridY = 178 - ($ratio * 128))
                            <line x1="58" y1="{{ $gridY }}" x2="400" y2="{{ $gridY }}" stroke="rgba(255,255,255,.09)" stroke-width="1" />
                            <text x="49" y="{{ $gridY + 4 }}" text-anchor="end" fill="#a3a3a3" font-size="10">{{ number_format($trendMax * $ratio) }}</text>
                        @endforeach
                        <polyline points="{{ $pagesLine }}" fill="none" stroke="#34d399" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="{{ $attendanceLine }}" fill="none" stroke="#38bdf8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        @foreach ($dailyTrend as $index => $day)
                            <g class="dashboard-line-point" tabindex="0">
                                <circle cx="{{ $trendX($index) }}" cy="{{ $trendY($day['pages']) }}" r="6" fill="#34d399" class="dashboard-chart-point origin-center" />
                                <g class="dashboard-line-point__tooltip" transform="translate({{ $trendX($index) }}, {{ max(18, $trendY($day['pages']) - 13) }})">
                                    <rect x="-68" y="-25" width="136" height="25" rx="6" fill="rgba(10,10,10,.96)" stroke="rgba(255,255,255,.18)" />
                                    <text x="0" y="-9" text-anchor="middle" fill="white" font-size="9">{{ $day['label'] }} · {{ __('dashboard.manager.analytics.memorized_pages') }}: {{ number_format($day['pages']) }}</text>
                                </g>
                            </g>
                            <g class="dashboard-line-point" tabindex="0">
                                <circle cx="{{ $trendX($index) }}" cy="{{ $trendY($day['attendance']) }}" r="6" fill="#38bdf8" class="dashboard-chart-point origin-center" />
                                <g class="dashboard-line-point__tooltip" transform="translate({{ $trendX($index) }}, {{ min(210, $trendY($day['attendance']) + 38) }})">
                                    <rect x="-68" y="-25" width="136" height="25" rx="6" fill="rgba(10,10,10,.96)" stroke="rgba(255,255,255,.18)" />
                                    <text x="0" y="-9" text-anchor="middle" fill="white" font-size="9">{{ $day['label'] }} · {{ __('dashboard.manager.analytics.students_attended') }}: {{ number_format($day['attendance']) }}</text>
                                </g>
                            </g>
                            <text x="{{ $trendX($index) }}" y="202" text-anchor="middle" fill="#a3a3a3" font-size="11">{{ $day['label'] }}</text>
                        @endforeach
                        <text x="20" y="110" text-anchor="middle" fill="#a3a3a3" font-size="10" transform="rotate(-90 20 110)">{{ __('dashboard.manager.analytics.count_axis') }}</text>
                    </svg>
                </article>
            </section>

            <section class="mt-6 grid gap-6 xl:grid-cols-2">
                <article class="surface-panel p-5 lg:p-6">
                    <div class="eyebrow">{{ __('dashboard.manager.analytics.leaderboard_eyebrow') }}</div>
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.top_students') }}</h2>
                    @if ($leaderboard->isEmpty())
                        <div class="admin-empty-state mt-5">{{ __('dashboard.manager.analytics.no_ranked_students') }}</div>
                    @else
                        <div class="dashboard-leaderboard mt-8 grid grid-cols-3 items-end gap-3">
                            @foreach ($podiumOrder as $entry)
                                @php($rankStyle = [1 => 'gold', 2 => 'silver', 3 => 'bronze'][$entry['rank']])
                                <button type="button" wire:click="showManagerStudent({{ $entry['student']->id }})" class="dashboard-leaderboard__card dashboard-leaderboard__card--{{ $rankStyle }} dashboard-leaderboard__card--rank-{{ $entry['rank'] }} group">
                                    <span class="dashboard-leaderboard__rank">{{ $entry['rank'] }}</span>
                                    <x-student-avatar :student="$entry['student']" size="lg" class="mx-auto transition-transform duration-200 group-hover:scale-110" />
                                    <span class="mt-3 block line-clamp-2 font-semibold text-white">{{ $entry['student']->full_name }}</span>
                                    <span class="mt-2 block text-sm text-neutral-200">{{ number_format($entry['points']) }} {{ __('dashboard.manager.analytics.points') }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </article>

                <article class="surface-panel p-5 lg:p-6">
                    <div class="eyebrow">{{ __('dashboard.manager.analytics.groups_eyebrow') }}</div>
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.top_groups_by_memorization') }}</h2>
                    @if ($groupPageTotals->isEmpty())
                        <div class="admin-empty-state mt-5">{{ __('dashboard.manager.analytics.no_groups') }}</div>
                    @else
                        <div class="mt-8 grid grid-cols-[3rem_minmax(0,1fr)] gap-2">
                            <div class="flex h-64 flex-col justify-between border-r border-white/20 pr-2 text-right text-[10px] text-neutral-400"><span>{{ number_format($barMax) }}</span><span>{{ number_format($barMax * .75) }}</span><span>{{ number_format($barMax * .5) }}</span><span>{{ number_format($barMax * .25) }}</span><span>0</span></div>
                        <div>
                        <div class="dashboard-bar-chart grid h-64 grid-cols-4 items-end gap-4 border-b border-white/20 px-3">
                            @foreach ($groupPageTotals as $index => $group)
                                @php($barHeight = max(3, ($group['pages'] / $barMax) * 100))
                                <div class="flex h-full min-w-0 flex-col justify-end text-center">
                                    <div class="mb-2 text-sm font-semibold text-white">{{ number_format($group['pages']) }}</div>
                                    <div class="dashboard-bar-chart__bar mx-auto w-full max-w-16 rounded-t-xl transition-transform duration-200 hover:scale-x-110" style="height: {{ $barHeight }}%; background: {{ $chartColor($index) }}">
                                        <span class="sr-only">{{ $group['name'] }}: {{ trans_choice('dashboard.manager.analytics.pages_count', $group['pages'], ['count' => number_format($group['pages'])]) }}</span>
                                        <span class="dashboard-chart-tooltip">{{ $group['name'] }} · {{ trans_choice('dashboard.manager.analytics.pages_count', $group['pages'], ['count' => number_format($group['pages'])]) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="grid grid-cols-4 gap-4 px-3 pt-3">
                            @foreach ($groupPageTotals as $group)
                                <div class="truncate text-center text-xs text-neutral-300" title="{{ $group['name'] }}">{{ $group['name'] }}</div>
                            @endforeach
                        </div>
                        </div>
                        <div></div><div class="pt-2 text-center text-xs text-neutral-400">{{ __('dashboard.manager.analytics.groups_axis') }}</div>
                        </div>
                    @endif
                </article>
            </section>

            <x-admin.modal :show="$selectedManagerStudentId !== null" :title="__('dashboard.manager.analytics.student_highlights')" close-method="closeManagerStudent" max-width="3xl">
                @if ($selectedManagerStudent)
                    <div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:text-start">
                        <x-student-avatar :student="$selectedManagerStudent['student']" size="lg" />
                        <div><h3 class="text-2xl font-semibold text-white">{{ $selectedManagerStudent['student']->full_name }}</h3><p class="mt-1 text-sm text-neutral-400">{{ $defaultCourse?->name }}</p></div>
                    </div>
                    <div class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="stat-card"><div class="kpi-label">{{ __('dashboard.manager.analytics.points') }}</div><div class="metric-value mt-4">{{ number_format($selectedManagerStudent['points']) }}</div></div>
                        <div class="stat-card"><div class="kpi-label">{{ __('dashboard.manager.analytics.memorized_pages') }}</div><div class="metric-value mt-4">{{ number_format($selectedManagerStudent['pages']) }}</div></div>
                        <div class="stat-card"><div class="kpi-label">{{ __('dashboard.manager.analytics.final_tests') }}</div><div class="metric-value mt-4">{{ number_format($selectedManagerStudent['final_tests']) }}</div></div>
                    </div>
                @endif
            </x-admin.modal>
        @endif

        @if ($dashboardRole === 'student')
            <section class="surface-panel mb-6 p-5 lg:p-6">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('dashboard.student.card_preview.title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('dashboard.student.card_preview.subtitle') }}</div>
                    </div>
                </div>

                @if ($studentCardPreviews->isEmpty())
                    <div class="admin-empty-state">{{ __('dashboard.student.card_preview.empty') }}</div>
                @else
                    <div class="mt-5 grid gap-5 xl:grid-cols-2">
                        @foreach ($studentCardPreviews as $cardPreview)
                            <article class="dashboard-print-card">
                                <div class="dashboard-print-card__meta">
                                    <div>
                                        <div class="text-base font-semibold text-white">{{ $cardPreview['group']?->name ?: __('dashboard.common.no_group') }}</div>
                                        <div class="mt-1 text-sm text-neutral-300">
                                            {{ $cardPreview['group']?->course?->name ?: __('dashboard.common.no_course') }}
                                            @if ($cardPreview['group']?->academicYear?->name)
                                                <span class="text-neutral-500">|</span> {{ $cardPreview['group']->academicYear->name }}
                                            @endif
                                        </div>
                                    </div>
                                    <span class="badge-soft badge-soft--emerald">{{ $cardPreview['template']->name }}</span>
                                </div>

                                <div class="dashboard-print-card__canvas">
                                    @include('print-templates.partials.item', ['item' => $cardPreview['rendered']])
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @if ($dashboardRole !== 'manager')
        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="eyebrow">{{ __('dashboard.hero.live_snapshot') }}</div>
                        <h2 class="font-display mt-3 text-2xl text-white">{{ $recordsHeading }}</h2>
                    </div>
                    <span class="badge-soft">{{ trans_choice('dashboard.hero.items', $records->count(), ['count' => number_format($records->count())]) }}</span>
                </div>
            </div>

            @if ($records->isEmpty())
                <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ $recordsEmpty }}</div>
            @else
                <div class="divide-y divide-white/6">
                    @foreach ($records as $record)
                        <div class="grid gap-4 px-5 py-5 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-center lg:px-6">
                            <div class="list-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>

                            <div>
                                <div class="text-base font-semibold text-white">{{ $record['title'] }}</div>
                                <div class="mt-1 text-sm text-neutral-400">{{ $record['subtitle'] }}</div>
                                <div class="mt-3 text-sm leading-6 text-neutral-300">{{ $record['meta'] }}</div>
                            </div>

                            <div class="text-xs uppercase tracking-[0.24em] text-neutral-500 lg:text-right">
                                {{ $loop->first ? __('dashboard.record_states.most_recent') : __('dashboard.record_states.in_scope') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
        @endif
    </div>
</div>
