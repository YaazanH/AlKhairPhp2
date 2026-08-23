<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\QuranFinalTest;
use App\Models\QuranPartialTest;
use App\Models\Teacher;
use App\Services\QuranPartialTestRuleService;
use App\Services\QuranPartialTestService;
use App\Support\RoleRegistry;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public QuranPartialTest $partialTest;
    public ?int $selectedPartId = null;
    public ?int $teacher_id = null;
    public string $tested_on = '';
    public string $mistake_count = '';
    public bool $showAttemptModal = false;
    public ?int $editingAttemptId = null;

    public function mount(QuranPartialTest $partialTest): void
    {
        $this->authorizePermission('quran-partial-tests.view');

        $this->partialTest = QuranPartialTest::query()
            ->with([
                'enrollment.group.course',
                'parts.attempts.teacher',
                'student.parentProfile',
                'juz',
            ])
            ->findOrFail($partialTest->id);

        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'partialTestRecord' => $this->partialTest->fresh([
                'enrollment.group.course',
                'parts.attempts.teacher',
                'student.parentProfile',
                'juz',
            ]),
            'currentTeacher' => $this->currentTeacher(),
            'failThreshold' => app(QuranPartialTestRuleService::class)->failThreshold(),
            'hasRelatedFinalTest' => $this->hasRelatedFinalTest(),
            'teachers' => $this->availableRecordingTeachers(),
        ];
    }

    public function openAttemptModal(int $partId): void
    {
        $this->authorizePermission('quran-partial-tests.record');
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        $part = $this->partialTest->parts()->findOrFail($partId);

        if ($part->status === 'passed') {
            $this->addError('attempt', __('workflow.quran_partial_tests.errors.part_already_passed'));

            return;
        }

        $this->selectedPartId = $part->id;
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->mistake_count = '';
        $this->editingAttemptId = null;
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function closeAttemptModal(): void
    {
        $this->selectedPartId = null;
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->mistake_count = '';
        $this->editingAttemptId = null;
        $this->showAttemptModal = false;
        $this->resetValidation();
    }

    public function saveAttempt(): void
    {
        $this->authorizePermission('quran-partial-tests.record');

        if (! $this->editingAttemptId) {
            \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        }

        $validated = $this->validate([
            'selectedPartId' => ['required', 'exists:quran_partial_test_parts,id'],
            'teacher_id' => [$this->currentTeacher() ? 'nullable' : 'required', 'exists:teachers,id'],
            'tested_on' => ['required', 'date'],
            'mistake_count' => ['required', 'integer', 'min:0', 'max:999'],
        ], [], [
            'mistake_count' => __('workflow.quran_partial_tests.attempts.fields.mistake_count'),
        ]);

        $part = $this->partialTest->parts()->findOrFail((int) $validated['selectedPartId']);
        $teacherId = $this->currentTeacher()?->id ?: (int) $validated['teacher_id'];
        $teacher = Teacher::query()->findOrFail($teacherId);
        abort_unless(auth()->user()?->hasRole('super_admin') || $this->currentTeacher() || $this->availableRecordingTeachers()->contains('id', $teacher->id), 403);

        $isEditing = $this->editingAttemptId !== null;
        abort_unless(! $isEditing || $this->canEditAttemptForTeacher($teacherId), 403);
        try {
            if ($isEditing) {
                app(QuranPartialTestService::class)->updateAttempt(
                    $part->attempts()->findOrFail($this->editingAttemptId),
                    $teacher,
                    [
                        'mistake_count' => $validated['mistake_count'],
                        'tested_on' => $validated['tested_on'],
                    ],
                );
            } else {
                app(QuranPartialTestService::class)->recordAttempt($part, $teacher, [
                    'mistake_count' => $validated['mistake_count'],
                    'tested_on' => $validated['tested_on'],
                ]);
            }
        } catch (\LogicException $exception) {
            $this->addError('attempt', $exception->getMessage());

            return;
        }

        session()->flash('status', __($isEditing ? 'workflow.quran_partial_tests.messages.attempt_updated' : 'workflow.quran_partial_tests.messages.attempt_saved'));
        $this->partialTest = $this->partialTest->fresh();
        $this->closeAttemptModal();
    }

    public function openEditAttempt(int $attemptId): void
    {
        $attempt = $this->partialTest->parts()->whereHas('attempts', fn ($query) => $query->whereKey($attemptId))->firstOrFail()
            ->attempts()->findOrFail($attemptId);
        abort_unless($this->canEditAttemptForTeacher($attempt->teacher_id), 403);
        $this->editingAttemptId = $attempt->id;
        $this->selectedPartId = $attempt->quran_partial_test_part_id;
        $this->teacher_id = $attempt->teacher_id;
        $this->tested_on = $attempt->tested_on?->toDateString() ?: now()->toDateString();
        $this->mistake_count = (string) $attempt->mistake_count;
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function canEditAttemptForTeacher(?int $teacherId): bool
    {
        if (! auth()->user()?->can('quran-partial-tests.record')) {
            return false;
        }

        $currentTeacher = $this->currentTeacher();

        return ! $currentTeacher || (int) $currentTeacher->id === (int) $teacherId;
    }

    public function deleteAttempt(): void
    {
        abort_unless(auth()->user()?->hasRole('super_admin') && $this->editingAttemptId, 403);

        if ($this->hasRelatedFinalTest()) {
            $this->addError('attempt', __('workflow.quran_partial_tests.errors.final_saber_exists'));

            return;
        }

        $attempt = $this->partialTest->parts()
            ->whereHas('attempts', fn ($query) => $query->whereKey($this->editingAttemptId))
            ->firstOrFail()
            ->attempts()->findOrFail($this->editingAttemptId);
        app(QuranPartialTestService::class)->deleteAttempt($attempt);
        session()->flash('status', __('workflow.quran_partial_tests.messages.attempt_deleted'));
        $this->partialTest = $this->partialTest->fresh();
        $this->closeAttemptModal();
    }

    public function deleteTest(): void
    {
        $this->authorizePermission('quran-partial-tests.delete');

        if ($this->hasRelatedFinalTest()) {
            $this->addError('deleteTest', __('workflow.quran_partial_tests.errors.final_saber_exists'));

            return;
        }

        try {
            app(QuranPartialTestService::class)->deleteTest($this->partialTest);
        } catch (\LogicException $exception) {
            $this->addError('deleteTest', $exception->getMessage());

            return;
        }

        session()->flash('status', __('workflow.quran_partial_tests.messages.deleted'));
        $this->redirect(route('quran-partial-tests.index'), navigate: true);
    }

    protected function currentTeacher(): ?Teacher
    {
        if (auth()->user()?->hasRole('super_admin')) {
            return null;
        }

        return $this->linkedTeacherForPermission('quran-partial-tests.record-linked-teacher');
    }

    protected function hasRelatedFinalTest(): bool
    {
        return QuranFinalTest::query()
            ->where('student_id', $this->partialTest->student_id)
            ->where('juz_id', $this->partialTest->juz_id)
            ->exists();
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
            ->filter(fn (Teacher $teacher): bool => $teacher->user?->can('quran-partial-tests.record') === true
                && ! $teacher->user->hasAnyRole(RoleRegistry::unrestrictedRoles()))
            ->values();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,auto)]">
            <div class="lg:self-center">
                <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_partial_tests.details.title') }}</h1>
            </div>

            <div class="w-full lg:min-w-80">
                <div class="rounded-3xl border border-white/12 bg-black/15 px-5 py-4">
                    <div class="text-lg font-semibold text-white">{{ $partialTestRecord->student?->full_name }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-200">
                        {{ $partialTestRecord->enrollment?->group?->name ?: __('workflow.common.no_group') }}
                        @if ($partialTestRecord->enrollment?->group?->course?->name)
                            · {{ $partialTestRecord->enrollment->group->course->name }}
                        @endif
                    </p>
                </div>

            </div>

            <div class="flex flex-wrap gap-3 lg:col-start-2">
                <a href="{{ route('quran-partial-tests.index') }}" wire:navigate class="pill-link">{{ __('workflow.quran_partial_tests.actions.back') }}</a>
                @can('quran-partial-tests.delete')
                    <button type="button" wire:click="deleteTest" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" @disabled($hasRelatedFinalTest) title="{{ $hasRelatedFinalTest ? __('workflow.quran_partial_tests.errors.final_saber_exists') : '' }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12 disabled:cursor-not-allowed disabled:opacity-40">{{ __('crud.common.actions.delete') }}</button>
                @endcan
                @error('deleteTest') <div class="w-full text-sm text-red-300">{{ $message }}</div> @enderror
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($partialTestRecord->parts as $part)
            <section class="surface-table">
                <div class="admin-grid-meta admin-grid-meta--controls">
                    <div class="admin-grid-meta__title">{{ __('workflow.quran_partial_tests.part.quarters.'.$part->part_number) }}</div>
                    @if ($part->status !== 'passed' && auth()->user()->can('quran-partial-tests.record'))
                        <button type="button" wire:click="openAttemptModal({{ $part->id }})" class="pill-link pill-link--accent workflow-entry-action--hidden">{{ __('workflow.quran_partial_tests.actions.record_attempt') }}</button>
                    @endif
                </div>

                @if ($part->attempts->isEmpty())
                    <div class="admin-empty-state">{{ __('workflow.quran_partial_tests.part.no_attempts') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.attempt') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.date') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.teacher') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.mistake_count') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.status') }}</th>
                                    @if ($part->attempts->contains(fn ($attempt) => $this->canEditAttemptForTeacher($attempt->teacher_id)))<th class="px-4 py-3 text-right">{{ __('crud.common.actions.actions') }}</th>@endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($part->attempts as $attempt)
                                    <tr>
                                        <td class="px-4 py-3">{{ $attempt->attempt_no }}</td>
                                        <td class="px-4 py-3">{{ $attempt->tested_on?->format('d-m-Y') }}</td>
                                        <td class="px-4 py-3">{{ $attempt->teacher?->first_name }} {{ $attempt->teacher?->last_name }}</td>
                                        <td class="px-4 py-3">
                                            @if ($attempt->mistake_count !== null)
                                                {{ $attempt->mistake_count }}
                                            @elseif ($attempt->score !== null)
                                                {{ __('workflow.quran_partial_tests.attempts.legacy_score', ['value' => $attempt->score]) }}
                                            @else
                                                {{ __('workflow.common.not_available') }}
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">{{ __('workflow.common.result_status.'.$attempt->status) }}</td>
                                        @if ($part->attempts->contains(fn ($row) => $this->canEditAttemptForTeacher($row->teacher_id)))
                                            <td class="px-4 py-3 text-right">
                                                @if ($this->canEditAttemptForTeacher($attempt->teacher_id))
                                                    <button type="button" wire:click="openEditAttempt({{ $attempt->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                    @if ($attempt->notes)
                                        <tr>
                                            <td class="px-4 pb-3 text-xs text-neutral-400" colspan="{{ $part->attempts->contains(fn ($row) => $this->canEditAttemptForTeacher($row->teacher_id)) ? 6 : 5 }}">{{ $attempt->notes }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    <x-admin.modal :show="$showAttemptModal" :title="__($editingAttemptId ? 'workflow.quran_partial_tests.attempts.edit_title' : 'workflow.quran_partial_tests.attempts.title')" :description="__('workflow.quran_partial_tests.attempts.copy')" close-method="closeAttemptModal" max-width="3xl">
        <form wire:submit="saveAttempt" class="space-y-4">
            @if ($currentTeacher)
                <div>
                    <label for="partial-attempt-teacher-readonly" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.teacher') }}</label>
                    <input id="partial-attempt-teacher-readonly" type="text" value="{{ $currentTeacher->first_name }} {{ $currentTeacher->last_name }}" readonly class="w-full rounded-xl px-4 py-3 text-sm">
                </div>
            @endif

            @if (! $currentTeacher)
                <div>
                    <label for="partial-attempt-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.teacher') }}</label>
                    <select id="partial-attempt-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
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
                    <label for="partial-attempt-date" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label>
                    <input id="partial-attempt-date" wire:model="tested_on" value="{{ $tested_on }}" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('tested_on') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="partial-attempt-mistake-count" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_partial_tests.attempts.fields.mistake_count') }}</label>
                    <input id="partial-attempt-mistake-count" wire:model="mistake_count" type="number" min="0" max="999" step="1" placeholder="{{ __('workflow.quran_partial_tests.attempts.fail_threshold', ['count' => $failThreshold]) }}" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('mistake_count') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            @error('attempt')
                <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
            @enderror

            <div class="flex justify-end gap-3">
                @if ($editingAttemptId && auth()->user()?->hasRole('super_admin'))
                    <button type="button" wire:click="deleteAttempt" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" @disabled($hasRelatedFinalTest) title="{{ $hasRelatedFinalTest ? __('workflow.quran_partial_tests.errors.final_saber_exists') : '' }}" class="pill-link me-auto border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12 disabled:cursor-not-allowed disabled:opacity-40">{{ __('crud.common.actions.delete') }}</button>
                @endif
                <button type="button" wire:click="closeAttemptModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __($editingAttemptId ? 'crud.common.actions.save' : 'workflow.quran_partial_tests.actions.save_attempt') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
