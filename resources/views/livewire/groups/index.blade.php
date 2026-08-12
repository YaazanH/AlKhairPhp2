<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $course_id = null;
    public ?int $academic_year_id = null;
    public ?int $teacher_id = null;
    public ?int $assistant_teacher_id = null;
    public ?int $grade_level_id = null;
    public ?int $curriculum_id = null;
    public string $name = '';
    public string $capacity = '0';
    public bool $is_active = true;
    public string $search = '';
    public string $statusFilter = 'active';
    public string $courseFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public ?int $rosterGroupId = null;
    public ?int $roster_student_id = null;
    public string $roster_enrolled_at = '';
    public bool $showRosterModal = false;
    public ?int $quickSummaryGroupId = null;
    public string $quickSummaryDate = '';
    public bool $showQuickSummaryModal = false;
    public ?int $dashboardCardGroupId = null;
    public string $dashboard_card_template_id = '';
    public bool $showDashboardCardTemplateModal = false;

    public function mount(): void
    {
        $this->authorizePermission('groups.view');
        $this->courseFilter = 'all';
        $this->resetForm();
        $this->quickSummaryDate = now()->toDateString();
    }

    public function with(): array
    {
        $baseQuery = $this->scopeGroupsQuery(Group::query());
        $filteredQuery = $this->scopeGroupsQuery(Group::query())
            ->with(['academicYear', 'course', 'teacher', 'assistantTeacher', 'gradeLevel'])
            ->withCount(['enrollments', 'schedules'])
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('academicYear', fn ($yearQuery) => $yearQuery->where('name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('teacher', fn ($teacherQuery) => $teacherQuery
                            ->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('assistantTeacher', fn ($teacherQuery) => $teacherQuery
                            ->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when($this->courseFilter !== 'all', fn ($query) => $query->where('course_id', (int) $this->courseFilter))
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'groups' => $filteredQuery->paginate($this->perPage),
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'starts_on', 'ends_on']),
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->get(['id', 'name']),
            'teachers' => $this->availableTeachersQuery()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'curricula' => Curriculum::query()->where('is_active', true)->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))->orderBy('name')->get(['id', 'course_id', 'name']),
            'rosterGroup' => $this->rosterGroupId
                ? $this->scopeGroupsQuery(Group::query()->with(['course', 'teacher']))->find($this->rosterGroupId)
                : null,
            'rosterEnrollments' => $this->rosterGroupId
                ? $this->scopeEnrollmentsQuery(
                    Enrollment::query()
                        ->with(['student.parentProfile', 'student.gradeLevel', 'student.user'])
                        ->where('group_id', $this->rosterGroupId)
                )
                    ->orderBy('status')
                    ->orderBy('enrolled_at')
                    ->get()
                : collect(),
            'availableRosterStudents' => $this->rosterGroupId
                ? $this->availableRosterStudentsQuery()
                    ->with('parentProfile')
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'parent_id', 'first_name', 'last_name', 'student_number'])
                : collect(),
            'quickSummaryGroup' => $this->quickSummaryGroupId
                ? $this->scopeGroupsQuery(Group::query()->with(['course', 'teacher']))->find($this->quickSummaryGroupId)
                : null,
            'quickSummaryRows' => $this->showQuickSummaryModal ? $this->buildQuickSummaryRows() : collect(),
            'dashboardCardGroup' => $this->dashboardCardGroupId
                ? $this->scopeGroupsQuery(Group::query()->with(['course', 'academicYear', 'teacher']))->find($this->dashboardCardGroupId)
                : null,
            'dashboardCardTemplates' => ($this->showDashboardCardTemplateModal || $this->showFormModal)
                ? PrintTemplate::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeGroupsQuery(Group::query()->where('is_active', true))->count(),
            ],
            'filteredCount' => $filteredCount,
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCourseFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCourseId(): void
    {
        if ($this->curriculum_id && ! Curriculum::query()->whereKey($this->curriculum_id)->where('course_id', $this->course_id)->exists()) {
            $this->curriculum_id = null;
        }
    }

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'exists:courses,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'assistant_teacher_id' => ['nullable', 'exists:teachers,id', 'different:teacher_id'],
            'grade_level_id' => ['nullable', 'exists:grade_levels,id'],
            'curriculum_id' => ['nullable', Rule::exists('curricula', 'id')->where(fn ($query) => $query->where('course_id', $this->course_id)->where('is_active', true))],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups', 'name')
                    ->where(fn ($query) => $query->where('academic_year_id', $this->academic_year_id))
                    ->ignore($this->editingId),
            ],
            'capacity' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'dashboard_card_template_id' => [
                'nullable',
                'integer',
                Rule::exists('print_templates', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('groups.create');

        $this->resetForm();
        $this->showFormModal = true;
    }

    public function createAndNew(): void
    {
        $preservedCourseId = $this->course_id;
        $errorCount = $this->getErrorBag()->count();

        $this->save();

        if ($this->getErrorBag()->count() > $errorCount) {
            return;
        }

        $this->resetForm($preservedCourseId);
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'groups.update' : 'groups.create');

        if ($this->editingId) {
            $this->authorizeScopedGroupAccess(Group::query()->findOrFail($this->editingId));
        }

        $validated = $this->validate();
        $dashboardCardTemplateId = $validated['dashboard_card_template_id'] ?? null;
        unset($validated['dashboard_card_template_id']);
        $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail($validated['teacher_id']));

        if ($validated['assistant_teacher_id']) {
            $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail($validated['assistant_teacher_id']));
        }

        if (! $this->teacherIsAvailable((int) $validated['teacher_id'])) {
            $this->addError('teacher_id', __('crud.groups.errors.teacher_unavailable'));

            return;
        }

        if ($validated['assistant_teacher_id'] && ! $this->teacherIsAvailable((int) $validated['assistant_teacher_id'])) {
            $this->addError('assistant_teacher_id', __('crud.groups.errors.assistant_teacher_unavailable'));

            return;
        }

        $validated['assistant_teacher_id'] = $validated['assistant_teacher_id'] ?: null;
        $validated['grade_level_id'] = $validated['grade_level_id'] ?: null;
        $validated['curriculum_id'] = $validated['curriculum_id'] ?: null;

        $group = Group::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        $templateMap = $this->dashboardCardTemplateMap();
        if ($dashboardCardTemplateId) {
            $templateMap[(string) $group->id] = (int) $dashboardCardTemplateId;
        } else {
            unset($templateMap[(string) $group->id]);
        }
        AppSetting::storeValue('general', 'student_dashboard_card_templates', $templateMap, 'array');

        if (! $group->is_active) {
            $this->deactivateGroupEnrollments($group);
        }

        session()->flash(
            'status',
            $this->editingId ? __('crud.groups.messages.updated') : __('crud.groups.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $groupId): void
    {
        $this->authorizePermission('groups.update');

        $group = Group::query()->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        $this->editingId = $group->id;
        $this->course_id = $group->course_id;
        $this->academic_year_id = $group->academic_year_id;
        $this->teacher_id = $group->teacher_id;
        $this->assistant_teacher_id = $group->assistant_teacher_id;
        $this->grade_level_id = $group->grade_level_id;
        $this->curriculum_id = $group->curriculum_id;
        $this->name = $group->name;
        $this->capacity = (string) $group->capacity;
        $this->is_active = $group->is_active;
        $this->dashboard_card_template_id = (string) ($this->dashboardCardTemplateMap()[(string) $group->id] ?? '');
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showFormModal = false;
    }

    public function delete(int $groupId): void
    {
        $this->authorizePermission('groups.delete');

        $group = Group::query()->withCount(['enrollments', 'schedules'])->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        if ($group->enrollments_count > 0 || $group->schedules_count > 0) {
            $this->addError('delete', __('crud.groups.errors.delete_linked'));

            return;
        }

        $group->delete();

        if ($this->editingId === $groupId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.groups.messages.deleted'));
    }

    public function deactivate(int $groupId): void
    {
        $this->authorizePermission('groups.update');

        $group = Group::query()->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);
        $this->deactivateGroupEnrollments($group);

        session()->flash('status', __('crud.groups.messages.deactivated'));
    }

    public function openRosterModal(int $groupId): void
    {
        $group = Group::query()->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        $this->rosterGroupId = $group->id;
        $this->roster_student_id = null;
        $this->roster_enrolled_at = now()->toDateString();
        $this->showRosterModal = true;

        $this->resetValidation();
    }

    public function closeRosterModal(): void
    {
        $this->rosterGroupId = null;
        $this->roster_student_id = null;
        $this->roster_enrolled_at = '';
        $this->showRosterModal = false;

        $this->resetValidation();
    }

    public function openQuickSummaryModal(int $groupId): void
    {
        abort_unless($this->canPermission('attendance.student.view') || $this->canPermission('memorization.view'), 403);

        $group = Group::query()->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        $this->quickSummaryGroupId = $group->id;
        $this->quickSummaryDate = $this->quickSummaryDate ?: now()->toDateString();
        $this->showQuickSummaryModal = true;

        $this->resetValidation();
    }

    public function closeQuickSummaryModal(): void
    {
        $this->quickSummaryGroupId = null;
        $this->quickSummaryDate = now()->toDateString();
        $this->showQuickSummaryModal = false;

        $this->resetValidation();
    }

    public function openDashboardCardTemplateModal(int $groupId): void
    {
        $this->authorizePermission('groups.update');

        $group = Group::query()->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        $templateMap = $this->dashboardCardTemplateMap();

        $this->dashboardCardGroupId = $group->id;
        $this->dashboard_card_template_id = isset($templateMap[(string) $group->id])
            ? (string) $templateMap[(string) $group->id]
            : '';
        $this->showDashboardCardTemplateModal = true;

        $this->resetValidation();
    }

    public function closeDashboardCardTemplateModal(): void
    {
        $this->dashboardCardGroupId = null;
        $this->dashboard_card_template_id = '';
        $this->showDashboardCardTemplateModal = false;

        $this->resetValidation();
    }

    public function saveDashboardCardTemplate(): void
    {
        $this->authorizePermission('groups.update');

        abort_unless($this->dashboardCardGroupId, 404);

        $group = Group::query()->findOrFail($this->dashboardCardGroupId);
        $this->authorizeScopedGroupAccess($group);

        $validated = validator(
            [
                'dashboard_card_template_id' => filled($this->dashboard_card_template_id)
                    ? (int) $this->dashboard_card_template_id
                    : null,
            ],
            [
                'dashboard_card_template_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('print_templates', 'id')->where(fn ($query) => $query->where('is_active', true)),
                ],
            ]
        )->validate();

        $templateMap = $this->dashboardCardTemplateMap();
        $selectedTemplateId = $validated['dashboard_card_template_id'] ?? null;

        if ($selectedTemplateId) {
            $templateMap[(string) $group->id] = $selectedTemplateId;
        } else {
            unset($templateMap[(string) $group->id]);
        }

        AppSetting::storeValue('general', 'student_dashboard_card_templates', $templateMap, 'array');

        session()->flash('status', __('crud.groups.messages.dashboard_card_template_saved'));

        $this->closeDashboardCardTemplateModal();
    }

    public function clearDashboardCardTemplate(): void
    {
        $this->dashboard_card_template_id = '';

        $this->saveDashboardCardTemplate();
    }

    public function copyQuickSummary(): void
    {
        abort_unless($this->canPermission('attendance.student.view') || $this->canPermission('memorization.view'), 403);

        if (! $this->quickSummaryGroupId) {
            return;
        }

        $group = Group::query()->findOrFail($this->quickSummaryGroupId);
        $this->authorizeScopedGroupAccess($group);

        $rows = $this->buildQuickSummaryRows();

        if ($rows->isEmpty()) {
            return;
        }

        $this->dispatch('admin-copy-text', text: $this->buildQuickSummaryCopyText($group, $rows));
    }

    public function addStudentToRoster(): void
    {
        $this->authorizePermission('enrollments.create');

        $group = Group::query()->findOrFail($this->rosterGroupId);
        $this->authorizeScopedGroupAccess($group);

        $validated = $this->validate([
            'roster_student_id' => ['required', 'exists:students,id'],
            'roster_enrolled_at' => ['required', 'date'],
        ]);

        $student = Student::query()->findOrFail($validated['roster_student_id']);
        $this->authorizeScopedStudentAccess($student);

        $duplicateEnrollmentExists = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('group_id', $group->id)
            ->exists();

        if ($duplicateEnrollmentExists) {
            $this->addError('roster_student_id', __('crud.enrollments.errors.already_enrolled'));

            return;
        }

        Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => $validated['roster_enrolled_at'],
            'status' => 'active',
        ]);

        $this->roster_student_id = null;
        $this->roster_enrolled_at = now()->toDateString();

        session()->flash('status', __('crud.groups.messages.student_added'));
    }

    public function removeStudentFromRoster(int $enrollmentId): void
    {
        $this->authorizePermission('enrollments.delete');

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);
        $this->authorizeScopedEnrollmentAccess($enrollment);

        abort_unless($this->rosterGroupId && $enrollment->group_id === $this->rosterGroupId, 404);

        $enrollment->delete();

        session()->flash('status', __('crud.groups.messages.student_removed'));
    }

    protected function availableRosterStudentsQuery()
    {
        return $this->scopeStudentsQuery(Student::query())
            ->whereDoesntHave('enrollments', function ($enrollmentQuery) {
                $enrollmentQuery->where('group_id', $this->rosterGroupId);
            });
    }

    protected function dashboardCardTemplateMap(): array
    {
        $templateMap = AppSetting::groupValues('general')->get('student_dashboard_card_templates');

        if (! is_array($templateMap)) {
            return [];
        }

        return collect($templateMap)
            ->filter(fn ($templateId, $groupId) => filled($templateId) && filled($groupId))
            ->mapWithKeys(fn ($templateId, $groupId) => [(string) $groupId => (int) $templateId])
            ->all();
    }

    protected function defaultAcademicYearId(): ?int
    {
        return AcademicYear::query()
            ->where('is_current', true)
            ->where('is_active', true)
            ->value('id')
            ?? AcademicYear::query()
                ->where('is_active', true)
                ->orderByDesc('starts_on')
                ->value('id');
    }

    protected function resetForm(?int $courseId = null): void
    {
        $this->editingId = null;
        $this->course_id = $courseId;
        $this->academic_year_id = $this->defaultAcademicYearId();
        $this->teacher_id = null;
        $this->assistant_teacher_id = null;
        $this->grade_level_id = null;
        $this->curriculum_id = null;
        $this->name = '';
        $this->capacity = '0';
        $this->is_active = true;
        $this->dashboard_card_template_id = '';

        $this->resetValidation();
    }

    protected function availableTeachersQuery()
    {
        return $this->scopeTeachersQuery(
            Teacher::query()
                ->where('status', 'active')
                ->where('is_helping', true)
                ->whereDoesntHave('assignedGroups', function ($query) {
                    if ($this->editingId) {
                        $query->whereKeyNot($this->editingId);
                    }
                })
                ->whereDoesntHave('assistedGroups', function ($query) {
                    if ($this->editingId) {
                        $query->whereKeyNot($this->editingId);
                    }
                })
        );
    }

    protected function teacherIsAvailable(int $teacherId): bool
    {
        return $this->availableTeachersQuery()
            ->whereKey($teacherId)
            ->exists();
    }

    protected function deactivateGroupEnrollments(Group $group): void
    {
        DB::transaction(function () use ($group): void {
            $group->forceFill(['is_active' => false])->save();

            Enrollment::query()
                ->where('group_id', $group->id)
                ->update(['status' => 'cancelled']);
        });
    }

    protected function buildQuickSummaryRows()
    {
        if (! $this->quickSummaryGroupId) {
            return collect();
        }

        $group = Group::query()->findOrFail($this->quickSummaryGroupId);
        $this->authorizeScopedGroupAccess($group);

        $summaryDate = $this->quickSummaryDate ?: now()->toDateString();
        $canViewAttendance = $this->canPermission('attendance.student.view');
        $canViewMemorization = $this->canPermission('memorization.view');

        $enrollments = $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->with(['student.parentProfile'])
                ->where('group_id', $group->id)
                ->where('status', 'active')
        )
            ->orderBy('enrolled_at')
            ->orderBy('id')
            ->get();

        if ($enrollments->isEmpty()) {
            return collect();
        }

        $attendanceRecords = $canViewAttendance
            ? $this->scopeGroupAttendanceDaysQuery(
                GroupAttendanceDay::query()
                    ->where('group_id', $group->id)
                    ->whereDate('attendance_date', $summaryDate)
                    ->with(['records.status'])
            )
                ->get()
                ->flatMap(fn (GroupAttendanceDay $day) => $day->records)
                ->keyBy('enrollment_id')
            : collect();

        $memorizationSessionsByEnrollment = $canViewMemorization
            ? $this->scopeMemorizationSessionsQuery(
                MemorizationSession::query()
                    ->with(['pages' => fn ($query) => $query->orderBy('page_no')])
                    ->whereIn('enrollment_id', $enrollments->pluck('id'))
                    ->whereDate('recorded_on', $summaryDate)
                    ->where('entry_type', 'new')
            )
                ->get()
                ->groupBy('enrollment_id')
            : collect();

        return $enrollments
            ->map(function (Enrollment $enrollment) use ($attendanceRecords, $memorizationSessionsByEnrollment, $canViewAttendance, $canViewMemorization, $summaryDate): object {
                $student = $enrollment->student;
                $studentName = $student
                    ? trim($student->first_name.' '.$student->last_name)
                    : __('crud.common.not_available');
                $attendanceLabel = $canViewAttendance
                    ? ($attendanceRecords->get($enrollment->id)?->status?->name ?: __('crud.groups.quick_summary.attendance_missing'))
                    : __('crud.groups.quick_summary.attendance_unavailable');

                $memorizedPages = $canViewMemorization
                    ? $memorizationSessionsByEnrollment
                        ->get($enrollment->id, collect())
                        ->flatMap(function (MemorizationSession $session) {
                            $sessionPages = $session->pages
                                ->pluck('page_no')
                                ->map(fn ($page) => (int) $page)
                                ->filter(fn ($page) => $page > 0)
                                ->values();

                            if ($sessionPages->isEmpty() && filled($session->from_page) && filled($session->to_page)) {
                                $fromPage = (int) min($session->from_page, $session->to_page);
                                $toPage = (int) max($session->from_page, $session->to_page);

                                return collect(range($fromPage, $toPage));
                            }

                            return $sessionPages;
                        })
                        ->unique()
                        ->sort()
                        ->values()
                    : collect();

                $memorizedLabel = $canViewMemorization
                    ? ($memorizedPages->isNotEmpty()
                        ? __('crud.groups.quick_summary.memorized_pages', ['pages' => $this->formatQuickSummaryPages($memorizedPages->all())])
                        : __('crud.groups.quick_summary.memorization_missing'))
                    : __('crud.groups.quick_summary.memorization_unavailable');

                return (object) [
                    'enrollment_id' => $enrollment->id,
                    'student_name' => $studentName,
                    'student_number' => $student?->student_number,
                    'parent_name' => $student?->parentProfile?->father_name,
                    'attendance_label' => $attendanceLabel,
                    'memorized_label' => $memorizedLabel,
                    'copy_text' => implode(PHP_EOL, [
                        __('crud.groups.quick_summary.copy_lines.student', ['value' => $studentName]),
                        __('crud.groups.quick_summary.copy_lines.date', ['value' => $summaryDate]),
                        __('crud.groups.quick_summary.copy_lines.attendance', ['value' => $attendanceLabel]),
                        __('crud.groups.quick_summary.copy_lines.memorized', ['value' => $memorizedLabel]),
                    ]),
                ];
            })
            ->values();
    }

    protected function formatQuickSummaryPages(array $pages): string
    {
        $pages = collect($pages)
            ->map(fn ($page) => (int) $page)
            ->filter(fn ($page) => $page > 0)
            ->unique()
            ->sort()
            ->values();

        if ($pages->isEmpty()) {
            return '';
        }

        $ranges = [];
        $rangeStart = $pages->first();
        $rangeEnd = $rangeStart;

        foreach ($pages->slice(1) as $page) {
            if ($page === $rangeEnd + 1) {
                $rangeEnd = $page;

                continue;
            }

            $ranges[] = $rangeStart === $rangeEnd ? (string) $rangeStart : $rangeStart.'-'.$rangeEnd;
            $rangeStart = $page;
            $rangeEnd = $page;
        }

        $ranges[] = $rangeStart === $rangeEnd ? (string) $rangeStart : $rangeStart.'-'.$rangeEnd;

        return implode(', ', $ranges);
    }

    protected function buildQuickSummaryCopyText(Group $group, \Illuminate\Support\Collection $rows): string
    {
        $summaryDate = $this->quickSummaryDate ?: now()->toDateString();

        $header = array_filter([
            __('crud.groups.quick_summary.copy_lines.group', ['value' => $group->name]),
            __('crud.groups.quick_summary.copy_lines.date', ['value' => $summaryDate]),
            $group->course?->name
                ? __('crud.groups.quick_summary.copy_lines.course', ['value' => $group->course->name])
                : null,
            $group->teacher
                ? __('crud.groups.quick_summary.copy_lines.teacher', ['value' => trim($group->teacher->first_name.' '.$group->teacher->last_name)])
                : null,
        ]);

        $studentBlocks = $rows->map(fn (object $row) => $row->copy_text)->all();

        return implode(PHP_EOL.PHP_EOL, [
            implode(PHP_EOL, $header),
            implode(PHP_EOL.PHP_EOL, $studentBlocks),
        ]);
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('crud.groups.hero.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.groups.hero.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.groups.hero.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('crud.groups.hero.badges.active_courses', ['count' => number_format($courses->count())]) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('crud.groups.hero.badges.academic_years', ['count' => number_format($academicYears->count())]) }}</span>
            <span class="badge-soft">{{ __('crud.groups.hero.badges.teachers_available', ['count' => number_format($teachers->count())]) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.groups.stats.all.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.groups.stats.all.description') }}</p>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.groups.stats.active.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.groups.stats.active.description') }}</p>
        </article>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('crud.groups.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('crud.groups.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="group-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="group-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="group-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="group-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="group-course-filter">{{ __('crud.common.filters.course') }}</label>
                    <select id="group-course-filter" wire:model.live="courseFilter">
                        <option value="all">{{ __('crud.common.filters.all_courses') }}</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('groups.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('groups.export', ['search' => $search, 'status' => $statusFilter, 'course_id' => $courseFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('crud.groups.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($groups->isEmpty())
            <div class="admin-empty-state">{{ __('crud.groups.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full table-fixed text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.year') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.grade') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.students') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.groups.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($groups as $group)
                            @php
                                $groupStatusClass = $group->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate';
                            @endphp
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $group->name }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">
                                        {{ __('crud.groups.table.capacity', ['capacity' => $group->capacity]) }}
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $group->course?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $group->teacher ? $group->teacher->first_name.' '.$group->teacher->last_name : __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $group->academicYear?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $group->gradeLevel?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $group->enrollments_count }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="{{ $groupStatusClass }}">{{ $group->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}</span></td>
                                <td class="px-5 py-4 text-right lg:px-6">
                                    <a href="{{ route('groups.show', $group) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.open') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($groups->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $groups->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.groups.form.edit_title') : __('crud.groups.form.create_title')"
        :description="__('crud.groups.form.help')"
        close-method="cancel"
        max-width="5xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="group-course" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.course') }}</label>
                    <select id="group-course" wire:model="course_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.select_course') }}</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                    @error('course_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-academic-year" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.academic_year') }}</label>
                    <select id="group-academic-year" wire:model="academic_year_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.select_academic_year') }}</option>
                        @foreach ($academicYears as $academicYear)
                            <option value="{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                        @endforeach
                    </select>
                    @error('academic_year_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="group-teacher" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.teacher') }}</label>
                    <select id="group-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.select_teacher') }}</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('teacher_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-assistant-teacher" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.assistant_teacher') }}</label>
                    <select id="group-assistant-teacher" wire:model="assistant_teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.no_assistant') }}</option>
                        @foreach ($teachers as $teacher)
                            @continue($teacher_id && $teacher->id === (int) $teacher_id)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('assistant_teacher_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="group-name" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.group_name') }}</label>
                    <input id="group-name" wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-grade-level" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.grade_level') }}</label>
                    <select id="group-grade-level" wire:model="grade_level_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.all_grade_levels') }}</option>
                        @foreach ($gradeLevels as $gradeLevel)
                            <option value="{{ $gradeLevel->id }}">{{ $gradeLevel->name }}</option>
                        @endforeach
                    </select>
                    @error('grade_level_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div>
                <label for="group-curriculum" class="mb-1 block text-sm font-medium">{{ __('curricula.fields.curriculum') }}</label>
                <select id="group-curriculum" wire:model="curriculum_id" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('curricula.options.no_curriculum') }}</option>
                    @foreach ($curricula as $curriculum)
                        <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                    @endforeach
                </select>
                @error('curriculum_id')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
            </div>

            <div>
                <label for="group-capacity" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.capacity') }}</label>
                <input id="group-capacity" wire:model="capacity" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('capacity')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="group-card-template" class="mb-1 block text-sm font-medium">{{ __('crud.groups.dashboard_card.fields.template') }}</label>
                <select id="group-card-template" wire:model="dashboard_card_template_id" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('crud.groups.dashboard_card.placeholders.none') }}</option>
                    @foreach ($dashboardCardTemplates as $template)
                        <option value="{{ $template->id }}">{{ $template->name }}</option>
                    @endforeach
                </select>
                @error('dashboard_card_template_id')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
            </div>

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.groups.form.active_group') }}</span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.groups.form.update_submit') : __('crud.groups.form.create_submit') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingId" click="createAndNew" />
                <button type="button" wire:click="cancel" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showDashboardCardTemplateModal"
        :title="__('crud.groups.dashboard_card.title', ['group' => $dashboardCardGroup?->name ?? ''])"
        :description="__('crud.groups.dashboard_card.help')"
        close-method="closeDashboardCardTemplateModal"
        max-width="4xl"
    >
        <div class="space-y-6">
            @if ($dashboardCardGroup)
                <section class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.dashboard_card.summary.group') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $dashboardCardGroup->name }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.dashboard_card.summary.course') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $dashboardCardGroup->course?->name ?: __('crud.common.not_available') }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.dashboard_card.summary.year') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $dashboardCardGroup->academicYear?->name ?: __('crud.common.not_available') }}</div>
                        </div>
                    </div>
                </section>

                <form wire:submit="saveDashboardCardTemplate" class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                    <div>
                        <label for="group-dashboard-card-template" class="mb-1 block text-sm font-medium">{{ __('crud.groups.dashboard_card.fields.template') }}</label>
                        <select id="group-dashboard-card-template" wire:model="dashboard_card_template_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('crud.groups.dashboard_card.placeholders.none') }}</option>
                            @foreach ($dashboardCardTemplates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </select>
                        @error('dashboard_card_template_id')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                        @if ($dashboardCardTemplates->isEmpty())
                            <div class="mt-3 text-sm text-neutral-400">{{ __('crud.groups.dashboard_card.empty_templates') }}</div>
                        @endif
                    </div>

                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <button type="submit" class="pill-link pill-link--accent">
                            {{ __('crud.groups.dashboard_card.save_action') }}
                        </button>
                        @if (filled($dashboard_card_template_id))
                            <button type="button" wire:click="clearDashboardCardTemplate" class="pill-link">
                                {{ __('crud.groups.dashboard_card.clear_action') }}
                            </button>
                        @endif
                        <button type="button" wire:click="closeDashboardCardTemplateModal" class="pill-link">
                            {{ __('crud.common.actions.close') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </x-admin.modal>

    <x-admin.modal
        :show="$showQuickSummaryModal"
        :title="__('crud.groups.quick_summary.title', ['group' => $quickSummaryGroup?->name ?? ''])"
        :description="__('crud.groups.quick_summary.help')"
        close-method="closeQuickSummaryModal"
        max-width="6xl"
    >
        <div class="space-y-6">
            @if ($quickSummaryGroup)
                <section class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                        <div class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.quick_summary.summary.group') }}</div>
                                    <div class="mt-2 text-lg font-semibold text-white">{{ $quickSummaryGroup->name }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.quick_summary.summary.course') }}</div>
                                    <div class="mt-2 text-lg font-semibold text-white">{{ $quickSummaryGroup->course?->name ?: __('crud.common.not_available') }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.quick_summary.summary.teacher') }}</div>
                                    <div class="mt-2 text-lg font-semibold text-white">{{ $quickSummaryGroup->teacher ? $quickSummaryGroup->teacher->first_name.' '.$quickSummaryGroup->teacher->last_name : __('crud.common.not_available') }}</div>
                                </div>
                            </div>
                            <p class="text-sm leading-6 text-neutral-400">{{ __('crud.groups.quick_summary.copy_help') }}</p>
                        </div>

                        <div class="space-y-3">
                            <label for="group-quick-summary-date" class="mb-1 block text-sm font-medium">{{ __('crud.groups.quick_summary.fields.date') }}</label>
                            <input id="group-quick-summary-date" wire:model.live="quickSummaryDate" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                            @if ($quickSummaryRows->isNotEmpty())
                                <button type="button" wire:click="copyQuickSummary" class="pill-link pill-link--accent w-full justify-center">
                                    {{ __('crud.groups.quick_summary.copy_group_action') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </section>

                @if ($quickSummaryRows->isEmpty())
                    <div class="admin-empty-state">{{ __('crud.groups.quick_summary.empty') }}</div>
                @else
                    <div class="grid gap-4 xl:grid-cols-2">
                        @foreach ($quickSummaryRows as $row)
                            <article class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <div class="text-lg font-semibold text-white">{{ $row->student_name }}</div>
                                        <div class="mt-1 flex flex-wrap gap-2 text-xs text-neutral-400">
                                            @if ($row->student_number)
                                                <span class="badge-soft">{{ $row->student_number }}</span>
                                            @endif
                                            <span class="badge-soft">{{ $row->parent_name ?: __('crud.common.not_available') }}</span>
                                        </div>
                                    </div>
                                </div>

                                <dl class="mt-5 space-y-4">
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.quick_summary.labels.attendance') }}</dt>
                                        <dd class="mt-2 text-base font-medium text-white">{{ $row->attendance_label }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.quick_summary.labels.memorized') }}</dt>
                                        <dd class="mt-2 text-base font-medium text-white">{{ $row->memorized_label }}</dd>
                                    </div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </x-admin.modal>

    <x-admin.modal
        :show="$showRosterModal"
        :title="__('crud.groups.roster.title', ['group' => $rosterGroup?->name ?? ''])"
        :description="__('crud.groups.roster.help')"
        close-method="closeRosterModal"
        max-width="6xl"
    >
        <div class="space-y-6">
            @if ($rosterGroup)
                <section class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.roster.summary.group') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $rosterGroup->name }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.roster.summary.course') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $rosterGroup->course?->name ?: __('crud.common.not_available') }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.roster.summary.teacher') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $rosterGroup->teacher ? $rosterGroup->teacher->first_name.' '.$rosterGroup->teacher->last_name : __('crud.common.not_available') }}</div>
                        </div>
                    </div>
                </section>

                @can('enrollments.create')
                    <form wire:submit="addStudentToRoster" class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-white">{{ __('crud.groups.roster.add_title') }}</div>
                                <p class="mt-1 text-sm text-neutral-400">{{ __('crud.groups.roster.add_help') }}</p>
                            </div>
                            <button type="submit" class="pill-link pill-link--accent">{{ __('crud.groups.roster.add_submit') }}</button>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-[minmax(0,2fr)_220px]">
                            <div>
                                <label for="group-roster-student" class="mb-1 block text-sm font-medium">{{ __('crud.groups.roster.fields.student') }}</label>
                                <select id="group-roster-student" wire:model="roster_student_id" class="w-full rounded-xl px-4 py-3 text-sm">
                                    <option value="">{{ __('crud.groups.roster.placeholders.select_student') }}</option>
                                    @foreach ($availableRosterStudents as $student)
                                        <option
                                            value="{{ $student->id }}"
                                            data-search="{{ trim(implode(' ', array_filter([$student->student_number, $student->parentProfile?->father_name, $student->first_name, $student->last_name]))) }}"
                                        >
                                            {{ $student->first_name }} {{ $student->last_name }}
                                            @if ($student->parentProfile?->father_name)
                                                - {{ $student->parentProfile->father_name }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @if ($availableRosterStudents->isEmpty())
                                    <div class="mt-1 text-sm text-neutral-400">{{ __('crud.groups.roster.no_available_students') }}</div>
                                @endif
                                @error('roster_student_id')
                                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label for="group-roster-enrolled-at" class="mb-1 block text-sm font-medium">{{ __('crud.groups.roster.fields.enrolled_at') }}</label>
                                <input id="group-roster-enrolled-at" wire:model="roster_enrolled_at" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                                @error('roster_enrolled_at')
                                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </form>
                @endcan

                <section class="surface-table">
                    <div class="admin-grid-meta">
                        <div>
                            <div class="admin-grid-meta__title">{{ __('crud.groups.roster.table.title') }}</div>
                            <div class="admin-grid-meta__summary">{{ __('crud.groups.roster.table.summary', ['count' => number_format($rosterEnrollments->count())]) }}</div>
                        </div>
                        @if ($rosterGroup && $rosterEnrollments->isNotEmpty())
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('groups.roster.export', $rosterGroup) }}" class="pill-link pill-link--accent">
                                    {{ __('crud.groups.roster.download_action') }}
                                </a>
                                <a href="{{ route('groups.roster.pdf', $rosterGroup) }}" target="_blank" rel="noopener" class="pill-link">
                                    {{ __('crud.groups.roster.download_pdf_action') }}
                                </a>
                            </div>
                        @endif
                    </div>

                    @if ($rosterEnrollments->isEmpty())
                        <div class="admin-empty-state">{{ __('crud.groups.roster.table.empty') }}</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.student') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.student_number') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.student_phone') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.grade') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.parent_number') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.parent') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.parent_phone') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.enrolled_at') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.status') }}</th>
                                        @can('enrollments.delete')
                                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.groups.roster.table.headers.actions') }}</th>
                                        @endcan
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/6">
                                    @foreach ($rosterEnrollments as $enrollment)
                                        @php
                                            $rosterStatusClass = match ($enrollment->status) {
                                                'active' => 'status-chip status-chip--emerald',
                                                'completed' => 'status-chip status-chip--gold',
                                                default => 'status-chip status-chip--slate',
                                            };
                                        @endphp
                                        <tr>
                                            <td class="px-5 py-4 lg:px-6">
                                                @if ($enrollment->student)
                                                    <div class="student-inline">
                                                        <x-student-avatar :student="$enrollment->student" size="sm" />
                                                        <div class="student-inline__body">
                                                            <div class="student-inline__name">{{ $enrollment->student->first_name }} {{ $enrollment->student->last_name }}</div>
                                                        </div>
                                                    </div>
                                                @else
                                                    <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-4 font-mono text-neutral-300 lg:px-6">{{ $enrollment->student?->student_number ?: __('crud.common.not_available') }}</td>
                                            <td class="px-5 py-4 text-neutral-300 lg:px-6"><bdi dir="ltr" class="inline-block">{{ $enrollment->student?->user?->phone ?: __('crud.common.not_available') }}</bdi></td>
                                            <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->student?->gradeLevel?->name ?: __('crud.common.not_available') }}</td>
                                            <td class="px-5 py-4 font-mono text-neutral-300 lg:px-6">{{ $enrollment->student?->parentProfile?->parent_number ?: __('crud.common.not_available') }}</td>
                                            <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                                <div>{{ $enrollment->student?->parentProfile?->father_name ?: __('crud.common.not_available') }}</div>
                                                @if ($enrollment->student?->parentProfile?->mother_name)
                                                    <div class="mt-1 text-xs text-neutral-400">{{ $enrollment->student->parentProfile->mother_name }}</div>
                                                @endif
                                            </td>
                                            <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                                <bdi dir="ltr" class="inline-block">{{ $enrollment->student?->parentProfile?->father_phone ?: ($enrollment->student?->parentProfile?->mother_phone ?: ($enrollment->student?->parentProfile?->home_phone ?: __('crud.common.not_available'))) }}</bdi>
                                            </td>
                                            <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->enrolled_at?->format('d-m-Y') ?: __('crud.common.not_available') }}</td>
                                            <td class="px-5 py-4 lg:px-6"><span class="{{ $rosterStatusClass }}">{{ __('crud.common.status_options.'.$enrollment->status) }}</span></td>
                                            @can('enrollments.delete')
                                                <td class="px-5 py-4 lg:px-6">
                                                    <div class="flex justify-end">
                                                        <button
                                                            type="button"
                                                            wire:click="removeStudentFromRoster({{ $enrollment->id }})"
                                                            wire:confirm="{{ __('crud.common.confirm_delete.message') }}"
                                                            class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12"
                                                        >
                                                            {{ __('crud.groups.roster.remove_action') }}
                                                        </button>
                                                    </div>
                                                </td>
                                            @endcan
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </x-admin.modal>
</div>
