<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\MemorizationService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?int $selectedStudentId = null;
    public ?int $selectedEnrollmentId = null;
    public string $from_page = '';
    public string $to_page = '';
    public bool $showDuplicateModal = false;
    public array $duplicatePages = [];
    public array $uniquePages = [];
    public array $pendingMemorizationPayload = [];
    public ?int $pendingEnrollmentId = null;

    public function mount(): void
    {
        $this->authorizePermission('memorization.record');
    }

    public function with(): array
    {
        $studentOptions = $this->quickEntryStudentsQuery()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
        $availableEnrollments = $this->selectedStudentId
            ? $this->availableQuickEntryEnrollmentsForStudent((int) $this->selectedStudentId)
            : collect();
        $selectedEnrollment = $this->selectedQuickEntryEnrollment($availableEnrollments);

        return [
            'studentOptions' => $studentOptions,
            'availableEnrollments' => $availableEnrollments,
            'currentTeacher' => $this->currentTeacher(),
            'currentUser' => auth()->user(),
            'selectedEnrollment' => $selectedEnrollment,
            'selectedTeacher' => $selectedEnrollment ? $this->resolveQuickEntryTeacher($selectedEnrollment) : null,
        ];
    }

    public function updatedSelectedStudentId($value): void
    {
        $this->selectedEnrollmentId = null;
        $this->resetValidation('selectedEnrollmentId');

        if (! filled($value)) {
            return;
        }

        $availableEnrollments = $this->availableQuickEntryEnrollmentsForStudent((int) $value);

        if ($availableEnrollments->count() === 1) {
            $this->selectedEnrollmentId = $availableEnrollments->first()->id;
        }
    }

    public function save(): void
    {
        $this->authorizePermission('memorization.record');

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'from_page' => ['required', 'integer', 'between:1,604'],
            'to_page' => ['required', 'integer', 'between:1,604', 'gte:from_page'],
        ], [], [
            'selectedStudentId' => __('workflow.memorization.quick_entry.form.student'),
            'selectedEnrollmentId' => __('workflow.memorization.workbench.form.group'),
            'from_page' => __('workflow.memorization.form.from_page'),
            'to_page' => __('workflow.memorization.form.to_page'),
        ]);

        $student = $this->findQuickEntryStudent((int) $validated['selectedStudentId']);
        $enrollment = $this->findQuickEntryEnrollmentForStudent(
            $student,
            filled($validated['selectedEnrollmentId'] ?? null) ? (int) $validated['selectedEnrollmentId'] : null,
        );

        if (! $enrollment) {
            if (! $this->getErrorBag()->has('selectedEnrollmentId')) {
                $this->addError('selectedStudentId', __('workflow.memorization.errors.no_active_enrollment'));
            }

            return;
        }

        $teacher = $this->resolveQuickEntryTeacher($enrollment);

        if (! $teacher) {
            $this->addError('selectedEnrollmentId', __('workflow.memorization.quick_entry.errors.no_assigned_teacher'));

            return;
        }

        $payload = [
            'teacher_id' => $teacher->id,
            'recorded_on' => now()->toDateString(),
            'entry_type' => 'new',
            'from_page' => $validated['from_page'],
            'to_page' => $validated['to_page'],
            'notes' => null,
        ];

        $service = app(MemorizationService::class);
        $duplicatePages = $service->findDuplicatePages(
            $enrollment,
            range((int) $validated['from_page'], (int) $validated['to_page']),
            'new',
        );

        if ($duplicatePages !== []) {
            $this->openDuplicateModal($enrollment, $payload, $duplicatePages);

            return;
        }

        $service->saveSession($enrollment, $payload);

        session()->flash('status', __('workflow.memorization.quick_entry.messages.saved'));

        $this->reset(['selectedStudentId', 'selectedEnrollmentId', 'from_page', 'to_page']);
        $this->resetValidation();
    }

    public function confirmDuplicateSave(): void
    {
        $this->authorizePermission('memorization.record');

        if ($this->pendingMemorizationPayload === [] || ! $this->pendingEnrollmentId) {
            return;
        }

        if ($this->uniquePages === []) {
            $this->closeDuplicateModal();

            return;
        }

        $enrollment = $this->quickEntryEnrollmentsQuery()
            ->with(['student', 'group.teacher'])
            ->findOrFail($this->pendingEnrollmentId);

        app(MemorizationService::class)->saveSession(
            $enrollment,
            $this->pendingMemorizationPayload,
            null,
            true,
        );

        session()->flash(
            'status',
            __('workflow.memorization.quick_entry.messages.saved_partial', ['pages' => implode(', ', $this->duplicatePages)]),
        );

        $this->closeDuplicateModal();
        $this->reset(['selectedStudentId', 'selectedEnrollmentId', 'from_page', 'to_page']);
        $this->resetValidation();
    }

    protected function currentTeacher(): ?\App\Models\Teacher
    {
        return auth()->user()?->teacherProfile;
    }

    protected function quickEntryStudentsQuery(): Builder
    {
        return Student::query()
            ->with(['parentProfile'])
            ->whereHas('enrollments', fn (Builder $builder) => $this->quickEntryEnrollmentsQuery($builder)->where('status', 'active'));
    }

    protected function quickEntryEnrollmentsQuery(?Builder $query = null): Builder
    {
        $query ??= Enrollment::query();

        return $query;
    }

    protected function findQuickEntryStudent(int $studentId): Student
    {
        return $this->quickEntryStudentsQuery()->findOrFail($studentId);
    }

    protected function findQuickEntryEnrollmentForStudent(Student $student, ?int $selectedEnrollmentId = null): ?Enrollment
    {
        $enrollments = $this->availableQuickEntryEnrollmentsForStudent($student->id);

        if ($enrollments->isEmpty()) {
            return null;
        }

        if ($selectedEnrollmentId !== null) {
            abort_unless($enrollments->contains('id', $selectedEnrollmentId), 403);

            return $enrollments->firstWhere('id', $selectedEnrollmentId);
        }

        if ($enrollments->count() > 1) {
            $this->addError('selectedEnrollmentId', __('workflow.memorization.errors.select_group'));

            return null;
        }

        return $enrollments->first();
    }

    protected function availableQuickEntryEnrollmentsForStudent(int $studentId)
    {
        return $this->quickEntryEnrollmentsQuery(
            Enrollment::query()
                ->with(['student', 'group.course', 'group.teacher', 'group.assistantTeacher'])
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->latest('enrolled_at')
                ->latest('id')
        )->get();
    }

    protected function selectedQuickEntryEnrollment($availableEnrollments): ?Enrollment
    {
        if ($availableEnrollments->isEmpty()) {
            return null;
        }

        if ($this->selectedEnrollmentId) {
            return $availableEnrollments->firstWhere('id', $this->selectedEnrollmentId);
        }

        return $availableEnrollments->count() === 1
            ? $availableEnrollments->first()
            : null;
    }

    protected function resolveQuickEntryTeacher(Enrollment $enrollment): ?Teacher
    {
        return $this->currentTeacher()
            ?: $enrollment->group?->teacher
            ?: $enrollment->group?->assistantTeacher;
    }

    public function closeDuplicateModal(): void
    {
        $this->showDuplicateModal = false;
        $this->duplicatePages = [];
        $this->uniquePages = [];
        $this->pendingMemorizationPayload = [];
        $this->pendingEnrollmentId = null;
    }

    protected function openDuplicateModal(Enrollment $enrollment, array $payload, array $duplicatePages): void
    {
        $pageNumbers = range((int) $payload['from_page'], (int) $payload['to_page']);

        $this->duplicatePages = $duplicatePages;
        $this->uniquePages = array_values(array_diff($pageNumbers, $duplicatePages));
        $this->pendingMemorizationPayload = $payload;
        $this->pendingEnrollmentId = $enrollment->id;
        $this->showDuplicateModal = true;
        $this->resetValidation();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.memorization.quick_entry.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.memorization.quick_entry.subtitle') }}</p>

        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.memorization.form.entry_type') }}: {{ __('workflow.common.entry_type.new') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.common.labels.recorded_on', ['value' => now()->format('d-m-Y')]) }}</span>
        </div>

        @if (session('status'))
            <div class="flash-success mt-6 px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="mt-6 rounded-3xl border border-red-400/25 bg-red-500/12 px-4 py-3 text-sm text-red-100">{{ session('error') }}</div>
        @endif
    </section>

    <section class="surface-panel mx-auto w-full max-w-4xl p-6 lg:p-8">
        <div class="mb-6 text-center">
            <div class="eyebrow">{{ __('workflow.memorization.quick_entry.card_eyebrow') }}</div>
            <h2 class="font-display mt-3 text-3xl text-white">{{ __('workflow.memorization.quick_entry.card_title') }}</h2>
            @if ($currentTeacher)
                <p class="mt-3 text-sm leading-7 text-neutral-300">
                    {{ __('workflow.memorization.quick_entry.teacher_context', ['name' => trim($currentTeacher->first_name.' '.$currentTeacher->last_name)]) }}
                </p>
            @else
                <p class="mt-3 text-sm leading-7 text-neutral-300">
                    {{ __('workflow.memorization.quick_entry.operator_context', ['name' => $currentUser?->name ?? $currentUser?->username ?? __('crud.common.not_available')]) }}
                </p>
            @endif
            <p class="mt-2 text-sm leading-7 text-neutral-400">{{ __('workflow.memorization.quick_entry.auto_context') }}</p>
        </div>

        <form wire:submit="save" class="space-y-5">
            <div class="admin-form-field">
                <label for="quick-memorization-student">{{ __('workflow.memorization.quick_entry.form.student') }}</label>
                <select id="quick-memorization-student" wire:model="selectedStudentId">
                    <option value="">{{ __('workflow.memorization.workbench.form.select_student') }}</option>
                    @foreach ($studentOptions as $student)
                        <option
                            value="{{ $student->id }}"
                            data-search="{{ trim(implode(' ', array_filter([$student->student_number, $student->parentProfile?->father_name, $student->first_name, $student->last_name]))) }}"
                        >
                            {{ $student->first_name }} {{ $student->last_name }}
                            @if ($student->student_number)
                                - #{{ $student->student_number }}
                            @endif
                            @if ($student->parentProfile?->father_name)
                                - {{ $student->parentProfile->father_name }}
                            @endif
                        </option>
                    @endforeach
                </select>
                @error('selectedStudentId')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            @if ($selectedStudentId && $availableEnrollments->count() > 1)
                <div class="admin-form-field">
                    <label for="quick-memorization-enrollment">{{ __('workflow.memorization.workbench.form.group') }}</label>
                    <select id="quick-memorization-enrollment" wire:model="selectedEnrollmentId">
                        <option value="">{{ __('workflow.memorization.workbench.form.select_group') }}</option>
                        @foreach ($availableEnrollments as $enrollment)
                            <option value="{{ $enrollment->id }}">
                                {{ $enrollment->group?->name ?: __('crud.common.not_available') }}
                                @if ($enrollment->group?->course?->name)
                                    - {{ $enrollment->group->course->name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('selectedEnrollmentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @elseif ($selectedEnrollment)
                <div class="rounded-3xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-neutral-300">
                    <div>{{ __('workflow.memorization.workbench.form.group_auto') }}</div>
                    <div class="mt-2 font-medium text-white">
                        {{ $selectedEnrollment->group?->name ?: __('crud.common.not_available') }}
                    </div>
                    @if ($selectedEnrollment->group?->course?->name)
                        <div class="mt-1 text-xs text-neutral-400">{{ $selectedEnrollment->group->course->name }}</div>
                    @endif
                    @if ($selectedTeacher)
                        <div class="mt-2 text-xs text-neutral-400">
                            {{ __('workflow.memorization.quick_entry.group_teacher_context', ['name' => trim($selectedTeacher->first_name.' '.$selectedTeacher->last_name)]) }}
                        </div>
                    @endif
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <div class="admin-form-field">
                    <label for="quick-memorization-from">{{ __('workflow.memorization.form.from_page') }}</label>
                    <input id="quick-memorization-from" wire:model="from_page" type="number" min="1" max="604" inputmode="numeric" class="px-5 py-4 text-base">
                    @error('from_page')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="admin-form-field">
                    <label for="quick-memorization-to">{{ __('workflow.memorization.form.to_page') }}</label>
                    <input id="quick-memorization-to" wire:model="to_page" type="number" min="1" max="604" inputmode="numeric" class="px-5 py-4 text-base">
                    @error('to_page')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="admin-action-cluster admin-action-cluster--end">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.memorization.quick_entry.form.save') }}</button>
            </div>
        </form>
    </section>

    <x-admin.modal
        :show="$showDuplicateModal"
        :title="__('workflow.memorization.duplicates.title')"
        :description="__('workflow.memorization.duplicates.description')"
        close-method="closeDuplicateModal"
        max-width="3xl"
    >
        <div class="space-y-4 text-sm text-neutral-300">
            <div class="rounded-2xl border border-amber-300/25 bg-amber-500/10 px-4 py-3 text-amber-100">
                {{ __('workflow.memorization.errors.duplicate_pages', ['pages' => implode(', ', $duplicatePages)]) }}
            </div>

            @if ($uniquePages !== [])
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-500/10 px-4 py-3 text-emerald-100">
                    {{ __('workflow.memorization.duplicates.unique_pages', ['pages' => implode(', ', $uniquePages)]) }}
                </div>
            @else
                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-neutral-200">
                    {{ __('workflow.memorization.duplicates.no_unique_pages') }}
                </div>
            @endif
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            @if ($uniquePages !== [])
                <button type="button" wire:click="confirmDuplicateSave" class="pill-link pill-link--accent">
                    {{ __('workflow.memorization.duplicates.save_unique') }}
                </button>
            @endif

            <button type="button" wire:click="closeDuplicateModal" class="pill-link">
                {{ __('crud.common.actions.cancel') }}
            </button>
        </div>
    </x-admin.modal>
</div>
