<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\PointTransaction;
use App\Services\AssessmentService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Assessment $currentAssessment;
    public array $result_scores = [];
    public array $result_statuses = [];
    public array $result_attempts = [];
    public array $result_notes = [];
    public string $search = '';
    public string $resultStatusFilter = 'all';
    public ?int $selectedGroupId = null;

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
            $enrollments = $enrollmentsQuery->get();
        }

        return [
            'assessmentRecord' => $this->currentAssessment->fresh(['group.course', 'groups.course', 'type']),
            'assessmentGroups' => $assessmentGroups,
            'assessmentGroupCount' => $assessmentGroups->count(),
            'assessmentPointsByEnrollment' => $this->assessmentPointsByEnrollment(),
            'enrollments' => $enrollments,
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
        $this->resetValidation();
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
            'result_attempts' => ['array'],
            'result_attempts.*' => ['nullable', 'integer', 'min:1'],
            'result_notes' => ['array'],
            'result_notes.*' => ['nullable', 'string'],
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
            $numericScore = ($score === null || $score === '') ? null : (float) $score;
            $status = $this->statusForScore($numericScore);
            $attempt = (int) ($validated['result_attempts'][$enrollment->id] ?? 1);
            $notes = $validated['result_notes'][$enrollment->id] ?? null;

            if (($score === null || $score === '') && $status === 'pending' && blank($notes)) {
                continue;
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
                    'status' => $status,
                    'attempt_no' => $attempt,
                    'notes' => blank($notes) ? null : $notes,
                ],
            );

            $service->syncResultPoints($result->fresh(['assessment.type', 'enrollment.student']));
        }

        $this->loadResults();
        session()->flash('status', __('workflow.assessments.results.messages.saved'));
    }

    protected function loadResults(): void
    {
        $results = AssessmentResult::query()
            ->where('assessment_id', $this->currentAssessment->id)
            ->get();

        $this->result_scores = $results->mapWithKeys(fn (AssessmentResult $result) => [$result->enrollment_id => $result->score !== null ? number_format((float) $result->score, 2, '.', '') : ''])->toArray();
        $this->result_statuses = $results->mapWithKeys(fn (AssessmentResult $result) => [$result->enrollment_id => $result->status])->toArray();
        $this->result_attempts = $results->mapWithKeys(fn (AssessmentResult $result) => [$result->enrollment_id => $result->attempt_no])->toArray();
        $this->result_notes = $results->mapWithKeys(fn (AssessmentResult $result) => [$result->enrollment_id => $result->notes ?? ''])->toArray();
    }

    public function displayStatusForEnrollment(int $enrollmentId): string
    {
        $score = $this->result_scores[$enrollmentId] ?? null;

        if ($score !== null && $score !== '') {
            return $this->statusForScore((float) $score);
        }

        return $this->result_statuses[$enrollmentId] ?? 'pending';
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
            ->whereNull('voided_at')
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
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('assessments.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('workflow.common.back_to_assessments') }}</a>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.assessments.results.title') }}</h1>
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
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

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
            <div class="kpi-label">{{ __('workflow.assessments.index.form.total_mark') }}</div>
            <div class="metric-value mt-3">{{ $assessmentRecord->total_mark !== null ? number_format((float) $assessmentRecord->total_mark, 2) : __('workflow.common.not_available') }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.results.stats.groups') }}</div>
            <div class="metric-value mt-3">{{ number_format($assessmentGroupCount) }}</div>
        </article>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.assessments.results.groups.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($assessmentGroups->count())]) }}</div>
            </div>
        </div>

        @if ($assessmentGroups->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.assessments.results.groups.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.assessments.results.groups.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.assessments.results.groups.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.assessments.results.groups.headers.students') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.assessments.results.groups.headers.results') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.assessments.results.groups.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($assessmentGroups as $group)
                            <tr class="{{ (int) $selectedGroupId === (int) $group->id ? 'bg-white/[0.03]' : '' }}">
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $group->name }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $group->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    {{ $group->teacher ? $group->teacher->first_name.' '.$group->teacher->last_name : __('workflow.common.no_teacher_assigned') }}
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $group->active_enrollments_count) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $group->assessment_results_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex justify-end">
                                        <button type="button" wire:click="selectGroup({{ $group->id }})" class="{{ (int) $selectedGroupId === (int) $group->id ? 'pill-link pill-link--compact pill-link--accent' : 'pill-link pill-link--compact' }}">
                                            {{ (int) $selectedGroupId === (int) $group->id ? __('workflow.assessments.results.groups.selected') : __('workflow.assessments.results.groups.open') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
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
                        <option value="pending">{{ __('workflow.common.result_status.pending') }}</option>
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
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.results.table.headers.student') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.results.table.headers.score') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.results.table.headers.status') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.results.table.headers.attempt') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.results.table.headers.notes') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.results.table.headers.cached_points') }}</th>
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
                                <input wire:model="result_scores.{{ $enrollment->id }}" type="number" min="0" step="0.01" class="w-28 rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                @error('result_scores.'.$enrollment->id) <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                            </td>
                            <td class="px-5 py-3">
                                <span class="{{ $this->resultStatusClass($displayStatus) }}">
                                    {{ __('workflow.common.result_status.'.$displayStatus) }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <input wire:model="result_attempts.{{ $enrollment->id }}" type="number" min="1" class="w-20 rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            </td>
                            <td class="px-5 py-3">
                                <input wire:model="result_notes.{{ $enrollment->id }}" type="text" class="w-full min-w-48 rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            </td>
                            <td class="px-5 py-3"><span class="status-chip status-chip--slate">{{ $assessmentPointsByEnrollment[$enrollment->id] ?? 0 }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('workflow.assessments.results.table.empty') }}</td>
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
