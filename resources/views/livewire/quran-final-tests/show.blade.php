<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\QuranFinalTest;
use App\Models\Teacher;
use App\Services\QuranFinalTestRuleService;
use App\Services\QuranFinalTestService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public QuranFinalTest $finalTest;
    public ?int $teacher_id = null;
    public string $tested_on = '';
    public string $score = '';
    public string $notes = '';
    public bool $showAttemptModal = false;

    public function mount(QuranFinalTest $finalTest): void
    {
        $this->authorizePermission('quran-final-tests.view');

        $this->finalTest = QuranFinalTest::query()
            ->with([
                'attempts.teacher',
                'enrollment.group.course',
                'juz',
                'student.parentProfile',
            ])
            ->findOrFail($finalTest->id);

        $this->authorizeTeacherEnrollmentAccess($this->finalTest->enrollment);

        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'currentTeacher' => $this->currentTeacher(),
            'finalTestRecord' => $this->finalTest->fresh([
                'attempts.teacher',
                'enrollment.group.course',
                'juz',
                'student.parentProfile',
            ]),
            'scoreRules' => app(QuranFinalTestRuleService::class)->ranges(),
            'teachers' => $this->currentTeacher()
                ? collect()
                : $this->scopeTeachersQuery(
                    Teacher::query()
                        ->whereIn('status', ['active', 'inactive'])
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                )->get(),
        ];
    }

    public function openAttemptModal(): void
    {
        $this->authorizePermission('quran-final-tests.record');

        if ($this->finalTest->status === 'passed') {
            $this->addError('attempt', __('workflow.quran_final_tests.errors.already_passed'));

            return;
        }

        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->score = '';
        $this->notes = '';
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function closeAttemptModal(): void
    {
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->score = '';
        $this->notes = '';
        $this->showAttemptModal = false;
        $this->resetValidation();
    }

    public function saveAttempt(): void
    {
        $this->authorizePermission('quran-final-tests.record');

        $validated = $this->validate([
            'teacher_id' => [$this->currentTeacher() ? 'nullable' : 'required', 'exists:teachers,id'],
            'tested_on' => ['required', 'date'],
            'score' => ['required', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string'],
        ]);

        $teacherId = $this->currentTeacher()?->id ?: (int) $validated['teacher_id'];
        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        try {
            app(QuranFinalTestService::class)->recordAttempt($this->finalTest, $teacher, [
                'notes' => $validated['notes'] ?? null,
                'score' => $validated['score'] ?? null,
                'tested_on' => $validated['tested_on'],
            ]);
        } catch (\LogicException $exception) {
            $this->addError('attempt', $exception->getMessage());

            return;
        }

        session()->flash('status', __('workflow.quran_final_tests.messages.attempt_saved'));
        $this->finalTest = $this->finalTest->fresh();
        $this->closeAttemptModal();
    }

    protected function currentTeacher(): ?Teacher
    {
        return $this->linkedTeacherForPermission('quran-final-tests.record-linked-teacher');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking_quran') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_final_tests.details.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.quran_final_tests.details.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.common.labels.juz_number', ['number' => $finalTestRecord->juz?->juz_number ?: __('workflow.common.not_available')]) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.quran_final_tests.details.attempts', ['count' => $finalTestRecord->attempts->count()]) }}</span>
            <span class="badge-soft">{{ __('workflow.quran_final_tests.statuses.'.$finalTestRecord->status) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ $finalTestRecord->student?->first_name }} {{ $finalTestRecord->student?->last_name }}</div>
                <p class="admin-toolbar__subtitle">
                    {{ $finalTestRecord->enrollment?->group?->name ?: __('workflow.common.no_group') }}
                    @if ($finalTestRecord->enrollment?->group?->course?->name)
                        · {{ $finalTestRecord->enrollment->group->course->name }}
                    @endif
                </p>
            </div>

            <div class="admin-toolbar__actions">
                @if ($finalTestRecord->status !== 'passed' && auth()->user()->can('quran-final-tests.record'))
                    <button type="button" wire:click="openAttemptModal" class="pill-link pill-link--accent">{{ __('workflow.quran_final_tests.actions.record_attempt') }}</button>
                @endif
                <a href="{{ route('quran-final-tests.index') }}" wire:navigate class="pill-link">{{ __('workflow.quran_final_tests.actions.back') }}</a>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.quran_final_tests.attempts.table') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($finalTestRecord->attempts->count())]) }}</div>
            </div>
        </div>

        @if ($finalTestRecord->attempts->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.quran_final_tests.attempts.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.attempt') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.score') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($finalTestRecord->attempts as $attempt)
                            <tr>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $attempt->attempt_no }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $attempt->tested_on?->format('Y-m-d') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $attempt->teacher?->first_name }} {{ $attempt->teacher?->last_name }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $attempt->score !== null ? $attempt->score : __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.result_status.'.$attempt->status) }}</td>
                            </tr>
                            @if ($attempt->notes)
                                <tr>
                                    <td class="px-5 pb-4 text-xs text-neutral-400 lg:px-6" colspan="5">{{ $attempt->notes }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <x-admin.modal :show="$showAttemptModal" :title="__('workflow.quran_final_tests.attempts.title')" :description="__('workflow.quran_final_tests.attempts.copy')" close-method="closeAttemptModal" max-width="3xl">
        <form wire:submit="saveAttempt" class="space-y-4">
            @if ($currentTeacher)
                <div class="soft-callout px-4 py-4 text-sm leading-6">
                    <div class="font-semibold text-white">{{ __('workflow.quran_tests.workbench.teacher_badge', ['name' => $currentTeacher->first_name.' '.$currentTeacher->last_name]) }}</div>
                    <div class="mt-2 text-neutral-300">{{ __('workflow.quran_final_tests.attempts.teacher_locked') }}</div>
                </div>
            @endif

            @if (! $currentTeacher)
                <div>
                    <label for="final-attempt-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.teacher') }}</label>
                    <select id="final-attempt-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.quran_tests.form.select_teacher') }}</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('teacher_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label for="final-attempt-date" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label>
                    <input id="final-attempt-date" wire:model="tested_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('tested_on') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="final-attempt-score" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label>
                    <input id="final-attempt-score" wire:model="score" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-neutral-300">
                        <span class="badge-soft badge-soft--emerald">{{ __('workflow.quran_final_tests.attempts.range_passed', ['from' => $scoreRules['passed']['from'], 'to' => $scoreRules['passed']['to']]) }}</span>
                        <span class="badge-soft">{{ __('workflow.quran_final_tests.attempts.range_failed', ['from' => $scoreRules['failed']['from'], 'to' => $scoreRules['failed']['to']]) }}</span>
                    </div>
                    @error('score') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label for="final-attempt-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.notes') }}</label>
                <textarea id="final-attempt-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>

            @error('attempt')
                <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
            @enderror

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeAttemptModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.quran_final_tests.actions.save_attempt') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
