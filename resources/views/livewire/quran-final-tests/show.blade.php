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
    public bool $showAttemptModal = false;
    public ?int $editingAttemptId = null;
    public bool $showCurrentJuzModal = false;
    public string $newCurrentJuzNumber = '';

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
            'hasRelatedAwqafTest' => $this->hasRelatedAwqafTest(),
            'teachers' => $this->availableRecordingTeachers(),
            'availableCurrentJuzs' => $this->availableCurrentJuzs(),
        ];
    }

    public function openAttemptModal(): void
    {
        $this->authorizePermission('quran-final-tests.record');
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        if ($this->finalTest->status === 'passed') {
            $this->addError('attempt', __('workflow.quran_final_tests.errors.already_passed'));

            return;
        }

        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->score = '';
        $this->editingAttemptId = null;
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function closeAttemptModal(): void
    {
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->score = '';
        $this->editingAttemptId = null;
        $this->showAttemptModal = false;
        $this->resetValidation();
    }

    public function saveAttempt(): void
    {
        $this->authorizePermission('quran-final-tests.record');

        if ($this->editingAttemptId && $this->hasRelatedAwqafTest()) {
            abort(409);
        }

        if (! $this->editingAttemptId) {
            \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        }

        $validated = $this->validate([
            'teacher_id' => [$this->currentTeacher() ? 'nullable' : 'required', 'exists:teachers,id'],
            'tested_on' => ['required', 'date'],
            'score' => ['required', 'numeric', 'between:0,100'],
        ]);

        $teacherId = $this->currentTeacher()?->id ?: (int) $validated['teacher_id'];
        $teacher = Teacher::query()->findOrFail($teacherId);
        abort_unless(auth()->user()?->hasRole('super_admin') || $this->currentTeacher() || $this->availableRecordingTeachers()->contains('id', $teacher->id), 403);

        $isEditing = $this->editingAttemptId !== null;
        abort_unless(! $isEditing || $this->canEditAttemptForTeacher($teacherId), 403);
        try {
            $attempt = $isEditing
                ? app(QuranFinalTestService::class)->updateAttempt(
                    $this->finalTest->attempts()->findOrFail($this->editingAttemptId),
                    $teacher,
                    [
                        'score' => $validated['score'] ?? null,
                        'tested_on' => $validated['tested_on'],
                    ],
                )
                : app(QuranFinalTestService::class)->recordAttempt($this->finalTest, $teacher, [
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
        abort_if($this->hasRelatedAwqafTest(), 409);

        $attempt = $this->finalTest->attempts()->findOrFail($attemptId);
        abort_unless($this->canEditAttemptForTeacher($attempt->teacher_id), 403);
        $this->editingAttemptId = $attempt->id;
        $this->teacher_id = $attempt->teacher_id;
        $this->tested_on = $attempt->tested_on?->toDateString() ?: now()->toDateString();
        $this->score = rtrim(rtrim(number_format((float) $attempt->score, 2, '.', ''), '0'), '.');
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function canEditAttemptForTeacher(?int $teacherId): bool
    {
        if (! auth()->user()?->can('quran-final-tests.record')) {
            return false;
        }

        $currentTeacher = $this->currentTeacher();

        return ! $currentTeacher || (int) $currentTeacher->id === (int) $teacherId;
    }

    public function deleteAttempt(): void
    {
        abort_unless(auth()->user()?->hasRole('super_admin') && $this->editingAttemptId, 403);
        abort_if($this->hasRelatedAwqafTest(), 409);

        $attempt = $this->finalTest->attempts()->findOrFail($this->editingAttemptId);
        try {
            app(QuranFinalTestService::class)->deleteAttempt($attempt);
        } catch (\LogicException $exception) {
            $this->addError('attempt', $exception->getMessage());

            return;
        }
        session()->flash('status', __('workflow.quran_final_tests.messages.attempt_deleted'));
        $this->finalTest = $this->finalTest->fresh();
        $this->closeAttemptModal();
    }

    public function deleteTest(): void
    {
        $this->authorizePermission('quran-final-tests.delete');
        abort_if($this->hasRelatedAwqafTest(), 409);

        try {
            app(QuranFinalTestService::class)->deleteTest($this->finalTest);
        } catch (\LogicException $exception) {
            $this->addError('deleteTest', $exception->getMessage());

            return;
        }
        session()->flash('status', __('workflow.quran_final_tests.messages.deleted'));
        $this->redirect(route('quran-final-tests.index'), navigate: true);
    }

    public function saveCurrentJuz(): void
    {
        $this->authorizePermission('quran-final-tests.record');
        $availableJuzs = $this->availableCurrentJuzs();
        $validated = $this->validate(['newCurrentJuzNumber' => ['required', 'integer', Rule::in($availableJuzs->pluck('juz_number')->map(fn ($number) => (string) $number)->all())]]);
        $newCurrentJuz = $availableJuzs->firstWhere('juz_number', (int) $validated['newCurrentJuzNumber']);
        abort_unless($newCurrentJuz, 422);
        $this->finalTest->student()->update(['quran_current_juz_id' => $newCurrentJuz->id]);
        $this->showCurrentJuzModal = false;
        $this->newCurrentJuzNumber = '';
        session()->flash('status', __('workflow.quran_final_tests.current_juz.updated'));
    }

    public function closeCurrentJuzModal(): void
    {
        $this->showCurrentJuzModal = false;
        $this->newCurrentJuzNumber = '';
        $this->resetValidation('newCurrentJuzNumber');
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

    protected function hasRelatedAwqafTest(): bool
    {
        return QuranTest::query()
            ->where('student_id', $this->finalTest->student_id)
            ->where('juz_id', $this->finalTest->juz_id)
            ->whereHas('type', fn ($query) => $query->where('code', 'awqaf'))
            ->exists();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,auto)]">
            <div class="lg:self-center">
                <x-back-link :href="route('quran-final-tests.index')" navigate />
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_final_tests.details.title') }}</h1>
            </div>

            <div class="w-full lg:min-w-80">
                <div class="rounded-3xl border border-white/12 bg-black/15 px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="text-lg font-semibold text-white">{{ $finalTestRecord->student?->full_name }}</div>
                        @if (! $hasRelatedAwqafTest && ! $finalTestRecord->enrollment?->belongsToFinishedCourse())
                            @can('quran-final-tests.delete')
                                <button type="button" wire:click="deleteTest" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="admin-icon-button admin-icon-button--danger" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-final-saber-delete>
                                    <x-admin-action-icon name="delete" />
                                </button>
                            @endcan
                        @endif
                    </div>
                    <p class="mt-2 text-sm leading-6 text-neutral-200">
                        {{ $finalTestRecord->enrollment?->group?->name ?: __('workflow.common.no_group') }}
                        @if ($finalTestRecord->enrollment?->group?->course?->name)
                            · {{ $finalTestRecord->enrollment->group->course->name }}
                        @endif
                    </p>
                </div>
                @error('deleteTest') <div class="mt-2 text-sm text-red-300">{{ $message }}</div> @enderror
            </div>

            <div class="flex flex-wrap gap-3 lg:col-start-2">
                @if ($finalTestRecord->status !== 'passed' && auth()->user()->can('quran-final-tests.record'))
                    <button type="button" wire:click="openAttemptModal" class="pill-link pill-link--accent workflow-entry-action--hidden">{{ __('workflow.quran_final_tests.actions.record_attempt') }}</button>
                @endif
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div class="admin-grid-meta__title">{{ __('workflow.quran_final_tests.attempts.table') }}</div>
        </div>

        @if ($finalTestRecord->attempts->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.quran_final_tests.attempts.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                @php($showFinalAttemptActions = ! $hasRelatedAwqafTest && $finalTestRecord->attempts->contains(fn ($attempt) => $this->canEditAttemptForTeacher($attempt->teacher_id)))
                @php($finalAttemptColumnCount = $showFinalAttemptActions ? 6 : 5)
                <table class="w-full table-fixed text-sm" style="table-layout: fixed; width: 100%;">
                    <colgroup>
                        @for ($column = 0; $column < $finalAttemptColumnCount; $column++)
                            <col style="width: {{ 100 / $finalAttemptColumnCount }}%;">
                        @endfor
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.attempt') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.score') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.attempts.headers.status') }}</th>
                            @if ($showFinalAttemptActions)<th class="admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('crud.common.actions.actions') }}</th>@endif
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
                                @if ($showFinalAttemptActions)
                                    <td class="px-5 py-4 text-center lg:px-6">
                                        @if ($this->canEditAttemptForTeacher($attempt->teacher_id))
                                            <button type="button" wire:click="openEditAttempt({{ $attempt->id }})" class="admin-icon-button" title="{{ __('crud.common.actions.edit') }}" aria-label="{{ __('crud.common.actions.edit') }}" data-final-saber-edit><x-admin-action-icon name="edit" /></button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                            @if ($attempt->notes)
                                <tr>
                                    <td class="px-5 pb-4 text-xs text-neutral-400 lg:px-6" colspan="{{ $showFinalAttemptActions ? 6 : 5 }}">{{ $attempt->notes }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <x-admin.modal :show="$showAttemptModal" :title="__($editingAttemptId ? 'workflow.quran_final_tests.attempts.edit_title' : 'workflow.quran_final_tests.attempts.title')" :description="__('workflow.quran_final_tests.attempts.copy')" close-method="closeAttemptModal" max-width="xl" compact>
        <form wire:submit="saveAttempt" class="space-y-4">
            @if ($editingAttemptId)
                <div>
                    <label for="final-attempt-student-readonly" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.workbench.form.student') }}</label>
                    <input id="final-attempt-student-readonly" type="text" value="{{ $finalTestRecord->student?->full_name ?: __('crud.common.not_available') }}" readonly class="w-full rounded-xl px-4 py-3 text-sm" data-final-saber-student-readonly>
                </div>
            @endif

            @if ($currentTeacher)
                <div>
                    <label for="final-attempt-teacher-readonly" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.teacher') }}</label>
                    <input id="final-attempt-teacher-readonly" type="text" value="{{ $currentTeacher->first_name }} {{ $currentTeacher->last_name }}" readonly class="w-full rounded-xl px-4 py-3 text-sm">
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
                    <input id="final-attempt-date" wire:model="tested_on" value="{{ $tested_on }}" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('tested_on') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="final-attempt-score" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label>
                    <div @class(['quick-saber-affixed-input' => $editingAttemptId])>
                        <input id="final-attempt-score" wire:model="score" type="number" min="0" max="100" step="0.01" placeholder="{{ __('workflow.quran_final_tests.attempts.range_passed', ['from' => \App\Support\PercentageFormatter::format($scoreRules['passed']['from']), 'to' => \App\Support\PercentageFormatter::format($scoreRules['passed']['to'])]) }}" class="w-full rounded-xl px-4 py-3 text-sm">
                        @if ($editingAttemptId)<span class="quick-saber-affixed-input__suffix" aria-hidden="true">%</span>@endif
                    </div>
                    @error('score') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            @error('attempt')
                <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
            @enderror

            <div class="admin-action-cluster admin-action-cluster--end">
                <button type="submit" class="admin-icon-button admin-icon-button--accent admin-modal-action-button" title="{{ __($editingAttemptId ? 'crud.common.actions.save' : 'workflow.quran_final_tests.actions.save_attempt') }}" aria-label="{{ __($editingAttemptId ? 'crud.common.actions.save' : 'workflow.quran_final_tests.actions.save_attempt') }}" data-final-saber-attempt-save>
                    <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                </button>
                <button type="button" wire:click="closeAttemptModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                @if (! $hasRelatedAwqafTest && ! $finalTestRecord->enrollment?->belongsToFinishedCourse() && $editingAttemptId && auth()->user()?->hasRole('super_admin'))
                    <x-delete-action-button wire:click="deleteAttempt" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('crud.common.actions.delete')" data-final-saber-attempt-delete />
                @endif
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showCurrentJuzModal" :title="__('workflow.quran_final_tests.current_juz.title')" :description="__('workflow.quran_final_tests.current_juz.tested', ['juz' => $finalTestRecord->juz?->juz_number])" close-method="closeCurrentJuzModal" max-width="xl">
        <form wire:submit="saveCurrentJuz" class="space-y-3">
            <div>
                <label for="new-current-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_final_tests.current_juz.enter') }}</label>
                <input id="new-current-juz" wire:model="newCurrentJuzNumber" type="number" min="1" max="30" step="1" inputmode="numeric" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('newCurrentJuzNumber') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div class="flex justify-end"><button type="submit" class="pill-link pill-link--accent">{{ __('crud.common.actions.save') }}</button></div>
        </form>
    </x-admin.modal>
</div>
