<?php

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\PointTransaction;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
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
        $currentAcademicYear = AcademicYear::query()
            ->where('is_current', true)
            ->first();
        $currentYearMemorizedPages = $currentAcademicYear
            ? StudentPageAchievement::query()
                ->whereHas('enrollment.group', fn ($query) => $query->where('academic_year_id', $currentAcademicYear->id))
                ->count()
            : 0;

        $recentGroups = Group::query()
            ->with(['course', 'academicYear', 'teacher'])
            ->withCount(['enrollments' => fn ($query) => $query->where('status', 'active')])
            ->latest()
            ->take(5)
            ->get();

        return [
            'dashboardRole' => 'manager',
            'heading' => __('dashboard.manager.heading'),
            'subheading' => __('dashboard.manager.subheading'),
            'intro' => __('dashboard.manager.intro'),
            'profileName' => $user->name,
            'profileJob' => __('dashboard.roles.manager'),
            'currentAcademicYearName' => $currentAcademicYear?->name ?: __('dashboard.manager.profile_meta_no_year'),
            'profileMeta' => $currentAcademicYear?->name
                ? __('dashboard.manager.profile_meta_current_year', ['year' => $currentAcademicYear->name])
                : __('dashboard.manager.profile_meta_no_year'),
            'stats' => [
                ['label' => __('dashboard.manager.stats.enrolled_students.label'), 'value' => Enrollment::where('status', 'active')->distinct('student_id')->count('student_id'), 'hint' => __('dashboard.manager.stats.enrolled_students.hint')],
                ['label' => __('dashboard.manager.stats.active_groups.label'), 'value' => Group::where('is_active', true)->count(), 'hint' => __('dashboard.manager.stats.active_groups.hint')],
                ['label' => __('dashboard.manager.stats.total_points.label'), 'value' => (int) PointTransaction::whereNull('voided_at')->sum('points'), 'hint' => __('dashboard.manager.stats.total_points.hint')],
                ['label' => __('dashboard.manager.stats.current_year_memorized_pages.label'), 'value' => $currentYearMemorizedPages, 'hint' => __('dashboard.manager.stats.current_year_memorized_pages.hint')],
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
            'records' => $recentGroups->map(fn (Group $group) => [
                'title' => $group->name,
                'subtitle' => trim(($group->course?->name ?: __('dashboard.common.no_course')).' | '.($group->academicYear?->name ?: __('dashboard.common.no_year'))),
                'meta' => trim(($group->teacher ? $group->teacher->first_name.' '.$group->teacher->last_name : __('dashboard.common.no_teacher')).' | '.__('dashboard.common.active_enrollments', ['count' => $group->enrollments_count])),
            ]),
        ];
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
                ['label' => __('dashboard.parent.stats.active_enrollments.label'), 'value' => $studentIds->isEmpty() ? 0 : Enrollment::whereIn('student_id', $studentIds)->where('status', 'active')->count(), 'hint' => __('dashboard.parent.stats.active_enrollments.hint')],
                ['label' => __('dashboard.parent.stats.cached_points.label'), 'value' => $studentIds->isEmpty() ? 0 : (int) Enrollment::whereIn('student_id', $studentIds)->sum('final_points_cached'), 'hint' => __('dashboard.parent.stats.cached_points.hint')],
                ['label' => __('dashboard.parent.stats.memorized_pages.label'), 'value' => $studentIds->isEmpty() ? 0 : (int) Enrollment::whereIn('student_id', $studentIds)->sum('memorized_pages_cached'), 'hint' => __('dashboard.parent.stats.memorized_pages.hint')],
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
                ['label' => __('dashboard.student.stats.active_enrollments.label'), 'value' => $allEnrollments->where('status', 'active')->count(), 'hint' => __('dashboard.student.stats.active_enrollments.hint')],
                ['label' => __('dashboard.student.stats.cached_points.label'), 'value' => (int) $allEnrollments->sum('final_points_cached'), 'hint' => __('dashboard.student.stats.cached_points.hint')],
                ['label' => __('dashboard.student.stats.memorized_pages.label'), 'value' => (int) $allEnrollments->sum('memorized_pages_cached'), 'hint' => __('dashboard.student.stats.memorized_pages.hint')],
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
        ];
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
                <div class="eyebrow">{{ __('dashboard.hero.workspace', ['role' => __('dashboard.roles.'.$dashboardRole)]) }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ $heading }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ $subheading }}</p>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-neutral-300">{{ $intro }}</p>

            </div>

            <aside class="surface-panel surface-panel--soft p-4 lg:p-5">
                <div class="flex items-center gap-4">
                    <x-user-avatar :user="auth()->user()" size="lg" />
                    <div class="min-w-0">
                        <div class="eyebrow">{{ __('dashboard.hero.signed_in_as') }}</div>
                        <div class="mt-2 truncate text-xl font-semibold text-white">{{ $profileName }}</div>
                        <p class="mt-1 truncate text-sm leading-6 text-neutral-300">{{ $profileMeta }}</p>
                    </div>
                </div>

                <div class="mt-5 grid gap-3">
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3">
                        <div class="kpi-label">{{ __('dashboard.hero.role') }}</div>
                        <div class="mt-2 text-sm font-semibold text-white">{{ __('dashboard.roles.'.$dashboardRole) }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3">
                        <div class="kpi-label">{{ __('dashboard.hero.job') }}</div>
                        <div class="mt-2 text-sm font-semibold text-white">{{ $profileJob }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3">
                        <div class="kpi-label">{{ __('dashboard.hero.current_academic_year') }}</div>
                        <div class="mt-2 text-sm font-semibold text-white">{{ $currentAcademicYearName }}</div>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    @if (! empty($stats))
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                <article class="stat-card">
                    <div class="flex items-start justify-between gap-4">
                        <div class="kpi-label">{{ $stat['label'] }}</div>
                        <span class="badge-soft {{ $loop->even ? 'badge-soft--emerald' : '' }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <div class="metric-value mt-6">{{ is_numeric($stat['value']) ? number_format($stat['value']) : $stat['value'] }}</div>
                    <p class="mt-4 max-w-xs text-sm leading-6 text-neutral-300">{{ $stat['hint'] }}</p>
                </article>
            @endforeach
        </div>
    @endif

    <div>
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
    </div>
</div>
