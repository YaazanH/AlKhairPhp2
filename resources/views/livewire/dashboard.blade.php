<?php

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
use App\Services\AccessScopeService;
use App\Services\CourseEndService;
use App\Services\CurriculumProgressService;
use App\Services\GroupDailySummaryService;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public ?int $selectedManagerStudentId = null;
    public bool $showTeacherLeaderboardModal = false;
    public bool $showTeacherMemorizationsModal = false;

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

        if ($user->teacherProfile || $user->can('dashboard.teacher.view') || $user->can('dashboard.group-teacher.view')) {
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

        $averageAttendanceByGroup = GroupAttendanceDay::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->withCount('records')
            ->withCount(['records as present_students_count' => fn ($query) => $query
                ->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_present', true))])
            ->get()
            ->groupBy('group_id')
            ->map(function ($days): array {
                $recordedDays = $days->where('records_count', '>', 0);

                return [
                    'count' => round((float) $days->avg('present_students_count'), 1),
                    'percentage' => $recordedDays->isEmpty()
                        ? 0.0
                        : round((float) $recordedDays->avg(fn (GroupAttendanceDay $day) => ($day->present_students_count / $day->records_count) * 100), 1),
                ];
            });

        $groupDistribution = $groups->map(fn (Group $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'students' => (int) $group->active_students_count,
            'average_attendance' => (float) ($averageAttendanceByGroup[$group->id]['count'] ?? 0),
            'average_attendance_percentage' => (float) ($averageAttendanceByGroup[$group->id]['percentage'] ?? 0),
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
            ->get();
        $activeEnrollmentIds = (clone $activeEnrollments)->pluck('id');
        $courseEndPointTotals = $defaultCourse
            ? app(CourseEndService::class)
                ->studentRows($defaultCourse)
                ->whereIn('enrollment_id', $activeEnrollmentIds)
                ->groupBy('student_id')
                ->map(fn ($rows): array => [
                    'points_before' => (int) $rows->sum('points_before'),
                    'points_after' => (int) $rows->sum('points_after'),
                ])
            : collect();
        $students = Student::query()->whereIn('id', $studentTotals->pluck('student_id'))->get()->keyBy('id');
        $studentPerformance = $studentTotals->values()
            ->map(function ($row) use ($courseEndPointTotals, $students): array {
                $projectedPoints = $courseEndPointTotals->get($row->student_id);

                return [
                    'student' => $students->get($row->student_id),
                    'points_before' => (int) ($projectedPoints['points_before'] ?? $row->points),
                    'points' => (int) ($projectedPoints['points_after'] ?? $row->points),
                    'pages' => (int) $row->pages,
                ];
            })
            ->filter(fn (array $row) => $row['student'])
            ->values();
        $studentPerformanceRanks = $studentPerformance
            ->sort(fn (array $left, array $right): int => ($right['points'] <=> $left['points'])
                ?: ($right['pages'] <=> $left['pages'])
                ?: ($left['student']->id <=> $right['student']->id))
            ->take(3)
            ->values()
            ->mapWithKeys(fn (array $row, int $index): array => [$row['student']->id => $index + 1]);
        $studentPerformance = $studentPerformance
            ->map(fn (array $row): array => [
                ...$row,
                'rank' => $studentPerformanceRanks->get($row['student']->id),
            ]);

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
                $selectedProjectedPoints = $courseEndPointTotals->get($selectedStudentModel->id);
                $selectedStudent = [
                    'student' => $selectedStudentModel,
                    'points' => (int) ($selectedProjectedPoints['points_after'] ?? (clone $selectedEnrollments)->sum('final_points_cached')),
                    'points_before' => (int) ($selectedProjectedPoints['points_before'] ?? (clone $selectedEnrollments)->sum('final_points_cached')),
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

        $curriculumProgress = Group::query()
            ->where('is_active', true)
            ->whereNotNull('curriculum_id')
            ->when($courseId, fn ($query) => $query->where('course_id', $courseId), fn ($query) => $query->whereRaw('1 = 0'))
            ->with(['curriculum.subjects.lessons', 'curriculumProgresses', 'customCurriculumLessons'])
            ->orderBy('name')
            ->get()
            ->map(fn (Group $group) => ['group' => $group, ...app(CurriculumProgressService::class)->summary($group)]);

        return [
            'dashboardRole' => 'manager',
            'teacherGroupDashboard' => false,
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
            'studentPerformance' => $studentPerformance,
            'groupPageTotals' => $groupPageTotals,
            'selectedManagerStudent' => $selectedStudent,
            'curriculumProgress' => $curriculumProgress,
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

    public function copyTeacherTodaySummary(int $groupId): void
    {
        $user = Auth::user();
        $teacher = $user?->teacherProfile?->load(['accessRole', 'jobTitle']);

        abort_unless($user && $teacher && ($user->can('dashboard.group-teacher.view') || $this->usesGroupSupervisorDashboard($teacher)), 403);

        $groupsQuery = app(AccessScopeService::class)->scopeGroups(Group::query(), $user);
        $group = (clone $groupsQuery)
            ->with(['course', 'teacher'])
            ->findOrFail($groupId);

        if (! $group) {
            return;
        }

        $date = now()->toDateString();

        $this->dispatch('admin-copy-text', text: app(GroupDailySummaryService::class)->currentCopyTextForUser($group, $date, $user));
    }

    protected function teacherData($user): array
    {
        $teacher = $user->teacherProfile?->load(['accessRole', 'jobTitle']);

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

        if ($user->can('dashboard.group-teacher.view') || $this->usesGroupSupervisorDashboard($teacher)) {
            return $this->teacherGroupData($user, $teacher, $groups);
        }

        $groupIds = (clone $groupsQuery)->pluck('id');
        $allAssignedGroups = (clone $groupsQuery)->count();
        $activeAssignedGroups = (clone $groupsQuery)->where('is_active', true)->count();
        $currentYearGroupCount = (clone $groupsQuery)
            ->whereHas('academicYear', fn ($query) => $query->where('is_current', true))
            ->count();

        $accessRoleName = $teacher->accessRole?->name;
        $accessRoleLabel = $teacher->jobTitle?->name ?: $teacher->job_title;
        $accessRoleLabel = filled($accessRoleLabel)
            ? $accessRoleLabel
            : ($accessRoleName
                ? ((__('ui.roles.'.$accessRoleName) === 'ui.roles.'.$accessRoleName)
                    ? Str::of($accessRoleName)->replaceMatches('/[_-]+/', ' ')->squish()->toString()
                    : __('ui.roles.'.$accessRoleName))
                : __('dashboard.roles.teacher'));

        return [
            'dashboardRole' => 'teacher',
            'teacherGroupDashboard' => false,
            'heading' => __('dashboard.teacher.heading'),
            'subheading' => __('dashboard.teacher.subheading'),
            'intro' => __('dashboard.teacher.intro'),
            'profileName' => $teacher->first_name.' '.$teacher->last_name,
            'profileJob' => $accessRoleLabel,
            'currentAcademicYearName' => $this->dashboardCourseName(),
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
                        ['label' => __('ui.nav.teacher_attendance'), 'route' => auth()->user()->can('attendance.teacher.view') ? route('teacher-attendance.index') : null],
                        ['label' => __('ui.nav.assessments'), 'route' => auth()->user()->can('assessments.view') ? route('assessments.index') : null],
                        ['label' => __('ui.nav.student_notes'), 'route' => auth()->user()->can('student-notes.view') ? route('student-notes.index') : null],
                    ])->filter(fn (array $link) => $link['route']),
                ],
            ],
            'recordsHeading' => __('dashboard.teacher.records.heading'),
            'recordsEmpty' => __('dashboard.teacher.records.empty'),
            'records' => $groups->map(fn (Group $group) => [
                'title' => $group->name,
                'subtitle' => $group->course?->name ?: __('dashboard.common.no_course'),
                'meta' => __('dashboard.common.active_students', ['count' => $group->enrollments_count]),
            ]),
        ];
    }

    protected function usesGroupSupervisorDashboard($teacher): bool
    {
        $roleNames = collect([
            $teacher->accessRole?->name,
            $teacher->job_title,
            $teacher->jobTitle?->name,
        ])->filter()->map(fn (string $name) => Str::lower(Str::squish($name)));

        return $roleNames->contains(fn (string $name) => in_array($name, [
            'مشرف حلقة',
            'مساعد مشرف حلقة',
            'group supervisor',
            'assistant group supervisor',
            'group_supervisor',
            'assistant_group_supervisor',
            'halaqa supervisor',
            'assistant halaqa supervisor',
            'halaqa_supervisor',
            'assistant_halaqa_supervisor',
        ], true));
    }

    protected function teacherGroupData($user, $teacher, $groups): array
    {
        $group = $groups->where('is_active', true)->first() ?: $groups->first();
        $groupId = $group?->id;
        $enrollments = $groupId
            ? Enrollment::query()
                ->with([
                    'student',
                    'studentAttendanceRecords.status',
                    'quranFinalTests',
                    'assessmentResults.assessment.type',
                ])
                ->where('group_id', $groupId)
                ->where('status', 'active')
                ->get()
            : collect();

        $studentRows = $enrollments->map(function (Enrollment $enrollment): array {
            $attendance = $enrollment->studentAttendanceRecords;
            $daysAttended = $attendance
                ->filter(fn (StudentAttendanceRecord $record) => (bool) $record->status?->is_present)
                ->pluck('group_attendance_day_id')
                ->unique()
                ->count();
            $finalScores = $enrollment->assessmentResults
                ->filter(fn ($result) => $this->isFinalAssessmentResult($result))
                ->pluck('score')
                ->filter(fn ($score) => $score !== null);

            return [
                'student' => $enrollment->student,
                'days_attended' => $daysAttended,
                'points' => (int) $enrollment->final_points_cached,
                'pages' => (int) $enrollment->memorized_pages_cached,
                'final_tests' => $enrollment->quranFinalTests->where('status', 'passed')->count(),
                'final_exam_score' => $finalScores->isEmpty() ? null : round((float) $finalScores->average(), 2),
            ];
        })->filter(fn (array $row) => $row['student'])->values();

        $attendanceRecords = $enrollments->flatMap->studentAttendanceRecords;
        $attendanceAverage = $attendanceRecords->isEmpty()
            ? 0
            : round(($attendanceRecords->filter(fn (StudentAttendanceRecord $record) => (bool) $record->status?->is_present)->count() / $attendanceRecords->count()) * 100, 1);

        $trendDates = GroupAttendanceDay::query()
            ->when($groupId, fn ($query) => $query->where('group_id', $groupId), fn ($query) => $query->whereRaw('1 = 0'))
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
            ->where('entry_type', 'new')
            ->when($groupId, fn ($query) => $query->whereHas('enrollment', fn ($enrollmentQuery) => $enrollmentQuery->where('group_id', $groupId)), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($trendStart, fn ($query) => $query->whereDate('recorded_on', '>=', $trendStart), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($trendFinish, fn ($query) => $query->whereDate('recorded_on', '<=', $trendFinish))
            ->selectRaw('recorded_on as activity_date, SUM(pages_count) as total_pages')
            ->groupBy('recorded_on')
            ->get()
            ->mapWithKeys(fn ($row) => [Carbon::parse($row->activity_date)->toDateString() => (int) $row->total_pages]);
        $attendanceByDate = StudentAttendanceRecord::query()
            ->whereHas('status', fn ($query) => $query->where('is_present', true))
            ->whereHas('attendanceDay', fn ($query) => $query
                ->when($groupId, fn ($dayQuery) => $dayQuery->where('group_id', $groupId), fn ($dayQuery) => $dayQuery->whereRaw('1 = 0'))
                ->when($trendStart && $trendFinish, fn ($dayQuery) => $dayQuery->whereBetween('attendance_date', [$trendStart, $trendFinish]), fn ($dayQuery) => $dayQuery->whereRaw('1 = 0')))
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

        $rankedStudents = $studentRows->sortBy([
            ['points', 'desc'],
            ['pages', 'desc'],
        ])->values();
        $topMemorizingStudents = $studentRows->sortByDesc('pages')->take(5)->values();
        $latestMemorizationsQuery = MemorizationSession::query()
            ->with('student')
            ->where('teacher_id', $teacher->id)
            ->when($groupId, fn ($query) => $query->whereHas('enrollment', fn ($enrollmentQuery) => $enrollmentQuery->where('group_id', $groupId)), fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('recorded_on')
            ->orderByDesc('id');
        $latestFiveMemorizations = (clone $latestMemorizationsQuery)->limit(5)->get();
        $latestMemorizations = $latestMemorizationsQuery->paginate(10, ['*'], 'teacherMemorizationPage');

        $accessRoleName = $teacher->accessRole?->name;
        $accessRoleLabel = $teacher->jobTitle?->name ?: $teacher->job_title;
        $accessRoleLabel = filled($accessRoleLabel)
            ? $accessRoleLabel
            : ($accessRoleName
                ? ((__('ui.roles.'.$accessRoleName) === 'ui.roles.'.$accessRoleName)
                    ? Str::of($accessRoleName)->replaceMatches('/[_-]+/', ' ')->squish()->toString()
                    : __('ui.roles.'.$accessRoleName))
                : __('dashboard.roles.teacher'));

        $teacherCurriculumSummary = $group
            ? app(CurriculumProgressService::class)->summary($group)
            : ['total' => 0, 'completed' => 0, 'percentage' => 0];

        return [
            'dashboardRole' => 'teacher',
            'teacherGroupDashboard' => true,
            'heading' => __('dashboard.teacher.group_dashboard.heading'),
            'subheading' => __('dashboard.teacher.group_dashboard.subheading'),
            'intro' => __('dashboard.teacher.intro'),
            'profileName' => $teacher->first_name.' '.$teacher->last_name,
            'profileJob' => $accessRoleLabel,
            'currentAcademicYearName' => $group
                ? ($group->course?->name ?: $this->dashboardCourseName())
                : $this->dashboardCourseName(),
            'dashboardGroupName' => $group?->name,
            'profileMeta' => $accessRoleLabel,
            'stats' => [
                [
                    'label' => __('dashboard.teacher.group_dashboard.today_summary'),
                    'value' => __('dashboard.teacher.group_dashboard.copy_today_summary'),
                    'action' => $group ? 'copyTeacherTodaySummary('.$group->id.')' : null,
                ],
                ['label' => __('dashboard.teacher.group_dashboard.stats.students'), 'value' => $enrollments->count()],
                ['label' => __('dashboard.teacher.group_dashboard.stats.attendance_average'), 'value' => number_format($attendanceAverage, 1).'%'],
                ['label' => __('dashboard.teacher.group_dashboard.stats.memorized_pages'), 'value' => (int) $enrollments->sum('memorized_pages_cached')],
            ],
            'cards' => collect(),
            'recordsHeading' => __('dashboard.teacher.records.heading'),
            'recordsEmpty' => __('dashboard.teacher.records.empty'),
            'records' => collect(),
            'teacherGroup' => $group,
            'teacherDailyTrend' => $dailyTrend,
            'teacherRankedStudents' => $rankedStudents,
            'teacherTopStudents' => $rankedStudents->take(5),
            'teacherTopMemorizingStudents' => $topMemorizingStudents,
            'teacherLatestMemorizations' => $latestMemorizations,
            'teacherLatestFiveMemorizations' => $latestFiveMemorizations,
            'teacherCurriculumSummary' => $teacherCurriculumSummary,
        ];
    }

    protected function isFinalAssessmentResult($result): bool
    {
        $assessment = $result->assessment;
        $code = Str::lower((string) $assessment?->type?->code);
        $name = Str::lower(Str::squish(($assessment?->type?->name ?? '').' '.($assessment?->title ?? '')));

        return in_array($code, ['final', 'final_exam', 'final-exam'], true)
            || Str::contains($name, ['final exam', 'final assessment', 'نهائي']);
    }

    public function openTeacherLeaderboard(): void
    {
        $teacher = Auth::user()?->teacherProfile?->load(['accessRole', 'jobTitle']);
        abort_unless($teacher && $this->usesGroupSupervisorDashboard($teacher), 403);
        $this->showTeacherLeaderboardModal = true;
    }

    public function closeTeacherLeaderboard(): void
    {
        $this->showTeacherLeaderboardModal = false;
    }

    public function openTeacherMemorizations(): void
    {
        $teacher = Auth::user()?->teacherProfile?->load(['accessRole', 'jobTitle']);
        abort_unless($teacher && $this->usesGroupSupervisorDashboard($teacher), 403);
        $this->resetPage('teacherMemorizationPage');
        $this->showTeacherMemorizationsModal = true;
    }

    public function closeTeacherMemorizations(): void
    {
        $this->showTeacherMemorizationsModal = false;
        $this->resetPage('teacherMemorizationPage');
    }

    protected function dashboardCourseName(): string
    {
        return Course::query()->where('is_default', true)->where('is_active', true)->value('name')
            ?: __('dashboard.manager.profile_meta_no_course');
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
            'teacherGroupDashboard' => false,
            'heading' => __('dashboard.parent.heading'),
            'subheading' => __('dashboard.parent.subheading'),
            'intro' => __('dashboard.parent.intro'),
            'profileName' => $parent->father_name,
            'profileJob' => __('dashboard.roles.parent'),
            'currentAcademicYearName' => $this->dashboardCourseName(),
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
                'title' => $student->full_name,
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
            'teacherGroupDashboard' => false,
            'heading' => __('dashboard.student.heading'),
            'subheading' => __('dashboard.student.subheading'),
            'intro' => __('dashboard.student.intro'),
            'profileName' => $student->full_name,
            'profileJob' => $student->gradeLevel?->name ?: __('dashboard.roles.student'),
            'currentAcademicYearName' => $this->dashboardCourseName(),
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
            'teacherGroupDashboard' => false,
            'heading' => __('dashboard.unassigned.heading'),
            'subheading' => __('dashboard.unassigned.subheading'),
            'intro' => __('dashboard.unassigned.intro'),
            'profileName' => $user->name,
            'profileJob' => __('dashboard.roles.unassigned'),
            'currentAcademicYearName' => $this->dashboardCourseName(),
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
            'teacherGroupDashboard' => false,
            'heading' => $heading,
            'subheading' => __('dashboard.missing_profile.subheading'),
            'intro' => $message,
            'profileName' => Auth::user()->name,
            'profileJob' => __('dashboard.roles.'.$role),
            'currentAcademicYearName' => $this->dashboardCourseName(),
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
                <div class="dashboard-course-context mt-4 flex max-w-3xl flex-wrap items-center gap-x-3 gap-y-2 text-base leading-7 text-neutral-200">
                    <span class="dashboard-course-context__course">{{ $currentAcademicYearName }}</span>
                    @if ($teacherGroupDashboard && filled($dashboardGroupName ?? null))
                        <span class="dashboard-course-context__separator" aria-hidden="true">·</span>
                        <span class="dashboard-course-context__group">{{ $dashboardGroupName }}</span>
                    @endif
                </div>
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
                    @if (filled($stat['action'] ?? null))
                        <button type="button" wire:click="{{ $stat['action'] }}" class="pill-link pill-link--accent mt-3 w-full justify-center">
                            {{ $stat['value'] }}
                        </button>
                    @else
                        <div class="metric-value mt-3">{{ is_numeric($stat['value']) ? number_format($stat['value']) : $stat['value'] }}</div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <div>
        @if ($dashboardRole === 'manager')
            @php
                $groupStudentTotal = (int) $groupDistribution->sum('students');
                $chartColor = fn (int $index) => sprintf('hsl(%.1f 42%% 57%%)', fmod(24 + ($index * 137.508), 360));
                $lollipopGroups = $groupDistribution->where('students', '>', 0)->sortByDesc('students')->values();
                $lollipopMax = max(1, (float) $lollipopGroups->max(fn (array $group) => max($group['students'], $group['average_attendance'])));
                $niceScale = function (int $value): array {
                    $value = max(1, $value);
                    $best = null;
                    $magnitude = 10 ** floor(log10($value));
                    foreach ([$magnitude / 10, $magnitude, $magnitude * 10] as $base) {
                        foreach ([1, 2, 2.5, 5, 10] as $factor) {
                            $step = $base * $factor;
                            $ticks = (int) ceil($value / $step);
                            if ($ticks < 4 || $ticks > 9) continue;
                            $maximum = $ticks * $step;
                            $score = (($maximum - $value) / $value) + (abs(6 - $ticks) * .015);
                            if ($best === null || $score < $best['score']) $best = compact('step', 'ticks', 'maximum', 'score');
                        }
                    }
                    return $best ?: ['step' => $value / 5, 'ticks' => 5, 'maximum' => $value, 'score' => 0];
                };
                $axisLabel = fn (float $value) => number_format($value, floor($value) === $value ? 0 : 1);
                $trendScale = $niceScale((int) $dailyTrend->max(fn (array $day) => max($day['pages'], $day['attendance'])));
                $trendMax = $trendScale['maximum'];
                $trendTicks = $trendScale['ticks'];
                $trendX = fn (int $index) => app()->isLocale('ar')
                    ? 424 - ($index * (390 / max($dailyTrend->count() - 1, 1)))
                    : 34 + ($index * (390 / max($dailyTrend->count() - 1, 1)));
                $trendY = fn (int $value) => 180 - (($value / $trendMax) * 152);
                $pagesLine = $dailyTrend->values()->map(fn (array $day, int $index) => $trendX($index).','.$trendY($day['pages']))->implode(' ');
                $attendanceLine = $dailyTrend->values()->map(fn (array $day, int $index) => $trendX($index).','.$trendY($day['attendance']))->implode(' ');
                $barHighest = max(1, (int) $groupPageTotals->max('pages'));
                $barRawStep = $barHighest / 6;
                $barMagnitude = 10 ** floor(log10($barRawStep));
                $barNormalizedStep = $barRawStep / $barMagnitude;
                $barNiceStep = ($barNormalizedStep <= 1 ? 1 : ($barNormalizedStep <= 2 ? 2 : ($barNormalizedStep <= 2.5 ? 2.5 : ($barNormalizedStep <= 5 ? 5 : 10)))) * $barMagnitude;
                $barTicks = max(1, (int) ceil($barHighest / $barNiceStep));
                $barMax = $barTicks * $barNiceStep;
                $barColumnCount = max(1, $groupPageTotals->count());
                $performanceMinimumPoints = min(0, (int) $studentPerformance->min('points'));
                $performanceMaximumPoints = max(1, (int) $studentPerformance->max('points'));
                $performanceMaximumPages = max(1, (int) $studentPerformance->max('pages'));
                $performancePointsSpan = max(1, $performanceMaximumPoints - $performanceMinimumPoints);
                $performanceAveragePoints = $studentPerformance->isEmpty() ? 0 : (float) $studentPerformance->avg('points_before');
                $performanceAveragePages = $studentPerformance->isEmpty() ? 0 : (float) $studentPerformance->avg('pages');
                $performanceX = fn (int $pages) => 7 + ((max(0, $pages) / $performanceMaximumPages) * 86);
                $performanceY = fn (int $points) => 7 + (((min($performanceMaximumPoints, max($performanceMinimumPoints, $points)) - $performanceMinimumPoints) / $performancePointsSpan) * 86);
                $performanceAverageX = $performanceX((int) round($performanceAveragePages));
                $performanceAverageY = $performanceY((int) round($performanceAveragePoints));
                $aboveAveragePerformance = $studentPerformance->filter(fn (array $entry): bool => $entry['points'] > $performanceAveragePoints
                    && $entry['pages'] > $performanceAveragePages);
                $aboveAveragePerformanceByStrength = $aboveAveragePerformance
                    ->sortBy(fn (array $entry): float => (($entry['points'] - $performanceAveragePoints) / $performancePointsSpan)
                        + (($entry['pages'] - $performanceAveragePages) / $performanceMaximumPages))
                    ->values();
                $aboveAveragePerformanceCount = $aboveAveragePerformanceByStrength->count();
                $aboveAverageDotSizes = $aboveAveragePerformanceByStrength
                    ->mapWithKeys(function (array $entry, int $index) use ($aboveAveragePerformanceCount): array {
                        $percentile = $aboveAveragePerformanceCount <= 1 ? 1.0 : $index / ($aboveAveragePerformanceCount - 1);
                        $growth = min(1.0, $percentile / 0.9);
                        $size = round(0.42 + (0.36 * $growth), 3);

                        return [$entry['student']->id => $size];
                    });
                $dimmedPerformanceClusters = [];
                $performanceClusterRadius = 0.5;

                foreach ($studentPerformance as $entry) {
                    $isAbovePerformanceAverage = $entry['points'] > $performanceAveragePoints
                        && $entry['pages'] > $performanceAveragePages;

                    if ($isAbovePerformanceAverage) {
                        continue;
                    }

                    $pointX = (float) $performanceX($entry['pages']);
                    $pointY = (float) $performanceY($entry['points']);
                    $nearestClusterIndex = null;
                    $nearestClusterDistance = INF;

                    foreach ($dimmedPerformanceClusters as $clusterIndex => $cluster) {
                        $horizontalDistance = $pointX - $cluster['x'];
                        $verticalDistance = ($pointY - $cluster['y']) * (7.5 / 16);
                        $distance = hypot($horizontalDistance, $verticalDistance);

                        if ($distance <= $performanceClusterRadius && $distance < $nearestClusterDistance) {
                            $nearestClusterIndex = $clusterIndex;
                            $nearestClusterDistance = $distance;
                        }
                    }

                    if ($nearestClusterIndex === null) {
                        $dimmedPerformanceClusters[] = [
                            'x' => $pointX,
                            'y' => $pointY,
                            'count' => 1,
                            'rank' => $entry['rank'],
                        ];
                        continue;
                    }

                    $cluster = $dimmedPerformanceClusters[$nearestClusterIndex];
                    $nextCount = $cluster['count'] + 1;
                    $clusterRank = $cluster['rank'];

                    if ($entry['rank'] && (! $clusterRank || $entry['rank'] < $clusterRank)) {
                        $clusterRank = $entry['rank'];
                    }

                    $dimmedPerformanceClusters[$nearestClusterIndex] = [
                        'x' => (($cluster['x'] * $cluster['count']) + $pointX) / $nextCount,
                        'y' => (($cluster['y'] * $cluster['count']) + $pointY) / $nextCount,
                        'count' => $nextCount,
                        'rank' => $clusterRank,
                    ];
                }
            @endphp

            <section class="dashboard-analytics-grid grid gap-6 xl:grid-cols-2">
                <article class="surface-panel p-5 lg:p-6">
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.group_distribution') }}</h2>
                    @if ($groupStudentTotal > 0)
                        <div class="dashboard-treemap mt-5 space-y-2.5" role="img" aria-label="{{ __('dashboard.manager.analytics.group_distribution') }}">
                            @foreach ($lollipopGroups as $index => $group)
                                <div class="grid grid-cols-[minmax(5rem,9rem)_minmax(0,1fr)_auto] items-center gap-3">
                                    <div class="truncate text-xs text-neutral-300">{{ $group['name'] }}</div>
                                    <div class="relative h-5">
                                        <span class="absolute inset-y-1/2 start-0 h-px -translate-y-1/2 rounded-full opacity-70" style="width: {{ ($group['students'] / $lollipopMax) * 100 }}%; background: {{ $chartColor($index) }}"></span>
                                        <span class="absolute top-1/2 h-3.5 w-3.5 -translate-y-1/2 rounded-full border-2 border-neutral-950 shadow" style="inset-inline-start: calc({{ ($group['students'] / $lollipopMax) * 100 }}% - .4375rem); background: {{ $chartColor($index) }}"></span>
                                        <span class="dashboard-lollipop-attendance absolute top-1/2 h-2.5 w-2.5 -translate-y-1/2 rounded-full border-2 border-neutral-950 bg-sky-300 shadow" style="inset-inline-start: calc({{ ($group['average_attendance'] / $lollipopMax) * 100 }}% - .3125rem)" tabindex="0" aria-label="{{ number_format($group['average_attendance_percentage'], 1) }}%">
                                            <span class="dashboard-lollipop-attendance__tooltip" aria-hidden="true">{{ number_format($group['average_attendance_percentage'], 1) }}%</span>
                                        </span>
                                    </div>
                                    <div class="min-w-8 text-end text-xs font-semibold text-white">{{ number_format($group['students']) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="admin-empty-state mt-5">{{ __('dashboard.manager.analytics.no_group_students') }}</div>
                    @endif
                </article>

                <article class="surface-panel flex min-h-[22rem] flex-col p-5 lg:p-6">
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.daily_activity') }}</h2>
                    <div class="dashboard-line-chart-shell flex flex-1 items-center justify-center" data-dashboard-expanded-line-chart>
                    <svg viewBox="0 0 458 220" dir="ltr" class="dashboard-line-chart mx-auto h-auto w-full max-w-none overflow-hidden" role="img" aria-label="{{ __('dashboard.manager.analytics.daily_activity') }}">
                        <line x1="{{ app()->isLocale('ar') ? 424 : 34 }}" y1="28" x2="{{ app()->isLocale('ar') ? 424 : 34 }}" y2="180" stroke="rgba(255,255,255,.3)" stroke-width="1.5" />
                        <line x1="34" y1="180" x2="424" y2="180" stroke="rgba(255,255,255,.3)" stroke-width="1.5" />
                        @foreach (range(0, $trendTicks) as $tick)
                            @php
                                $ratio = $tick / $trendTicks;
                                $gridY = 180 - ($ratio * 152);
                            @endphp
                            <line x1="34" y1="{{ $gridY }}" x2="424" y2="{{ $gridY }}" stroke="rgba(255,255,255,.09)" stroke-width="1" />
                            <text x="{{ app()->isLocale('ar') ? 436 : 22 }}" y="{{ $gridY + 3 }}" text-anchor="{{ app()->isLocale('ar') ? 'start' : 'end' }}" direction="ltr" fill="#a3a3a3" font-size="9">{{ $axisLabel($trendMax * $ratio) }}</text>
                        @endforeach
                        @foreach ($dailyTrend as $index => $day)
                            <line x1="{{ $trendX($index) }}" y1="28" x2="{{ $trendX($index) }}" y2="180" stroke="rgba(255,255,255,.09)" stroke-width="1" />
                        @endforeach
                        <polyline points="{{ $pagesLine }}" fill="none" stroke="#34d399" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="{{ $attendanceLine }}" fill="none" stroke="#38bdf8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        @foreach ($dailyTrend as $index => $day)
                            <g class="dashboard-line-point" tabindex="0" aria-label="{{ $day['label'] }} · {{ __('dashboard.manager.analytics.memorized_pages') }}: {{ number_format($day['pages']) }}">
                                <circle cx="{{ $trendX($index) }}" cy="{{ $trendY($day['pages']) }}" r="6" fill="#34d399" class="dashboard-chart-point origin-center" />
                                <g class="dashboard-line-point__tooltip" transform="translate({{ $trendX($index) }}, {{ max(16, $trendY($day['pages']) - 12) }})" data-dashboard-line-tooltip-value-only>
                                    <rect x="-15" y="-15" width="30" height="15" rx="4" fill="rgba(10,10,10,.96)" stroke="rgba(255,255,255,.16)" stroke-width="0.5" />
                                    <text x="0" y="-4.5" text-anchor="middle" fill="white" font-size="8" font-weight="800" class="dashboard-line-point__tooltip-value">{{ number_format($day['pages']) }}</text>
                                </g>
                            </g>
                            <g class="dashboard-line-point" tabindex="0" aria-label="{{ $day['label'] }} · {{ __('dashboard.manager.analytics.students_attended') }}: {{ number_format($day['attendance']) }}">
                                <circle cx="{{ $trendX($index) }}" cy="{{ $trendY($day['attendance']) }}" r="6" fill="#38bdf8" class="dashboard-chart-point origin-center" />
                                <g class="dashboard-line-point__tooltip" transform="translate({{ $trendX($index) }}, {{ min(214, $trendY($day['attendance']) + 28) }})" data-dashboard-line-tooltip-value-only>
                                    <rect x="-15" y="-15" width="30" height="15" rx="4" fill="rgba(10,10,10,.96)" stroke="rgba(255,255,255,.16)" stroke-width="0.5" />
                                    <text x="0" y="-4.5" text-anchor="middle" fill="white" font-size="8" font-weight="800" class="dashboard-line-point__tooltip-value">{{ number_format($day['attendance']) }}</text>
                                </g>
                            </g>
                            <text x="{{ $trendX($index) }}" y="205" text-anchor="middle" fill="#a3a3a3" font-size="9">{{ $day['label'] }}</text>
                        @endforeach
                    </svg>
                    </div>
                </article>
            </section>

            <section class="dashboard-ranking-grid mt-6 grid items-stretch gap-6 xl:grid-cols-2">
                <article class="surface-panel flex min-h-[26rem] flex-col p-5 lg:p-6">
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.performance_map') }}</h2>
                    @if ($studentPerformance->isEmpty())
                        <div class="admin-empty-state mt-5 flex-1">{{ __('dashboard.manager.analytics.no_performance_students') }}</div>
                    @else
                        <div class="dashboard-performance-map mt-5 flex-1" role="group" aria-label="{{ __('dashboard.manager.analytics.performance_map') }}">
                            <div class="dashboard-performance-map__plot">
                                <span class="dashboard-performance-map__zone dashboard-performance-map__zone--high-points" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">{{ __('dashboard.manager.analytics.high_points') }}</span>
                                <span class="dashboard-performance-map__zone dashboard-performance-map__zone--high-pages" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">{{ __('dashboard.manager.analytics.high_memorization') }}</span>
                                <span class="dashboard-performance-map__average-line dashboard-performance-map__average-line--vertical" style="--average-position: {{ $performanceAverageX }}%"></span>
                                <span class="dashboard-performance-map__average-line dashboard-performance-map__average-line--horizontal" style="--average-position: {{ $performanceAverageY }}%" data-points-average-before-rules="{{ $performanceAveragePoints }}"></span>
                                @foreach ($studentPerformance as $entry)
                                    @php
                                        $isAbovePerformanceAverage = $entry['points'] > $performanceAveragePoints
                                            && $entry['pages'] > $performanceAveragePages;
                                        $performanceRankClass = $entry['rank'] ? ' dashboard-performance-map__point--rank-'.$entry['rank'] : '';
                                        $performanceDotSize = $aboveAverageDotSizes->get($entry['student']->id, 0.78);
                                    @endphp
                                    @if ($isAbovePerformanceAverage)
                                        <button
                                            type="button"
                                            wire:click="showManagerStudent({{ $entry['student']->id }})"
                                            class="dashboard-performance-map__point dashboard-performance-map__point--above-average{{ $performanceRankClass }}"
                                            style="--point-x: {{ $performanceX($entry['pages']) }}%; --point-y: {{ $performanceY($entry['points']) }}%; --performance-dot-size: {{ $performanceDotSize }}rem"
                                            data-performance-rank="{{ $entry['rank'] }}"
                                            data-performance-dot-size="{{ $performanceDotSize }}"
                                            data-points-before="{{ $entry['points_before'] }}"
                                            data-points-after="{{ $entry['points'] }}"
                                            aria-label="{{ $entry['student']->full_name }} — {{ number_format($entry['points']) }} {{ __('dashboard.manager.analytics.points') }}, {{ trans_choice('dashboard.manager.analytics.pages_count', $entry['pages'], ['count' => number_format($entry['pages'])]) }}"
                                        >
                                            <span class="dashboard-performance-map__dot" aria-hidden="true"></span>
                                            <span class="dashboard-performance-map__tooltip" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
                                                <strong>{{ $entry['student']->full_name }}</strong>
                                                <small>{{ number_format($entry['points']) }} {{ __('dashboard.manager.analytics.points') }} · {{ trans_choice('dashboard.manager.analytics.pages_count', $entry['pages'], ['count' => number_format($entry['pages'])]) }}</small>
                                            </span>
                                        </button>
                                    @endif
                                @endforeach
                                <div class="dashboard-performance-map__dimmed-layer" data-performance-dimmed-layer data-performance-cluster-radius="{{ $performanceClusterRadius }}" aria-hidden="true">
                                    @foreach ($dimmedPerformanceClusters as $cluster)
                                        <span
                                            @class([
                                                'dashboard-performance-map__point',
                                                'dashboard-performance-map__point--below-average',
                                                'dashboard-performance-map__point--rank-'.$cluster['rank'] => $cluster['rank'],
                                            ])
                                            style="--point-x: {{ $cluster['x'] }}%; --point-y: {{ $cluster['y'] }}%"
                                            data-performance-cluster-size="{{ $cluster['count'] }}"
                                            data-performance-rank="{{ $cluster['rank'] }}"
                                            aria-hidden="true"
                                        >
                                            <span class="dashboard-performance-map__dot"></span>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                            <div class="dashboard-performance-map__averages" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
                                <span>{{ __('dashboard.manager.analytics.average_points') }}: <bdi>{{ number_format($performanceAveragePoints, 1) }}</bdi></span>
                                <span>{{ __('dashboard.manager.analytics.average_pages') }}: <bdi>{{ number_format($performanceAveragePages, 1) }}</bdi></span>
                            </div>
                        </div>
                    @endif
                </article>

                <article class="surface-panel flex min-h-[26rem] flex-col justify-center p-5 lg:p-6" data-dashboard-vertically-centered-bar-card data-dashboard-bar-content-centered>
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.top_groups_by_memorization') }}</h2>
                    @if ($groupPageTotals->isEmpty())
                        <div class="admin-empty-state mt-5 flex-1">{{ __('dashboard.manager.analytics.no_groups') }}</div>
                    @else
                        <div class="mt-6 flex items-center justify-center" data-dashboard-centered-bar-chart>
                        <div class="dashboard-bar-chart-shell mx-auto grid w-full max-w-xl grid-cols-[3rem_minmax(0,1fr)_3rem] gap-0" data-dashboard-balanced-axis>
                            <div class="flex h-64 flex-col justify-between border-e border-white/20 pe-2 text-end text-[10px] font-light text-neutral-400" style="grid-column: 1; grid-row: 1">
                                @foreach (range($barTicks, 0) as $tick)<span>{{ $axisLabel($barNiceStep * $tick) }}</span>@endforeach
                            </div>
                        <div class="relative" style="grid-column: 2; grid-row: 1">
                        <div class="pointer-events-none absolute inset-x-0 top-0 flex h-64 flex-col justify-between" aria-hidden="true">
                            @foreach (range($barTicks, 0) as $gridLine)
                                <span class="block border-t border-white/10"></span>
                            @endforeach
                        </div>
                        <div class="dashboard-bar-chart relative grid h-64 items-end gap-4 border-b border-white/20 px-3" style="grid-template-columns: repeat({{ $barColumnCount }}, minmax(0, 1fr))">
                            @foreach ($groupPageTotals as $index => $group)
                                @php
                                    $barHeight = max(3, ($group['pages'] / $barMax) * 100);
                                @endphp
                                <div class="relative flex h-full min-w-0 flex-col justify-end text-center">
                                    <div class="dashboard-bar-chart__bar mx-auto w-full max-w-16 shrink-0 rounded-t-xl transition-transform duration-200 hover:scale-x-110" style="height: {{ $barHeight }}%; background: {{ $chartColor($index) }}">
                                        <span class="sr-only">{{ $group['name'] }}: {{ trans_choice('dashboard.manager.analytics.pages_count', $group['pages'], ['count' => number_format($group['pages'])]) }}</span>
                                        <span class="dashboard-chart-tooltip dashboard-chart-tooltip--compact" data-dashboard-bar-tooltip-value-only>{{ number_format($group['pages']) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="grid gap-4 px-3 pt-3" style="grid-template-columns: repeat({{ $barColumnCount }}, minmax(0, 1fr))">
                            @foreach ($groupPageTotals as $group)
                                <div class="truncate text-center text-xs text-neutral-300" title="{{ $group['name'] }}">{{ $group['name'] }}</div>
                            @endforeach
                        </div>
                        </div>
                        <div aria-hidden="true" style="grid-column: 3; grid-row: 1"></div>
                        </div>
                        </div>
                    @endif
                </article>
            </section>

            <section class="dashboard-curriculum-progress-card surface-panel mt-6 p-5 lg:p-6">
                <h2 class="font-display mt-2 text-2xl text-white">{{ __('curricula.progress.title') }}</h2>
                @if ($curriculumProgress->isEmpty())
                    <div class="admin-empty-state mt-5">{{ __('curricula.progress.empty') }}</div>
                @else
                    <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach ($curriculumProgress as $row)
                            <a href="{{ route('curricula.index') }}" wire:navigate class="text-center">
                                <div class="mx-auto grid size-28 place-items-center rounded-full" style="background: conic-gradient(#9fbea9 {{ $row['percentage'] }}%, #c99b9b 0)">
                                    <div class="grid size-20 place-items-center rounded-full bg-neutral-950 text-lg font-semibold text-white">{{ number_format($row['percentage'], 0) }}%</div>
                                </div>
                                <div class="mt-3 truncate text-sm font-semibold text-white">{{ $row['group']->name }}</div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

        @endif

        @include('livewire.partials.dashboard-manager-student-modal')

        @if ($teacherGroupDashboard)
            @php
                $teacherNiceScale = function (int $value): array {
                    $value = max(1, $value);
                    $best = null;
                    $magnitude = 10 ** floor(log10($value));
                    foreach ([$magnitude / 10, $magnitude, $magnitude * 10] as $base) {
                        foreach ([1, 2, 2.5, 5, 10] as $factor) {
                            $step = max(1, ceil($base * $factor));
                            $ticks = (int) ceil($value / $step);
                            if ($ticks < 4 || $ticks > 9) continue;
                            $maximum = $ticks * $step;
                            $score = (($maximum - $value) / $value) + (abs(6 - $ticks) * .015);
                            if ($best === null || $score < $best['score']) $best = compact('step', 'ticks', 'maximum', 'score');
                        }
                    }
                    return $best ?: ['step' => $value / 5, 'ticks' => 5, 'maximum' => $value, 'score' => 0];
                };
                $teacherAxisLabel = fn (float $value) => number_format($value, floor($value) === $value ? 0 : 1);
                $teacherTrendScale = $teacherNiceScale((int) $teacherDailyTrend->max(fn (array $day) => max($day['pages'], $day['attendance'])));
                $teacherTrendMax = $teacherTrendScale['maximum'];
                $teacherTrendTicks = $teacherTrendScale['ticks'];
                $teacherTrendX = fn (int $index) => app()->isLocale('ar')
                    ? 400 - ($index * (342 / max($teacherDailyTrend->count() - 1, 1)))
                    : 58 + ($index * (342 / max($teacherDailyTrend->count() - 1, 1)));
                $teacherTrendY = fn (int $value) => 178 - (($value / $teacherTrendMax) * 128);
                $teacherPagesLine = $teacherDailyTrend->values()->map(fn (array $day, int $index) => $teacherTrendX($index).','.$teacherTrendY($day['pages']))->implode(' ');
                $teacherAttendanceLine = $teacherDailyTrend->values()->map(fn (array $day, int $index) => $teacherTrendX($index).','.$teacherTrendY($day['attendance']))->implode(' ');
                $teacherLollipopMax = max(1, (int) $teacherTopMemorizingStudents->max('pages'));
            @endphp

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="surface-panel p-5 lg:p-6">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div><div class="eyebrow">{{ __('dashboard.manager.analytics.last_five_attendance_days') }}</div><h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.manager.analytics.daily_activity') }}</h2></div>
                        <div class="flex flex-wrap gap-4 text-xs text-neutral-300">
                            <span class="flex items-center gap-2"><i class="h-2.5 w-6 rounded-full bg-emerald-400"></i>{{ __('dashboard.manager.analytics.memorized_pages') }}</span>
                            <span class="flex items-center gap-2"><i class="h-2.5 w-6 rounded-full bg-sky-400"></i>{{ __('dashboard.manager.analytics.students_attended') }}</span>
                        </div>
                    </div>
                    @if ($teacherDailyTrend->isEmpty())
                        <div class="admin-empty-state mt-5">{{ __('dashboard.teacher.group_dashboard.empty_activity') }}</div>
                    @else
                        <svg viewBox="0 0 440 220" dir="ltr" class="dashboard-line-chart mx-auto mt-6 h-auto w-full max-w-2xl overflow-hidden" role="img" aria-label="{{ __('dashboard.manager.analytics.daily_activity') }}">
                            <line x1="{{ app()->isLocale('ar') ? 400 : 58 }}" y1="42" x2="{{ app()->isLocale('ar') ? 400 : 58 }}" y2="178" stroke="rgba(255,255,255,.3)" stroke-width="1.5" />
                            <line x1="58" y1="178" x2="400" y2="178" stroke="rgba(255,255,255,.3)" stroke-width="1.5" />
                            @foreach (range(0, $teacherTrendTicks) as $tick)
                                @php
                                    $ratio = $tick / $teacherTrendTicks;
                                    $gridY = 178 - ($ratio * 128);
                                @endphp
                                <line x1="58" y1="{{ $gridY }}" x2="400" y2="{{ $gridY }}" stroke="rgba(255,255,255,.09)" stroke-width="1" />
                                <text x="{{ app()->isLocale('ar') ? 408 : 49 }}" y="{{ $gridY + 3 }}" text-anchor="{{ app()->isLocale('ar') ? 'start' : 'end' }}" fill="#a3a3a3" font-size="9">{{ $teacherAxisLabel($teacherTrendMax * $ratio) }}</text>
                            @endforeach
                            @foreach ($teacherDailyTrend as $index => $day)<line x1="{{ $teacherTrendX($index) }}" y1="50" x2="{{ $teacherTrendX($index) }}" y2="178" stroke="rgba(255,255,255,.09)" stroke-width="1" />@endforeach
                            <polyline points="{{ $teacherPagesLine }}" fill="none" stroke="#34d399" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="{{ $teacherAttendanceLine }}" fill="none" stroke="#38bdf8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                            @foreach ($teacherDailyTrend as $index => $day)
                                <g class="dashboard-line-point" tabindex="0"><circle cx="{{ $teacherTrendX($index) }}" cy="{{ $teacherTrendY($day['pages']) }}" r="6" fill="#34d399" class="dashboard-chart-point origin-center" /><title>{{ $day['label'] }} · {{ __('dashboard.manager.analytics.memorized_pages') }}: {{ number_format($day['pages']) }}</title></g>
                                <g class="dashboard-line-point" tabindex="0"><circle cx="{{ $teacherTrendX($index) }}" cy="{{ $teacherTrendY($day['attendance']) }}" r="6" fill="#38bdf8" class="dashboard-chart-point origin-center" /><title>{{ $day['label'] }} · {{ __('dashboard.manager.analytics.students_attended') }}: {{ number_format($day['attendance']) }}</title></g>
                                <text x="{{ $teacherTrendX($index) }}" y="202" text-anchor="middle" fill="#a3a3a3" font-size="9">{{ $day['label'] }}</text>
                            @endforeach
                        </svg>
                    @endif
                </article>

                <article class="teacher-memorization-ranking-card surface-panel p-5 lg:p-6">
                    <div class="eyebrow">{{ __('dashboard.teacher.group_dashboard.memorization_ranking_eyebrow') }}</div>
                    <h2 class="font-display mt-2 text-2xl text-white">{{ __('dashboard.teacher.group_dashboard.top_memorizing_students') }}</h2>
                    @if ($teacherTopMemorizingStudents->isEmpty())
                        <div class="admin-empty-state mt-5">{{ __('dashboard.teacher.group_dashboard.empty_students') }}</div>
                    @else
                        <div class="mt-6 space-y-3">
                            @foreach ($teacherTopMemorizingStudents as $index => $row)
                                <div class="teacher-memorization-ranking-row grid grid-cols-[minmax(7rem,11rem)_minmax(0,1fr)_auto] items-center gap-3">
                                    <div class="truncate text-sm text-neutral-200">{{ $row['student']->full_name }}</div>
                                    <div class="relative h-5">
                                        <span class="absolute inset-y-1/2 start-0 h-px -translate-y-1/2 rounded-full bg-emerald-400/75" style="width: {{ ($row['pages'] / $teacherLollipopMax) * 100 }}%"></span>
                                        <span class="absolute top-1/2 h-3.5 w-3.5 -translate-y-1/2 rounded-full border-2 border-neutral-950 bg-emerald-400 shadow" style="inset-inline-start: calc({{ ($row['pages'] / $teacherLollipopMax) * 100 }}% - .4375rem)"></span>
                                    </div>
                                    <div class="min-w-10 text-end text-sm font-semibold text-white">{{ number_format($row['pages']) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            </section>

            <section class="teacher-dashboard-secondary-grid mt-6 grid gap-6 xl:grid-cols-2">
                <article class="teacher-points-card surface-table">
                    <div class="teacher-points-card__header admin-grid-meta">
                        <div><div class="admin-grid-meta__title">{{ __('dashboard.teacher.group_dashboard.top_students_by_points') }}</div><div class="admin-grid-meta__summary">{{ __('dashboard.teacher.group_dashboard.top_five') }}</div></div>
                        @if ($teacherRankedStudents->isNotEmpty())<button type="button" wire:click="openTeacherLeaderboard" class="pill-link pill-link--compact">{{ __('dashboard.teacher.group_dashboard.view_all') }}</button>@endif
                    </div>
                    @if ($teacherTopStudents->isEmpty())
                        <div class="admin-empty-state">{{ __('dashboard.teacher.group_dashboard.empty_students') }}</div>
                    @else
                        <div class="teacher-points-desktop overflow-x-auto"><table class="text-sm"><thead><tr>
                            <th class="px-4 py-3 text-start">#</th><th class="px-4 py-3 text-start">{{ __('dashboard.teacher.group_dashboard.columns.student') }}</th><th class="px-4 py-3 text-start">{{ __('dashboard.teacher.group_dashboard.columns.points') }}</th><th class="px-4 py-3 text-start">{{ __('dashboard.teacher.group_dashboard.columns.pages') }}</th><th class="px-4 py-3 text-start">{{ __('dashboard.teacher.group_dashboard.columns.final_tests') }}</th>
                        </tr></thead><tbody class="divide-y divide-white/6">@foreach ($teacherTopStudents as $row)<tr><td class="px-4 py-3">{{ $loop->iteration }}</td><td class="px-4 py-3 font-medium text-white">{{ $row['student']->full_name }}</td><td class="px-4 py-3">{{ number_format($row['points']) }}</td><td class="px-4 py-3">{{ number_format($row['pages']) }}</td><td class="px-4 py-3">{{ number_format($row['final_tests']) }}</td></tr>@endforeach</tbody></table></div>
                        <div class="teacher-points-mobile">
                            @foreach ($teacherTopStudents as $row)
                                <article class="teacher-points-mobile__item">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="list-index shrink-0">{{ $loop->iteration }}</span>
                                        <div class="min-w-0 truncate font-semibold text-white">{{ $row['student']->full_name }}</div>
                                    </div>
                                    <dl class="teacher-points-mobile__metrics">
                                        <div><dt>{{ __('dashboard.teacher.group_dashboard.columns.points') }}</dt><dd>{{ number_format($row['points']) }}</dd></div>
                                        <div><dt>{{ __('dashboard.teacher.group_dashboard.columns.pages') }}</dt><dd>{{ number_format($row['pages']) }}</dd></div>
                                        <div><dt>{{ __('dashboard.teacher.group_dashboard.columns.final_tests') }}</dt><dd>{{ number_format($row['final_tests']) }}</dd></div>
                                    </dl>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </article>

                <article class="teacher-curriculum-card surface-panel flex flex-col items-center p-5 lg:p-6">
                    <h2 class="font-display mt-2 text-center text-2xl text-white">{{ __('curricula.progress.title') }}</h2>
                    @if (($teacherCurriculumSummary['total'] ?? 0) === 0)
                        <div class="admin-empty-state mt-5">{{ __('curricula.progress.empty') }}</div>
                    @else
                        <a href="{{ route('curricula.index') }}" wire:navigate class="mt-6 text-center">
                            <div class="mx-auto grid size-44 place-items-center rounded-full" style="background: conic-gradient(#9fbea9 {{ $teacherCurriculumSummary['percentage'] }}%, #c99b9b 0)">
                                <div class="grid size-32 place-items-center rounded-full bg-neutral-950 text-3xl font-semibold text-white">{{ number_format($teacherCurriculumSummary['percentage'], 0) }}%</div>
                            </div>
                            <div class="mt-3 text-sm font-semibold text-white">{{ $teacherGroup?->name }}</div>
                        </a>
                    @endif
                </article>
            </section>

            <x-admin.modal :show="$showTeacherLeaderboardModal" :title="__('dashboard.teacher.group_dashboard.all_students')" close-method="closeTeacherLeaderboard" max-width="4xl" compact>
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-3 py-2 text-start">#</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.student') }}</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.days_attended') }}</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.points') }}</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.pages') }}</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.final_tests') }}</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.final_exam_score') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($teacherRankedStudents as $row)<tr><td class="px-3 py-2">{{ $loop->iteration }}</td><td class="px-3 py-2 font-medium text-white">{{ $row['student']->full_name }}</td><td class="px-3 py-2">{{ number_format($row['days_attended']) }}</td><td class="px-3 py-2">{{ number_format($row['points']) }}</td><td class="px-3 py-2">{{ number_format($row['pages']) }}</td><td class="px-3 py-2">{{ number_format($row['final_tests']) }}</td><td class="px-3 py-2">{{ $row['final_exam_score'] === null ? '—' : \App\Support\PercentageFormatter::format($row['final_exam_score']) }}</td></tr>@endforeach</tbody></table></div>
            </x-admin.modal>

            <x-admin.modal :show="$showTeacherMemorizationsModal" :title="__('dashboard.teacher.group_dashboard.all_memorizations')" close-method="closeTeacherMemorizations" max-width="3xl" compact>
                <div class="space-y-3">
                    <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.student') }}</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.page_number') }}</th><th class="px-3 py-2 text-start">{{ __('dashboard.teacher.group_dashboard.columns.date') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($teacherLatestMemorizations as $session)<tr><td class="px-3 py-2 font-medium text-white">{{ $session->student?->full_name }}</td><td class="px-3 py-2"><span dir="ltr">{{ $session->from_page === $session->to_page ? $session->from_page : $session->from_page.'–'.$session->to_page }}</span></td><td class="px-3 py-2">{{ $session->recorded_on?->format('d-m-Y') }}</td></tr>@endforeach</tbody></table></div>
                    @if ($teacherLatestMemorizations->hasPages())<div>{{ $teacherLatestMemorizations->links() }}</div>@endif
                </div>
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

        @if ($dashboardRole !== 'manager' && ! $teacherGroupDashboard)
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
