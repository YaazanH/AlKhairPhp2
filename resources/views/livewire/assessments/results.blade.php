<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\PointTransaction;
use App\Services\AssessmentService;
use App\Services\PointLedgerService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Assessment $currentAssessment;
    public array $result_scores = [];
    public array $result_statuses = [];
    public string $search = '';
    public string $resultStatusFilter = 'all';
    public ?int $selectedGroupId = null;
    public string $quick_enrollment_id = '';
    public string $quick_score = '';
    public string $sortField = 'student';
    public string $sortDirection = 'asc';

    protected array $sortableFields = [
        'score',
        'status',
        'student',
    ];

    public function mount(Assessment $assessment): void
    {
        $this->authorizePermission('assessment-results.view');

        $this->currentAssessment = Assessment::query()
            ->with(['group.course', 'groups.course', 'type'])
            ->findOrFail($assessment->id);

        $this->authorizeTeacherAssessmentAccess($this->currentAssessment);
        $groupIds = $this->assessmentGroupIds();

        if (count($groupIds) === 1) {
            $this->selectedGroupId = $groupIds[0];
        }

        $this->loadResults();
    }

    public function deleteAssessment(): void
    {
        $this->authorizePermission('assessments.delete');
        $assessment = $this->currentAssessment->fresh(['results.enrollment.student', 'groupDetails']);
        $this->authorizeTeacherAssessmentAccess($assessment);

        DB::transaction(function () use ($assessment): void {
            $ledger = app(PointLedgerService::class);
            $enrollments = $assessment->results->pluck('enrollment')->filter()->unique('id');
            foreach ($assessment->results as $result) {
                $ledger->voidSourceTransactions('assessment_result', $result->id, __('workflow.assessments.index.messages.deleted_void_reason'));
            }
            $assessment->results()->delete();
            $assessment->groupDetails()->delete();
            $assessment->delete();
            foreach ($enrollments as $enrollment) {
                if ($freshEnrollment = $enrollment->fresh(['student'])) {
                    $ledger->syncEnrollmentCaches($freshEnrollment);
                }
            }
        });

        session()->flash('status', __('workflow.assessments.index.messages.deleted'));
        $this->redirect(route('assessments.index'), navigate: true);
    }

    public function with(): array
    {
        $groupIds = $this->assessmentGroupIds();
        $assessmentGroups = $this->assessmentGroups();

        if ($this->selectedGroupId !== null && ! $assessmentGroups->contains('id', $this->selectedGroupId)) {
            $this->selectedGroupId = null;
        }

        if ($this->selectedGroupId === null && $assessmentGroups->count() === 1) {
            $this->selectedGroupId = (int) $assessmentGroups->first()->id;
        }

        $selectedGroup = $this->selectedGroupId
            ? $assessmentGroups->firstWhere('id', $this->selectedGroupId)
            : null;
        $enrollments = collect();

        if ($selectedGroup) {
            $enrollmentsQuery = Enrollment::query()
                ->with(['student', 'assessmentResults' => fn ($query) => $query->where('assessment_id', $this->currentAssessment->id)])
                ->where('group_id', $selectedGroup->id)
                ->where('status', 'active')
                ->when(filled($this->search), function ($query) {
                    $query->whereHas('student', function ($studentQuery) {
                        $studentQuery
                            ->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%')
                            ->orWhere('student_number', 'like', '%'.$this->search.'%');
                    });
                })
                ->when($this->resultStatusFilter !== 'all', function ($query) {
                    if ($this->resultStatusFilter === 'pending') {
                        $query->where(function ($builder) {
                            $builder
                                ->whereDoesntHave('assessmentResults', fn ($resultQuery) => $resultQuery->where('assessment_id', $this->currentAssessment->id))
                                ->orWhereHas('assessmentResults', fn ($resultQuery) => $resultQuery
                                    ->where('assessment_id', $this->currentAssessment->id)
                                    ->where('status', 'pending'));
                        });

                        return;
                    }

                    $query->whereHas('assessmentResults', fn ($resultQuery) => $resultQuery
                        ->where('assessment_id', $this->currentAssessment->id)
                        ->where('status', $this->resultStatusFilter));
                })
                ->orderBy('enrolled_at');
            $enrollments = $this->sortedEnrollments($enrollmentsQuery->get());
        }

        return [
            'assessmentRecord' => $this->currentAssessment->fresh(['group.course', 'groups.course', 'type']),
            'assessmentGroups' => $assessmentGroups,
            'assessmentGroupCount' => $assessmentGroups->count(),
            'assessmentPointsByEnrollment' => $this->assessmentPointsByEnrollment(),
            'enrollments' => $enrollments,
            'quickEntryEnrollments' => Enrollment::query()
                ->with(['student', 'group.course'])
                ->whereIn('group_id', $assessmentGroups->pluck('id'))
                ->where('status', 'active')
                ->get()
                ->sortBy(fn (Enrollment $enrollment) => mb_strtolower((string) ($enrollment->student?->full_name ?? '')))
                ->values(),
            'selectedGroup' => $selectedGroup,
            'totalActiveEnrollments' => $assessmentGroups->sum('active_enrollments_count'),
            'totalSavedResults' => $assessmentGroups->sum('assessment_results_count'),
        ];
    }

    public function selectGroup(int $groupId): void
    {
        if (! in_array($groupId, $this->assessmentGroupIds(), true)) {
            abort(404);
        }

        $group = Group::query()->findOrFail($groupId);
        $this->authorizeTeacherGroupAccess($group);

        $this->selectedGroupId = $groupId;
        $this->search = '';
        $this->resultStatusFilter = 'all';
        $this->resetValidation();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = $field === 'student' ? 'asc' : 'desc';
        }
    }

    public function saveResults(): void
    {
        $this->authorizePermission('assessment-results.record');
        $this->authorizeTeacherAssessmentAccess($this->currentAssessment);

        if (! $this->selectedGroupId || ! in_array($this->selectedGroupId, $this->assessmentGroupIds(), true)) {
            $this->addError('selectedGroupId', __('workflow.assessments.results.errors.select_group'));

            return;
        }

        $selectedGroup = Group::query()->findOrFail($this->selectedGroupId);
        $this->authorizeTeacherGroupAccess($selectedGroup);

        $maxMark = $this->currentAssessment->total_mark !== null ? (float) $this->currentAssessment->total_mark : 100;

        $validated = $this->validate([
            'result_scores' => ['array'],
            'result_scores.*' => ['nullable', 'numeric', 'min:0', 'max:'.$maxMark],
        ]);

        $teacherId = auth()->user()?->teacherProfile?->id ?: $this->currentAssessment->group?->teacher_id;
        $teacherId = $teacherId ?: $selectedGroup->teacher_id;
        $service = app(AssessmentService::class);

        $enrollments = Enrollment::query()
            ->where('group_id', $selectedGroup->id)
            ->where('status', 'active')
            ->get();

        foreach ($enrollments as $enrollment) {
            $score = $validated['result_scores'][$enrollment->id] ?? null;
            $didNotAttend = $score === null || $score === '';
            $numericScore = $didNotAttend ? 0.0 : (float) $score;
            $status = $didNotAttend ? 'absent' : $this->statusForScore($numericScore);

            $result = AssessmentResult::query()->updateOrCreate(
                [
                    'assessment_id' => $this->currentAssessment->id,
                    'enrollment_id' => $enrollment->id,
                ],
                [
                    'student_id' => $enrollment->student_id,
                    'teacher_id' => $teacherId,
                    'score' => $numericScore,
                    'status' => $status,
                    'attempt_no' => 1,
                    'notes' => null,
                ],
            );

            $service->syncResultPoints($result->fresh(['assessment.type', 'enrollment.student']));
        }

        $this->loadResults();
        session()->flash('status', __('workflow.assessments.results.messages.saved'));
    }

    public function saveEnrollmentResult(int $enrollmentId): void
    {
        $this->authorizePermission('assessment-results.record');
        $this->authorizeTeacherAssessmentAccess($this->currentAssessment);

        if (! $this->selectedGroupId || ! in_array($this->selectedGroupId, $this->assessmentGroupIds(), true)) {
            $this->addError('selectedGroupId', __('workflow.assessments.results.errors.select_group'));

            return;
        }

        $maxMark = $this->currentAssessment->total_mark !== null ? (float) $this->currentAssessment->total_mark : 100;
        $validated = $this->validate([
            'result_scores.'.$enrollmentId => ['required', 'numeric', 'min:0', 'max:'.$maxMark],
        ]);

        $enrollment = Enrollment::query()
            ->where('group_id', $this->selectedGroupId)
            ->where('status', 'active')
            ->findOrFail($enrollmentId);
        $selectedGroup = Group::query()->findOrFail($this->selectedGroupId);
        $this->authorizeTeacherGroupAccess($selectedGroup);

        $teacherId = auth()->user()?->teacherProfile?->id ?: $this->currentAssessment->group?->teacher_id;
        $teacherId = $teacherId ?: $selectedGroup->teacher_id;
        $numericScore = (float) $validated['result_scores'][$enrollmentId];

        $result = AssessmentResult::query()->updateOrCreate(
            [
                'assessment_id' => $this->currentAssessment->id,
                'enrollment_id' => $enrollment->id,
            ],
            [
                'student_id' => $enrollment->student_id,
                'teacher_id' => $teacherId,
                'score' => $numericScore,
                'status' => $this->statusForScore($numericScore),
                'attempt_no' => 1,
                'notes' => null,
            ],
        );

        app(AssessmentService::class)->syncResultPoints($result->fresh(['assessment.type', 'enrollment.student']));

        $this->loadResults();
        session()->flash('status', __('workflow.assessments.results.messages.quick_saved'));
    }

    public function updatedQuickEnrollmentId($enrollmentId): void
    {
        $this->quick_score = '';
        $this->resetValidation(['quick_enrollment_id', 'quick_score']);

        if (blank($enrollmentId)) {
            return;
        }

        $enrollment = Enrollment::query()
            ->whereIn('group_id', $this->assessmentGroups()->pluck('id'))
            ->where('status', 'active')
            ->find($enrollmentId);

        if (! $enrollment) {
            return;
        }

        $result = AssessmentResult::query()
            ->where('assessment_id', $this->currentAssessment->id)
            ->where('enrollment_id', $enrollment->id)
            ->first();

        if ($result) {
            $this->quick_score = $result->score !== null ? number_format((float) $result->score, 2, '.', '') : '';
        }
    }

    public function saveQuickResult(): void
    {
        $this->authorizePermission('assessment-results.record');
        $this->authorizeTeacherAssessmentAccess($this->currentAssessment);

        $maxMark = $this->currentAssessment->total_mark !== null ? (float) $this->currentAssessment->total_mark : 100;
        $validated = $this->validate([
            'quick_enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'quick_score' => ['required', 'numeric', 'min:0', 'max:'.$maxMark],
        ]);

        $enrollment = Enrollment::query()
            ->with('group')
            ->whereIn('group_id', $this->assessmentGroups()->pluck('id'))
            ->where('status', 'active')
            ->findOrFail((int) $validated['quick_enrollment_id']);
        $this->authorizeTeacherGroupAccess($enrollment->group);

        $teacherId = auth()->user()?->teacherProfile?->id ?: $enrollment->group?->teacher_id;
        $numericScore = (float) $validated['quick_score'];
        $result = AssessmentResult::query()->updateOrCreate(
            [
                'assessment_id' => $this->currentAssessment->id,
                'enrollment_id' => $enrollment->id,
            ],
            [
                'student_id' => $enrollment->student_id,
                'teacher_id' => $teacherId,
                'score' => $numericScore,
                'status' => $this->statusForScore($numericScore),
                'attempt_no' => 1,
                'notes' => null,
            ],
        );

        app(AssessmentService::class)->syncResultPoints($result->fresh(['assessment.type', 'enrollment.student']));

        $this->quick_enrollment_id = '';
        $this->quick_score = '';
        $this->loadResults();
        session()->flash('status', __('workflow.assessments.results.messages.quick_saved'));
    }

    protected function loadResults(): void
    {
        $results = AssessmentResult::query()
            ->where('assessment_id', $this->currentAssessment->id)
            ->get();

        $this->result_scores = $results->mapWithKeys(fn (AssessmentResult $result) => [$result->enrollment_id => $result->score !== null ? number_format((float) $result->score, 2, '.', '') : ''])->toArray();
        $this->result_statuses = $results->mapWithKeys(fn (AssessmentResult $result) => [$result->enrollment_id => $result->status])->toArray();
    }

    public function displayStatusForEnrollment(int $enrollmentId): string
    {
        $score = $this->result_scores[$enrollmentId] ?? null;

        if ($score !== null && $score !== '') {
            return $this->statusForScore((float) $score);
        }

        return $this->result_statuses[$enrollmentId] ?? 'absent';
    }

    public function resultStatusClass(string $status): string
    {
        return match ($status) {
            'passed' => 'status-chip status-chip--emerald',
            'failed' => 'status-chip status-chip--rose',
            'absent' => 'status-chip status-chip--gold',
            default => 'status-chip status-chip--slate',
        };
    }

    protected function assessmentGroups()
    {
        $groupIds = $this->assessmentGroupIds();

        if ($groupIds === []) {
            return collect();
        }

        $assessmentId = $this->currentAssessment->id;

        return $this->scopeGroupsQuery(
            Group::query()
                ->with(['course', 'teacher'])
                ->withCount([
                    'enrollments as active_enrollments_count' => fn ($query) => $query->where('status', 'active'),
                    'enrollments as assessment_results_count' => fn ($query) => $query
                        ->where('status', 'active')
                        ->whereHas('assessmentResults', fn ($resultQuery) => $resultQuery->where('assessment_id', $assessmentId)),
                ])
                ->whereIn('id', $groupIds)
        )
            ->orderBy('name')
            ->get();
    }

    protected function assessmentGroupIds(): array
    {
        $this->currentAssessment->loadMissing('groups');

        $groupIds = $this->currentAssessment->groups
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($groupIds === [] && $this->currentAssessment->group_id) {
            $groupIds[] = (int) $this->currentAssessment->group_id;
        }

        return $groupIds;
    }

    protected function assessmentPointsByEnrollment(): array
    {
        $resultIdsByEnrollment = AssessmentResult::query()
            ->where('assessment_id', $this->currentAssessment->id)
            ->pluck('id', 'enrollment_id');

        if ($resultIdsByEnrollment->isEmpty()) {
            return [];
        }

        $pointTotals = PointTransaction::query()
            ->where('source_type', 'assessment_result')
            ->whereIn('source_id', $resultIdsByEnrollment->values())
            ->effectiveActive()
            ->selectRaw('source_id, sum(points) as points')
            ->groupBy('source_id')
            ->pluck('points', 'source_id');

        return $resultIdsByEnrollment
            ->mapWithKeys(fn ($resultId, $enrollmentId) => [(int) $enrollmentId => (int) ($pointTotals[$resultId] ?? 0)])
            ->all();
    }

    protected function statusForScore(?float $score): string
    {
        return app(AssessmentService::class)->statusForScore($this->currentAssessment, $score);
    }

    protected function sortedEnrollments($enrollments)
    {
        $field = in_array($this->sortField, $this->sortableFields, true)
            ? $this->sortField
            : 'student';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return $enrollments
            ->sort(function (Enrollment $left, Enrollment $right) use ($field, $direction): int {
                $leftResult = $left->assessmentResults->first();
                $rightResult = $right->assessmentResults->first();

                $comparison = match ($field) {
                    'score' => (float) ($leftResult?->score ?? -1) <=> (float) ($rightResult?->score ?? -1),
                    'status' => strnatcasecmp($this->displayStatusForEnrollment($left->id), $this->displayStatusForEnrollment($right->id)),
                    default => strnatcasecmp((string) ($left->student?->full_name ?? ''), (string) ($right->student?->full_name ?? '')),
                };

                if ($comparison === 0) {
                    $comparison = strnatcasecmp((string) ($left->student?->full_name ?? ''), (string) ($right->student?->full_name ?? ''));
                }

                return $direction === 'desc' ? -$comparison : $comparison;
            })
            ->values();
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('assessments.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('workflow.common.back_to_assessments') }}</a>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ $assessmentRecord->title }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.assessments.results.subtitle') }}</p>
            </div>

            <div class="surface-panel px-5 py-4">
                <div class="text-sm font-semibold text-white">{{ $assessmentRecord->title }}</div>
                <div class="mt-1 text-sm text-neutral-400">
                    {{ $assessmentRecord->type?->name ?: __('workflow.common.not_available') }} |
                    {{ $assessmentGroups->isNotEmpty()
                        ? $assessmentGroups->pluck('name')->implode(', ')
                        : ($assessmentRecord->group?->name ?: __('workflow.common.not_available')) }}
                </div>
                <div class="mt-1 text-sm text-neutral-400">{{ __('workflow.common.labels.total', ['value' => $assessmentRecord->total_mark !== null ? number_format((float) $assessmentRecord->total_mark, 2) : __('workflow.common.not_available')]) }} | {{ __('workflow.common.labels.pass', ['value' => $assessmentRecord->pass_mark !== null ? number_format((float) $assessmentRecord->pass_mark, 2) : __('workflow.common.not_available')]) }}</div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('assessments.results.pdf', $assessmentRecord) }}" target="_blank" rel="noopener" class="pill-link pill-link--compact">{{ __('workflow.assessments.results.pdf_export') }}</a>
                    @can('assessments.update')<a href="{{ route('assessments.index', ['edit' => $assessmentRecord->id]) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</a>@endcan
                    @can('assessments.delete')<button type="button" wire:click="deleteAssessment" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('crud.common.actions.delete') }}</button>@endcan
                </div>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @can('assessment-results.record')
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('workflow.assessments.results.student_entry.title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('workflow.assessments.results.student_entry.help') }}</p>
                </div>
                <span class="badge-soft badge-soft--emerald">{{ __('workflow.assessments.results.student_entry.all_groups') }}</span>
            </div>

            <form wire:submit="saveQuickResult" class="mt-5 grid gap-4 lg:grid-cols-[minmax(18rem,1fr)_10rem_auto] lg:items-end" data-searchable-refresh>
                <div>
                    <label for="assessment-student-entry" class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.results.quick_entry.student') }}</label>
                    <select
                        id="assessment-student-entry"
                        wire:model.live="quick_enrollment_id"
                        class="searchable-select w-full rounded-xl px-4 py-3 text-sm"
                        data-search-placeholder="{{ __('workflow.assessments.results.student_entry.search_placeholder') }}"
                    >
                        <option value="">{{ __('workflow.assessments.results.quick_entry.select_student') }}</option>
                        @foreach ($quickEntryEnrollments as $enrollment)
                            <option
                                value="{{ $enrollment->id }}"
                                data-search="{{ trim(implode(' ', array_filter([$enrollment->student?->student_number, $enrollment->student?->first_name, $enrollment->student?->last_name, $enrollment->group?->name, $enrollment->group?->course?->name]))) }}"
                            >
                                {{ $enrollment->student?->full_name }}
                                · {{ $enrollment->group?->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('quick_enrollment_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label for="assessment-student-score" class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.results.quick_entry.score') }}</label>
                    <input id="assessment-student-score" wire:model="quick_score" type="number" min="0" max="{{ $assessmentRecord->total_mark !== null ? (float) $assessmentRecord->total_mark : 100 }}" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('quick_score') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <button type="submit" class="pill-link pill-link--accent justify-center lg:mb-px">
                    {{ __('workflow.assessments.results.quick_entry.save') }}
                </button>
            </form>
        </section>
    @endcan

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.results.table.headers.student') }}</div>
            <div class="metric-value mt-3">{{ number_format($totalActiveEnrollments) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.index.table.headers.results') }}</div>
            <div class="metric-value mt-3">{{ number_format($totalSavedResults) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.results.stats.groups') }}</div>
            <div class="metric-value mt-3">{{ number_format($assessmentGroupCount) }}</div>
        </article>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.assessments.results.groups.choose_title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.assessments.results.groups.choose_help') }}</p>
            </div>
        </div>

        @if ($assessmentGroups->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.assessments.results.groups.empty') }}</div>
        @else
            <div class="mt-5 flex flex-wrap gap-3">
                @foreach ($assessmentGroups as $group)
                    <button
                        type="button"
                        wire:click="selectGroup({{ $group->id }})"
                        class="{{ (int) $selectedGroupId === (int) $group->id ? 'pill-link pill-link--accent' : 'pill-link' }}"
                    >
                        <span>{{ $group->name }}</span>
                        <span class="opacity-70">{{ $group->assessment_results_count }}/{{ $group->active_enrollments_count }}</span>
                    </button>
                @endforeach
            </div>

            @if ($selectedGroup)
                <div class="mt-5 flex flex-wrap gap-3 border-t border-white/10 pt-5">
                    <span class="badge-soft badge-soft--emerald">{{ $selectedGroup->course?->name ?: __('workflow.common.no_course') }}</span>
                    <span class="badge-soft">{{ $selectedGroup->teacher ? $selectedGroup->teacher->first_name.' '.$selectedGroup->teacher->last_name : __('workflow.common.no_teacher_assigned') }}</span>
                    <span class="badge-soft">{{ __('workflow.assessments.results.groups.progress', ['saved' => number_format((int) $selectedGroup->assessment_results_count), 'total' => number_format((int) $selectedGroup->active_enrollments_count)]) }}</span>
                </div>
            @endif
        @endif
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">
                    {{ __('workflow.assessments.results.table.title') }}
                    @if ($selectedGroup)
                        <span class="text-neutral-400">| {{ $selectedGroup->name }}</span>
                    @endif
                </div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($enrollments->count())]) }}</div>
            </div>
            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="assessment-result-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="assessment-result-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('workflow.assessments.results.filters.search_placeholder') }}">
                </div>
                <div class="admin-filter-field">
                    <label for="assessment-result-status-filter">{{ __('workflow.assessments.results.filters.status') }}</label>
                    <select id="assessment-result-status-filter" wire:model.live="resultStatusFilter">
                        <option value="all">{{ __('workflow.assessments.results.filters.all_statuses') }}</option>
                        <option value="absent">{{ __('workflow.common.result_status.absent') }}</option>
                        <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                        <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                        <option value="absent">{{ __('workflow.common.result_status.absent') }}</option>
                    </select>
                </div>
            </div>
        </div>

        @if (! $selectedGroup)
            @error('selectedGroupId')
                <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div>
            @enderror
            <div class="admin-empty-state">{{ __('workflow.assessments.results.groups.select_first') }}</div>
        @else
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left font-medium">
                            <button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                {{ __('workflow.assessments.results.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-medium">
                            <button type="button" wire:click="sortBy('score')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                {{ __('workflow.assessments.results.table.headers.score') }} <span>{{ $this->sortIndicator('score') }}</span>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-medium">
                            <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                {{ __('workflow.assessments.results.table.headers.status') }} <span>{{ $this->sortIndicator('status') }}</span>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.results.table.headers.cached_points') }}</th>
                        @can('assessment-results.record')
                            <th class="px-5 py-3 text-right font-medium">{{ __('workflow.assessments.results.table.headers.actions') }}</th>
                        @endcan
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($enrollments as $enrollment)
                        @php
                            $displayStatus = $this->displayStatusForEnrollment($enrollment->id);
                        @endphp
                        <tr>
                            <td class="px-5 py-3">
                                <div class="student-inline">
                                    <x-student-avatar :student="$enrollment->student" size="sm" />
                                    <div class="student-inline__body">
                                        <div class="student-inline__name">{{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }}</div>
                                        <div class="student-inline__meta">{{ $enrollment->student?->school_name ?: __('workflow.common.no_school_recorded') }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <input wire:model.live.debounce.300ms="result_scores.{{ $enrollment->id }}" wire:keydown.enter="saveEnrollmentResult({{ $enrollment->id }})" type="number" min="0" max="{{ $assessmentRecord->total_mark !== null ? (float) $assessmentRecord->total_mark : 100 }}" step="0.01" placeholder="0" class="w-28 rounded-xl px-3 py-2 text-sm">
                                @error('result_scores.'.$enrollment->id) <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $this->resultStatusClass($displayStatus) }}">
                                    {{ __('workflow.common.result_status.'.$displayStatus) }}
                                </span>
                            </td>
                            <td class="px-5 py-3"><span class="status-chip status-chip--slate">{{ $assessmentPointsByEnrollment[$enrollment->id] ?? 0 }}</span></td>
                            @can('assessment-results.record')
                                <td class="px-5 py-3 text-right">
                                    <button type="button" wire:click="saveEnrollmentResult({{ $enrollment->id }})" class="pill-link pill-link--compact">
                                        {{ __('workflow.assessments.results.quick_entry.save') }}
                                    </button>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('workflow.assessments.results.table.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif
    </section>

    @if ($selectedGroup)
    @can('assessment-results.record')
        <div class="admin-action-cluster admin-action-cluster--end">
            <button wire:click="saveResults" type="button" class="pill-link pill-link--accent">
                {{ __('workflow.common.actions.save_assessment_results') }}
            </button>
        </div>
    @endcan
    @endif

</div>
