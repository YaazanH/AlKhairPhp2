<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\QuranFinalTest;
use App\Models\QuranTest;
use App\Models\QuranJuz;
use App\Models\Teacher;
use App\Services\QuranFinalTestRuleService;
use App\Services\QuranFinalTestService;
use App\Support\RoleRegistry;
use Livewire\Volt\Component;
use Illuminate\Validation\Rule;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public QuranFinalTest $finalTest;
    public ?int $teacher_id = null;
    public string $tested_on = '';
    public string $score = '';
    public string $notes = '';
    public bool $showAttemptModal = false;
    public ?int $editingAttemptId = null;
    public bool $showCurrentJuzModal = false;
    public ?int $newCurrentJuzId = null;

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
            'teachers' => $this->availableRecordingTeachers(),
            'availableCurrentJuzs' => $this->availableCurrentJuzs(),
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
        $this->editingAttemptId = null;
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function closeAttemptModal(): void
    {
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->score = '';
        $this->notes = '';
        $this->editingAttemptId = null;
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
        abort_unless($this->currentTeacher() || $this->availableRecordingTeachers()->contains('id', $teacher->id), 403);

        $isEditing = $this->editingAttemptId !== null;
        abort_unless(! $isEditing || auth()->user()?->hasRole('super_admin'), 403);
        try {
            $attempt = $isEditing
                ? app(QuranFinalTestService::class)->updateAttempt(
                    $this->finalTest->attempts()->findOrFail($this->editingAttemptId),
                    $teacher,
                    [
                        'notes' => $validated['notes'] ?? null,
                        'score' => $validated['score'] ?? null,
                        'tested_on' => $validated['tested_on'],
                    ],
                )
                : app(QuranFinalTestService::class)->recordAttempt($this->finalTest, $teacher, [
                    'notes' => $validated['notes'] ?? null,
                    'score' => $validated['score'] ?? null,
                    'tested_on' => $validated['tested_on'],
                ]);
        } catch (\LogicException $exception) {
            $this->addError('attempt', $exception->getMessage());

            return;
        }

        session()->flash('status', __($isEditing ? 'workflow.quran_final_tests.messages.attempt_updated' : 'workflow.quran_final_tests.messages.attempt_saved'));
        $this->finalTest = $this->finalTest->fresh();
        $this->closeAttemptModal();
        if (! $isEditing && $attempt->status === 'passed') {
            $this->showCurrentJuzModal = true;
        }
    }

    public function openEditAttempt(int $attemptId): void
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);
        $attempt = $this->finalTest->attempts()->findOrFail($attemptId);
        $this->editingAttemptId = $attempt->id;
        $this->teacher_id = $attempt->teacher_id;
        $this->tested_on = $attempt->tested_on?->toDateString() ?: now()->toDateString();
        $this->score = rtrim(rtrim(number_format((float) $attempt->score, 2, '.', ''), '0'), '.');
        $this->notes = $attempt->notes ?: '';
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function deleteAttempt(): void
    {
        abort_unless(auth()->user()?->hasRole('super_admin') && $this->editingAttemptId, 403);
        $attempt = $this->finalTest->attempts()->findOrFail($this->editingAttemptId);
        app(QuranFinalTestService::class)->deleteAttempt($attempt);
        session()->flash('status', __('workflow.quran_final_tests.messages.attempt_deleted'));
        $this->finalTest = $this->finalTest->fresh();
        $this->closeAttemptModal();
    }

    public function deleteTest(): void
    {
        $this->authorizePermission('quran-final-tests.delete');
        app(QuranFinalTestService::class)->deleteTest($this->finalTest);
        session()->flash('status', __('workflow.quran_final_tests.messages.deleted'));
        $this->redirect(route('quran-final-tests.index'), navigate: true);
    }

    public function saveCurrentJuz(): void
    {
        $this->authorizePermission('quran-final-tests.record');
        $validated = $this->validate(['newCurrentJuzId' => ['required', Rule::in($this->availableCurrentJuzs()->pluck('id')->all())]]);
        $this->finalTest->student()->update(['quran_current_juz_id' => (int) $validated['newCurrentJuzId']]);
        $this->showCurrentJuzModal = false;
        $this->newCurrentJuzId = null;
        session()->flash('status', __('workflow.quran_final_tests.current_juz.updated'));
    }

    public function closeCurrentJuzModal(): void
    {
        $this->showCurrentJuzModal = false;
        $this->newCurrentJuzId = null;
        $this->resetValidation('newCurrentJuzId');
    }

    protected function availableRecordingTeachers()
    {
        if ($this->currentTeacher()) {
            return collect();
        }

        return Teacher::query()
            ->with('user.roles')
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->filter(fn (Teacher $teacher): bool => $teacher->user?->can('quran-final-tests.record') === true
                && ! $teacher->user->hasAnyRole(RoleRegistry::unrestrictedRoles()))
            ->values();
    }

    protected function availableCurrentJuzs()
    {
        $student = $this->finalTest->student;
        $blockedJuzIds = collect([$this->finalTest->juz_id])
            ->merge($student?->externalMemorizedJuzs()->pluck('quran_juzs.id') ?? collect())
            ->merge(QuranFinalTest::query()->where('student_id', $this->finalTest->student_id)->where('status', 'passed')->pluck('juz_id'))
            ->merge(QuranTest::query()->where('student_id', $this->finalTest->student_id)->where('status', 'passed')->whereHas('type', fn ($query) => $query->where('code', 'final'))->pluck('juz_id'))
            ->filter()->unique()->all();

        return QuranJuz::query()->whereNotIn('id', $blockedJuzIds)->orderBy('juz_number')->get();
    }

    protected function currentTeacher(): ?Teacher
    {
        if (auth()->user()?->hasRole('super_admin')) {
            return null;
        }

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
                            @if (auth()->user()?->hasRole('super_admin'))<th class="px-5 py-4 text-right lg:px-6">{{ __('crud.common.actions.actions') }}</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($finalTestRecord->attempts as $attempt)
                            <tr>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $attempt->attempt_no }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $attempt->tested_on?->format('d-m-Y') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $attempt->teacher?->first_name }} {{ $attempt->teacher?->last_name }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ \App\Support\PercentageFormatter::format($attempt->score, __('workflow.common.not_available')) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.result_status.'.$attempt->status) }}</td>
                                @if (auth()->user()?->hasRole('super_admin'))<td class="px-5 py-4 text-right lg:px-6"><button type="button" wire:click="openEditAttempt({{ $attempt->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button></td>@endif
                            </tr>
                            @if ($attempt->notes)
                                <tr>
                                    <td class="px-5 pb-4 text-xs text-neutral-400 lg:px-6" colspan="{{ auth()->user()?->hasRole('super_admin') ? 6 : 5 }}">{{ $attempt->notes }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @can('quran-final-tests.delete')
        <section class="surface-panel border border-red-400/20 p-5 lg:p-6">
            <div class="flex justify-end">
                <button type="button" wire:click="deleteTest" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
            </div>
        </section>
    @endcan

    <x-admin.modal :show="$showAttemptModal" :title="__($editingAttemptId ? 'workflow.quran_final_tests.attempts.edit_title' : 'workflow.quran_final_tests.attempts.title')" :description="__('workflow.quran_final_tests.attempts.copy')" close-method="closeAttemptModal" max-width="3xl">
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
                        <span class="badge-soft badge-soft--emerald">{{ __('workflow.quran_final_tests.attempts.range_passed', ['from' => \App\Support\PercentageFormatter::format($scoreRules['passed']['from']), 'to' => \App\Support\PercentageFormatter::format($scoreRules['passed']['to'])]) }}</span>
                        <span class="badge-soft">{{ __('workflow.quran_final_tests.attempts.range_failed', ['from' => \App\Support\PercentageFormatter::format($scoreRules['failed']['from']), 'to' => \App\Support\PercentageFormatter::format($scoreRules['failed']['to'])]) }}</span>
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
                @if ($editingAttemptId)
                    <button type="button" wire:click="deleteAttempt" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link me-auto border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                @endif
                <button type="button" wire:click="closeAttemptModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __($editingAttemptId ? 'crud.common.actions.save' : 'workflow.quran_final_tests.actions.save_attempt') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showCurrentJuzModal" :title="__('workflow.quran_final_tests.current_juz.title')" :description="__('workflow.quran_final_tests.current_juz.tested', ['juz' => $finalTestRecord->juz?->juz_number])" close-method="closeCurrentJuzModal" max-width="xl">
        <form wire:submit="saveCurrentJuz" class="space-y-3">
            <div>
                <label for="new-current-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_final_tests.current_juz.select') }}</label>
                <select id="new-current-juz" wire:model="newCurrentJuzId" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('workflow.quran_final_tests.current_juz.select') }}</option>
                    @foreach ($availableCurrentJuzs as $juz)<option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>@endforeach
                </select>
                @error('newCurrentJuzId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div class="flex justify-end"><button type="submit" class="pill-link pill-link--accent">{{ __('crud.common.actions.save') }}</button></div>
        </form>
    </x-admin.modal>
</div>
