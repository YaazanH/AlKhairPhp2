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

new class extends Component
{
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

    public bool $showQuickResultModal = false;

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
            ->whereNull('course_finished_at')
            ->whereDoesntHave('group.course', fn ($courseQuery) => $courseQuery->whereNotNull('finished_at'))
            ->whereDoesntHave('groups.course', fn ($courseQuery) => $courseQuery->whereNotNull('finished_at'))
            ->findOrFail($assessment->id);

        $this->authorizeTeacherAssessmentAccess($this->currentAssessment);
        $groupIds = $this->assessmentGroupIds();

        if (count($groupIds) === 1) {
            $this->selectedGroupId = $groupIds[0];
        }

        $this->loadResults();
    }

    public function with(): array
    {
        $groupIds = $this->assessmentGroupIds();
        $assessmentGroups = $this->assessmentGroups();
        $availableGroupIds = $this->scopeGroupsQuery(
            Group::query()->where('is_active', true)->whereHas('course', fn ($query) => $query->where('is_active', true))
        )->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $assessmentGroupIds = $assessmentGroups->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

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

        $quickEntryEnrollments = Enrollment::query()
            ->with(['student', 'group.course'])
            ->whereIn('group_id', $assessmentGroups->pluck('id'))
            ->where('status', 'active')
            ->get()
            ->sortBy(fn (Enrollment $enrollment) => mb_strtolower((string) ($enrollment->student?->full_name ?? '')))
            ->values();

        return [
            'assessmentRecord' => $this->currentAssessment->fresh(['group.course', 'groups.course', 'type']),
            'assessmentGroups' => $assessmentGroups,
            'usesAllGroups' => $this->currentAssessment->group_scope === 'all'
                || ($this->currentAssessment->group_scope === null && $availableGroupIds->isNotEmpty() && $assessmentGroupIds->all() === $availableGroupIds->all()),
            'assessmentPointsByEnrollment' => $this->assessmentPointsByEnrollment(),
            'enrollments' => $enrollments,
            'quickEntryEnrollments' => $quickEntryEnrollments,
            'quickSelectedEnrollment' => filled($this->quick_enrollment_id)
                ? $quickEntryEnrollments->firstWhere('id', (int) $this->quick_enrollment_id)
                : null,
            'selectedGroup' => $selectedGroup,
            'assessmentAverage' => AssessmentResult::query()
                ->where('assessment_id', $this->currentAssessment->id)
                ->whereNotNull('score')
                ->avg('score'),
            'totalSavedResults' => $assessmentGroups->sum('assessment_results_count'),
            'totalPassedStudents' => $assessmentGroups->sum('assessment_passed_count'),
            'canRecordAssessmentScores' => $this->canPermission('assessment-results.record')
                && $this->canPermission('assessment-results.record-scores'),
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
        $this->authorizeScoreRecording();
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
        $this->authorizeScoreRecording();
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

    public function openQuickResultModal(): void
    {
        $this->authorizeScoreRecording();
        $this->quick_enrollment_id = '';
        $this->quick_score = '';
        $this->showQuickResultModal = true;
        $this->resetValidation(['quick_enrollment_id', 'quick_score']);
    }

    public function closeQuickResultModal(): void
    {
        $this->showQuickResultModal = false;
        $this->quick_enrollment_id = '';
        $this->quick_score = '';
        $this->resetValidation(['quick_enrollment_id', 'quick_score']);
    }

    public function saveQuickResult(): void
    {
        $this->authorizeScoreRecording();
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

        if ($numericScore === 0.0) {
            $result = AssessmentResult::query()
                ->where('assessment_id', $this->currentAssessment->id)
                ->where('enrollment_id', $enrollment->id)
                ->first();

            if ($result) {
                DB::transaction(function () use ($result, $enrollment): void {
                    $ledger = app(PointLedgerService::class);
                    $ledger->voidSourceTransactions('assessment_result', $result->id, __('workflow.assessments.results.messages.score_removed'));
                    $result->delete();
                    $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
                });
            }

            $this->quick_enrollment_id = '';
            $this->quick_score = '';
            $this->loadResults();
            $this->dispatch('assessment-quick-score-saved');
            $this->js('window.scheduleAssessmentQuickScoreStudentFocus?.()');
            session()->flash('status', __('workflow.assessments.results.messages.score_removed'));

            return;
        }

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
        $this->dispatch('assessment-quick-score-saved');
        $this->js('window.scheduleAssessmentQuickScoreStudentFocus?.()');
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
            'absent' => 'status-chip status-chip--amber',
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
                        ->whereHas('assessmentResults', fn ($resultQuery) => $resultQuery
                            ->where('assessment_id', $assessmentId)
                            ->whereIn('status', ['passed', 'failed'])),
                    'enrollments as assessment_passed_count' => fn ($query) => $query
                        ->where('status', 'active')
                        ->whereHas('assessmentResults', fn ($resultQuery) => $resultQuery
                            ->where('assessment_id', $assessmentId)
                            ->where('status', 'passed')),
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

    protected function authorizeScoreRecording(): void
    {
        $this->authorizePermission('assessment-results.record');
        $this->authorizePermission('assessment-results.record-scores');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <x-back-link :href="route('assessments.index')" navigate class="assessment-results-back" />
                <h1 class="assessment-results-title font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ $assessmentRecord->title }}</h1>
            </div>

            <div class="surface-panel px-5 py-4">
                <div class="text-sm font-semibold text-white">{{ $assessmentRecord->title }}</div>
                <div class="mt-1 text-sm text-neutral-400">
                    {{ $assessmentRecord->type?->name ?: __('workflow.common.not_available') }} |
                    {{ $assessmentGroups->isNotEmpty()
                        ? ($usesAllGroups ? __('crud.common.filters.all_groups') : $assessmentGroups->pluck('name')->implode(', '))
                        : ($assessmentRecord->group?->name ?: __('workflow.common.not_available')) }}
                </div>
                <div class="mt-1 text-sm text-neutral-400">{{ __('workflow.assessments.results.details.participants') }}: {{ number_format($totalSavedResults) }} | {{ __('workflow.assessments.results.details.passed') }}: {{ number_format($totalPassedStudents) }}</div>
                <div class="assessment-results-card-actions mt-3">
                    @can('assessments.update')<x-edit-action-button :href="route('assessments.index', ['edit' => $assessmentRecord->id, 'return_to' => 'results'])" wire:navigate :label="__('crud.common.actions.edit')" data-assessment-edit-action />@endcan
                    <a href="{{ route('assessments.results.pdf', $assessmentRecord) }}" target="_blank" rel="noopener" class="admin-icon-button" title="{{ __('workflow.assessments.results.pdf_export') }}" aria-label="{{ __('workflow.assessments.results.pdf_export') }}"><x-pdf-export-icon /></a>
                    @if($canRecordAssessmentScores)<button type="button" wire:click="openQuickResultModal" class="admin-icon-button admin-icon-button--accent assessment-results-card-actions__add" title="{{ __('workflow.assessments.results.student_entry.title') }}" aria-label="{{ __('workflow.assessments.results.student_entry.title') }}" data-assessment-add-result-action><x-admin-action-icon name="add" /></button>@endif
                </div>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        @if ($assessmentGroups->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.assessments.results.groups.empty') }}</div>
        @else
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(min(100%,14rem),1fr))]">
                @foreach ($assessmentGroups as $group)
                    <button
                        type="button"
                        wire:click="selectGroup({{ $group->id }})"
                        aria-pressed="{{ (int) $selectedGroupId === (int) $group->id ? 'true' : 'false' }}"
                        class="assessment-group-selector flex w-full flex-col items-start rounded-2xl border px-4 py-3 text-start transition {{ (int) $selectedGroupId === (int) $group->id ? 'border-emerald-400/45 bg-emerald-500/15 text-white shadow-lg shadow-emerald-950/20' : 'border-white/10 bg-white/4 text-neutral-200 hover:border-white/20 hover:bg-white/7' }}"
                    >
                        <span class="font-semibold">{{ $group->name }}</span>
                        <span class="mt-1 text-xs text-neutral-400">{{ number_format($group->assessment_results_count) }}</span>
                    </button>
                @endforeach
            </div>

        @endif
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls assessment-results-toolbar">
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
                    <label class="sr-only" for="assessment-result-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="assessment-result-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('workflow.assessments.results.filters.search_placeholder') }}">
                </div>
                <div class="admin-filter-field">
                    <label class="sr-only" for="assessment-result-status-filter">{{ __('workflow.assessments.results.filters.status') }}</label>
                    <select id="assessment-result-status-filter" wire:model.live="resultStatusFilter">
                        <option value="all">{{ __('workflow.assessments.results.filters.all_statuses') }}</option>
                        <option value="absent">{{ __('workflow.common.result_status.absent') }}</option>
                        <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                        <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                    </select>
                </div>
                @if ($selectedGroup)
                    <a href="{{ route('assessments.results.pdf', ['assessment' => $assessmentRecord, 'group_id' => $selectedGroup->id]) }}" target="_blank" rel="noopener" class="admin-icon-button assessment-results-filter-pdf-button" title="{{ __('workflow.assessments.results.pdf_export') }}" aria-label="{{ __('workflow.assessments.results.pdf_export') }}"><x-pdf-export-icon /></a>
                @endif
            </div>
        </div>

        @if (! $selectedGroup)
            @error('selectedGroupId')
                <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div>
            @enderror
            <div class="admin-empty-state">{{ __('workflow.assessments.results.groups.select_first') }}</div>
        @else
        @php($assessmentResultRowNumbers = $enrollments->values()->mapWithKeys(fn ($enrollment, $index) => [$enrollment->id => $index + 1]))
        @php($assessmentResultsUseTwoColumns = $enrollments->count() > 5)
        @php($assessmentResultColumns = $enrollments->isEmpty() ? collect([collect()]) : $enrollments->chunk((int) ceil($enrollments->count() / 2)))
        <div class="assessment-results-single {{ $assessmentResultsUseTwoColumns ? '' : 'assessment-results-single--full' }}">
            <table class="assessment-results-data-table w-full table-fixed text-sm">
                <thead>
                    <tr>
                        <th class="px-2 py-2 text-center font-medium">#</th>
                        <th class="px-3 py-2 text-left font-medium"><button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-1 font-medium text-inherit">{{ __('workflow.assessments.results.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span></button></th>
                        <th class="px-3 py-2 text-left font-medium"><button type="button" wire:click="sortBy('score')" class="inline-flex items-center gap-1 font-medium text-inherit">{{ __('workflow.assessments.results.table.headers.score') }} <span>{{ $this->sortIndicator('score') }}</span></button></th>
                        <th class="px-3 py-2 text-left font-medium"><button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-1 font-medium text-inherit">{{ __('workflow.assessments.results.table.headers.status') }} <span>{{ $this->sortIndicator('status') }}</span></button></th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('workflow.assessments.results.table.headers.cached_points') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($enrollments as $enrollment)
                        @php($displayStatus = $this->displayStatusForEnrollment($enrollment->id))
                        <tr>
                            <td class="px-2 py-2 text-center text-neutral-400">{{ $assessmentResultRowNumbers[$enrollment->id] }}</td>
                            <td class="px-3 py-2"><div class="student-inline__name">{{ $enrollment->student?->full_name }}</div></td>
                            <td class="px-3 py-2">{{ ($result = $enrollment->assessmentResults->first()) ? number_format((float) $result->score, 2) : '—' }}</td>
                            <td class="px-3 py-2"><span class="assessment-result-status-chip {{ $this->resultStatusClass($displayStatus) }}">{{ __('workflow.common.result_status.'.$displayStatus) }}</span></td>
                            <td class="px-3 py-2"><span class="status-chip status-chip--slate">{{ $assessmentPointsByEnrollment[$enrollment->id] ?? 0 }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-sm text-neutral-500">{{ __('workflow.assessments.results.table.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="assessment-results-dual {{ $assessmentResultsUseTwoColumns ? '' : 'assessment-results-dual--inactive' }}">
            @foreach ($assessmentResultColumns as $columnEnrollments)
                <div class="assessment-results-table-wrap">
                    <table class="assessment-results-data-table w-full table-fixed text-sm">
                        <thead>
                            <tr>
                                <th class="px-2 py-2 text-center font-medium">#</th>
                                <th class="px-3 py-2 text-left font-medium"><button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-1 font-medium text-inherit">{{ __('workflow.assessments.results.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span></button></th>
                                <th class="px-3 py-2 text-left font-medium"><button type="button" wire:click="sortBy('score')" class="inline-flex items-center gap-1 font-medium text-inherit">{{ __('workflow.assessments.results.table.headers.score') }} <span>{{ $this->sortIndicator('score') }}</span></button></th>
                                <th class="px-3 py-2 text-left font-medium"><button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-1 font-medium text-inherit">{{ __('workflow.assessments.results.table.headers.status') }} <span>{{ $this->sortIndicator('status') }}</span></button></th>
                                <th class="px-3 py-2 text-left font-medium">{{ __('workflow.assessments.results.table.headers.cached_points') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse ($columnEnrollments as $enrollment)
                                @php($displayStatus = $this->displayStatusForEnrollment($enrollment->id))
                                <tr>
                                    <td class="px-2 py-2 text-center text-neutral-400">{{ $assessmentResultRowNumbers[$enrollment->id] }}</td>
                                    <td class="px-3 py-2"><div class="student-inline__name">{{ $enrollment->student?->full_name }}</div></td>
                                    <td class="px-3 py-2">{{ ($result = $enrollment->assessmentResults->first()) ? number_format((float) $result->score, 2) : '—' }}</td>
                                    <td class="px-3 py-2"><span class="assessment-result-status-chip {{ $this->resultStatusClass($displayStatus) }}">{{ __('workflow.common.result_status.'.$displayStatus) }}</span></td>
                                    <td class="px-3 py-2"><span class="status-chip status-chip--slate">{{ $assessmentPointsByEnrollment[$enrollment->id] ?? 0 }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-8 text-center text-sm text-neutral-500">{{ __('workflow.assessments.results.table.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
        @endif
    </section>

    @if($canRecordAssessmentScores)
        <x-admin.modal
            :show="$showQuickResultModal"
            :title="__('workflow.assessments.results.student_entry.title')"
            close-method="closeQuickResultModal"
            max-width="xl"
            compact
        >
            <form
                wire:submit="saveQuickResult"
                class="space-y-4"
                data-searchable-refresh
                data-assessment-quick-score-form
            >
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-end">
                    <div>
                        <label for="assessment-student-entry" class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.results.quick_entry.student') }}</label>
                        <select
                            id="assessment-student-entry"
                            wire:model.live="quick_enrollment_id"
                            class="searchable-select h-11 w-full rounded-xl px-4 text-sm"
                            data-search-input="true"
                            data-open-on-focus="true"
                            data-hide-placeholder-option="true"
                            data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}"
                        >
                            <option value="">{{ __('workflow.assessments.results.quick_entry.select_student') }}</option>
                            @foreach ($quickEntryEnrollments as $enrollment)
                                <option value="{{ $enrollment->id }}">{{ $enrollment->student?->full_name }}</option>
                            @endforeach
                        </select>
                        @error('quick_enrollment_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.group') }}</label>
                        <div id="assessment-selected-group" class="flex h-11 items-center rounded-xl border border-white/10 px-4 text-sm text-neutral-200">{{ $quickSelectedEnrollment?->group?->name ?: '—' }}</div>
                    </div>
                </div>

                <div>
                    <label for="assessment-student-score" class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.results.quick_entry.score') }}</label>
                    <input id="assessment-student-score" wire:model="quick_score" wire:keydown.enter.prevent.stop="saveQuickResult" wire:keydown.tab.prevent.stop="saveQuickResult" type="number" min="0" max="{{ $assessmentRecord->total_mark !== null ? (float) $assessmentRecord->total_mark : 100 }}" step="0.01" class="h-11 w-full rounded-xl px-4 text-sm">
                    @error('quick_score') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <button
                        type="submit"
                        class="admin-icon-button admin-icon-button--accent admin-modal-action-button"
                        title="{{ __('crud.common.actions.add_and_new') }}"
                        aria-label="{{ __('crud.common.actions.add_and_new') }}"
                        data-assessment-quick-score-save
                    >
                        <x-admin-action-icon name="save-new" class="admin-modal-action__icon admin-modal-action__icon--save-new" />
                    </button>
                </div>
            </form>
        </x-admin.modal>
    @endif

</div>
