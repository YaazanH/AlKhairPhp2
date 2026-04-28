<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\QuranPartialTest;
use App\Models\Teacher;
use App\Services\QuranPartialTestRuleService;
use App\Services\QuranPartialTestService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public QuranPartialTest $partialTest;
    public ?int $selectedPartId = null;
    public ?int $teacher_id = null;
    public string $tested_on = '';
    public string $mistake_count = '';
    public string $notes = '';
    public bool $showAttemptModal = false;

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

        $this->authorizeTeacherEnrollmentAccess($this->partialTest->enrollment);

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

    public function openAttemptModal(int $partId): void
    {
        $this->authorizePermission('quran-partial-tests.record');

        $part = $this->partialTest->parts()->findOrFail($partId);

        if ($part->status === 'passed') {
            $this->addError('attempt', __('workflow.quran_partial_tests.errors.part_already_passed'));

            return;
        }

        $this->selectedPartId = $part->id;
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->mistake_count = '';
        $this->notes = '';
        $this->showAttemptModal = true;
        $this->resetValidation();
    }

    public function closeAttemptModal(): void
    {
        $this->selectedPartId = null;
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->tested_on = now()->toDateString();
        $this->mistake_count = '';
        $this->notes = '';
        $this->showAttemptModal = false;
        $this->resetValidation();
    }

    public function saveAttempt(): void
    {
        $this->authorizePermission('quran-partial-tests.record');

        $validated = $this->validate([
            'selectedPartId' => ['required', 'exists:quran_partial_test_parts,id'],
            'teacher_id' => [$this->currentTeacher() ? 'nullable' : 'required', 'exists:teachers,id'],
            'tested_on' => ['required', 'date'],
            'mistake_count' => ['required', 'integer', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'mistake_count' => __('workflow.quran_partial_tests.attempts.fields.mistake_count'),
        ]);

        $part = $this->partialTest->parts()->findOrFail((int) $validated['selectedPartId']);
        $teacherId = $this->currentTeacher()?->id ?: (int) $validated['teacher_id'];
        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        try {
            app(QuranPartialTestService::class)->recordAttempt($part, $teacher, [
                'mistake_count' => $validated['mistake_count'],
                'notes' => $validated['notes'] ?? null,
                'tested_on' => $validated['tested_on'],
            ]);
        } catch (\LogicException $exception) {
            $this->addError('attempt', $exception->getMessage());

            return;
        }

        session()->flash('status', __('workflow.quran_partial_tests.messages.attempt_saved'));
        $this->partialTest = $this->partialTest->fresh();
        $this->closeAttemptModal();
    }

    protected function currentTeacher(): ?Teacher
    {
        return $this->linkedTeacherForPermission('quran-partial-tests.record-linked-teacher');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking_quran') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_partial_tests.details.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.quran_partial_tests.details.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.common.labels.juz_number', ['number' => $partialTestRecord->juz?->juz_number ?: __('workflow.common.not_available')]) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.quran_partial_tests.details.parts_passed', ['count' => $partialTestRecord->parts->where('status', 'passed')->count()]) }}</span>
            <span class="badge-soft">{{ __('workflow.quran_partial_tests.statuses.'.$partialTestRecord->status) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ $partialTestRecord->student?->first_name }} {{ $partialTestRecord->student?->last_name }}</div>
                <p class="admin-toolbar__subtitle">
                    {{ $partialTestRecord->enrollment?->group?->name ?: __('workflow.common.no_group') }}
                    @if ($partialTestRecord->enrollment?->group?->course?->name)
                        · {{ $partialTestRecord->enrollment->group->course->name }}
                    @endif
                </p>
            </div>

            <div class="admin-toolbar__actions">
                <a href="{{ route('quran-partial-tests.index') }}" wire:navigate class="pill-link">{{ __('workflow.quran_partial_tests.actions.back') }}</a>
            </div>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($partialTestRecord->parts as $part)
            <section class="surface-panel p-5 lg:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-sm uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.quran_partial_tests.part.label', ['number' => $part->part_number]) }}</div>
                        <div class="mt-2 text-2xl font-semibold text-white">{{ __('workflow.quran_partial_tests.statuses.'.$part->status) }}</div>
                        <div class="mt-2 text-sm text-neutral-300">{{ __('workflow.quran_partial_tests.part.retries', ['count' => max(0, $part->attempts->count() - ($part->status === 'passed' ? 1 : 0))]) }}</div>
                    </div>

                    @if ($part->status !== 'passed' && auth()->user()->can('quran-partial-tests.record'))
                        <button type="button" wire:click="openAttemptModal({{ $part->id }})" class="pill-link pill-link--accent">{{ __('workflow.quran_partial_tests.actions.record_attempt') }}</button>
                    @endif
                </div>

                @if ($part->attempts->isEmpty())
                    <div class="mt-5 rounded-2xl border border-dashed border-white/10 px-4 py-5 text-sm text-neutral-400">{{ __('workflow.quran_partial_tests.part.no_attempts') }}</div>
                @else
                    <div class="mt-5 overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.attempt') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.date') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.teacher') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.mistake_count') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('workflow.quran_partial_tests.attempts.headers.status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($part->attempts as $attempt)
                                    <tr>
                                        <td class="px-4 py-3">{{ $attempt->attempt_no }}</td>
                                        <td class="px-4 py-3">{{ $attempt->tested_on?->format('Y-m-d') }}</td>
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
                                    </tr>
                                    @if ($attempt->notes)
                                        <tr>
                                            <td class="px-4 pb-3 text-xs text-neutral-400" colspan="5">{{ $attempt->notes }}</td>
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

    <x-admin.modal :show="$showAttemptModal" :title="__('workflow.quran_partial_tests.attempts.title')" :description="__('workflow.quran_partial_tests.attempts.copy')" close-method="closeAttemptModal" max-width="3xl">
        <form wire:submit="saveAttempt" class="space-y-4">
            @if ($currentTeacher)
                <div class="soft-callout px-4 py-4 text-sm leading-6">
                    <div class="font-semibold text-white">{{ __('workflow.quran_tests.workbench.teacher_badge', ['name' => $currentTeacher->first_name.' '.$currentTeacher->last_name]) }}</div>
                    <div class="mt-2 text-neutral-300">{{ __('workflow.quran_partial_tests.attempts.teacher_locked') }}</div>
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
                    <input id="partial-attempt-date" wire:model="tested_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('tested_on') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="partial-attempt-mistake-count" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_partial_tests.attempts.fields.mistake_count') }}</label>
                    <input id="partial-attempt-mistake-count" wire:model="mistake_count" type="number" min="0" max="999" step="1" class="w-full rounded-xl px-4 py-3 text-sm">
                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-neutral-300">
                        <span class="badge-soft">{{ __('workflow.quran_partial_tests.attempts.fail_threshold', ['count' => $failThreshold]) }}</span>
                    </div>
                    @error('mistake_count') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label for="partial-attempt-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.notes') }}</label>
                <textarea id="partial-attempt-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>

            @error('attempt')
                <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
            @enderror

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeAttemptModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.quran_partial_tests.actions.save_attempt') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
