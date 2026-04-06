<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
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

    public function mount(Assessment $assessment): void
    {
        $this->authorizePermission('assessment-results.view');

        $this->currentAssessment = Assessment::query()
            ->with(['group.course', 'type'])
            ->findOrFail($assessment->id);

        $this->authorizeTeacherAssessmentAccess($this->currentAssessment);
        $this->loadResults();
    }

    public function with(): array
    {
        return [
            'assessmentRecord' => $this->currentAssessment->fresh(['group.course', 'type']),
            'enrollments' => Enrollment::query()
                ->with(['student', 'assessmentResults' => fn ($query) => $query->where('assessment_id', $this->currentAssessment->id)])
                ->where('group_id', $this->currentAssessment->group_id)
                ->where('status', 'active')
                ->orderBy('enrolled_at')
                ->get(),
        ];
    }

    public function saveResults(): void
    {
        $this->authorizePermission('assessment-results.record');
        $this->authorizeTeacherAssessmentAccess($this->currentAssessment);

        $maxMark = $this->currentAssessment->total_mark !== null ? (float) $this->currentAssessment->total_mark : 100;

        $validated = $this->validate([
            'result_scores' => ['array'],
            'result_scores.*' => ['nullable', 'numeric', 'min:0', 'max:'.$maxMark],
            'result_statuses' => ['array'],
            'result_statuses.*' => ['nullable', 'in:passed,failed,absent,pending'],
            'result_attempts' => ['array'],
            'result_attempts.*' => ['nullable', 'integer', 'min:1'],
            'result_notes' => ['array'],
            'result_notes.*' => ['nullable', 'string'],
        ]);

        $teacherId = auth()->user()?->teacherProfile?->id ?: $this->currentAssessment->group?->teacher_id;
        $service = app(AssessmentService::class);

        $enrollments = Enrollment::query()
            ->where('group_id', $this->currentAssessment->group_id)
            ->where('status', 'active')
            ->get();

        foreach ($enrollments as $enrollment) {
            $score = $validated['result_scores'][$enrollment->id] ?? null;
            $status = $validated['result_statuses'][$enrollment->id] ?? 'pending';
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
                    'score' => $score === '' ? null : $score,
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
                <div class="mt-1 text-sm text-neutral-400">{{ $assessmentRecord->type?->name ?: __('workflow.common.not_available') }} | {{ $assessmentRecord->group?->name ?: __('workflow.common.not_available') }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ __('workflow.common.labels.total', ['value' => $assessmentRecord->total_mark !== null ? number_format((float) $assessmentRecord->total_mark, 2) : __('workflow.common.not_available')]) }} | {{ __('workflow.common.labels.pass', ['value' => $assessmentRecord->pass_mark !== null ? number_format((float) $assessmentRecord->pass_mark, 2) : __('workflow.common.not_available')]) }}</div>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @php
        $savedResultsCount = $enrollments->filter(fn ($enrollment) => $enrollment->assessmentResults->isNotEmpty())->count();
    @endphp

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.results.table.headers.student') }}</div>
            <div class="metric-value mt-3">{{ number_format($enrollments->count()) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.index.table.headers.results') }}</div>
            <div class="metric-value mt-3">{{ number_format($savedResultsCount) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.index.form.total_mark') }}</div>
            <div class="metric-value mt-3">{{ $assessmentRecord->total_mark !== null ? number_format((float) $assessmentRecord->total_mark, 2) : __('workflow.common.not_available') }}</div>
        </article>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.assessments.results.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($enrollments->count())]) }}</div>
            </div>
        </div>

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
                                <select wire:model="result_statuses.{{ $enrollment->id }}" class="w-32 rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                    <option value="pending">{{ __('workflow.common.result_status.pending') }}</option>
                                    <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                                    <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                                    <option value="absent">{{ __('workflow.common.result_status.absent') }}</option>
                                </select>
                            </td>
                            <td class="px-5 py-3">
                                <input wire:model="result_attempts.{{ $enrollment->id }}" type="number" min="1" class="w-20 rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            </td>
                            <td class="px-5 py-3">
                                <input wire:model="result_notes.{{ $enrollment->id }}" type="text" class="w-full min-w-48 rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            </td>
                            <td class="px-5 py-3"><span class="status-chip status-chip--slate">{{ $enrollment->final_points_cached }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('workflow.assessments.results.table.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('assessment-results.record')
        <div class="admin-action-cluster admin-action-cluster--end">
            <button wire:click="saveResults" type="button" class="pill-link pill-link--accent">
                {{ __('workflow.common.actions.save_assessment_results') }}
            </button>
        </div>
    @endcan
</div>
