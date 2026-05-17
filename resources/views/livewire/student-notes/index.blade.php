<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;

    public ?int $editingId = null;
    public ?int $student_id = null;
    public ?int $enrollment_id = null;
    public string $source = '';
    public string $visibility = '';
    public string $noted_at = '';
    public string $body = '';
    public ?int $filter_student_id = null;
    public string $filter_source = '';
    public string $filter_visibility = '';
    public ?int $context_student_id = null;
    public ?int $context_enrollment_id = null;
    public bool $showForm = false;

    public function mount(): void
    {
        $this->authorizePermission('student-notes.view');
        $this->applyRequestedContext();
        $this->cancel();
    }

    public function with(): array
    {
        $students = $this->availableStudentsQuery()
            ->with('parentProfile')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $enrollmentsQuery = $this->availableEnrollmentsQuery()
            ->with(['group.course'])
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id');

        if ($this->student_id) {
            $enrollmentsQuery->where('student_id', $this->student_id);
        } else {
            $enrollmentsQuery->whereRaw('1 = 0');
        }

        $notesQuery = $this->availableNotesQuery();

        if ($this->filter_student_id) {
            $notesQuery->where('student_id', $this->filter_student_id);
        }

        if ($this->filter_source !== '') {
            $notesQuery->where('source', $this->filter_source);
        }

        if ($this->filter_visibility !== '') {
            $notesQuery->where('visibility', $this->filter_visibility);
        }

        return [
            'students' => $students,
            'enrollments' => $enrollmentsQuery->get(),
            'notes' => $notesQuery->get(),
            'filterSourceOptions' => $this->filterSourceOptions(),
            'formSourceOptions' => $this->formSourceOptions(),
            'teacherMode' => $this->isTeacherRole(),
            'totals' => [
                'all' => $this->availableNotesQuery()->count(),
                'parent_visible' => $this->availableNotesQuery()->where('visibility', 'visible_to_parent')->count(),
                'today' => $this->availableNotesQuery()->whereDate('noted_at', now()->toDateString())->count(),
            ],
            'visibilityOptions' => $this->visibilityOptions(),
        ];
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
            'source' => ['required', Rule::in(array_keys($this->formSourceOptions()))],
            'visibility' => ['required', Rule::in(array_keys($this->visibilityOptions()))],
            'noted_at' => ['required', 'date'],
            'body' => ['required', 'string'],
        ];
    }

    public function updatedStudentId(): void
    {
        $this->enrollment_id = null;
        $this->resetValidation('enrollment_id');
    }

    public function create(): void
    {
        $this->authorizePermission('student-notes.create');

        $this->cancel(closeForm: false);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'student-notes.update' : 'student-notes.create');

        $validated = $this->validate();
        $student = $this->findAvailableStudent((int) $validated['student_id']);
        $enrollment = $validated['enrollment_id']
            ? $this->findAvailableEnrollment((int) $validated['enrollment_id'], $student->id)
            : null;

        $note = $this->editingId
            ? $this->findAvailableNote($this->editingId)
            : null;

        if ($note) {
            $this->authorizeExistingNoteChange($note);
        }

        StudentNote::query()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'student_id' => $student->id,
                'enrollment_id' => $enrollment?->id,
                'author_id' => $note?->author_id ?? auth()->id(),
                'source' => $validated['source'],
                'visibility' => $validated['visibility'],
                'body' => trim($validated['body']),
                'noted_at' => $validated['noted_at'],
            ],
        );

        session()->flash(
            'status',
            $this->editingId ? __('notes.messages.updated') : __('notes.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $noteId): void
    {
        $this->authorizePermission('student-notes.update');

        $note = $this->findAvailableNote($noteId);
        $this->authorizeExistingNoteChange($note);

        $this->editingId = $note->id;
        $this->student_id = $note->student_id;
        $this->enrollment_id = $note->enrollment_id;
        $this->source = $note->source;
        $this->visibility = $note->visibility;
        $this->noted_at = $note->noted_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i');
        $this->body = $note->body;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function cancel(bool $closeForm = true): void
    {
        $this->editingId = null;
        $this->student_id = $this->context_student_id;
        $this->enrollment_id = $this->context_enrollment_id;
        $this->source = $this->defaultSource();
        $this->visibility = $this->defaultVisibility();
        $this->noted_at = now()->format('Y-m-d\TH:i');
        $this->body = '';

        if ($this->context_student_id) {
            $this->filter_student_id = $this->context_student_id;
        }

        if ($closeForm) {
            $this->showForm = false;
        }

        $this->resetValidation();
    }

    public function clearFilters(): void
    {
        $this->filter_student_id = $this->context_student_id;
        $this->filter_source = '';
        $this->filter_visibility = '';
    }

    public function delete(int $noteId): void
    {
        $this->authorizePermission('student-notes.delete');

        $note = $this->findAvailableNote($noteId);
        $this->authorizeExistingNoteChange($note);
        $note->delete();

        if ($this->editingId === $noteId) {
            $this->cancel();
        }

        session()->flash('status', __('notes.messages.deleted'));
    }

    protected function applyRequestedContext(): void
    {
        $studentId = request()->integer('student') ?: null;
        $enrollmentId = request()->integer('enrollment') ?: null;

        if ($enrollmentId) {
            $enrollment = $this->findAvailableEnrollment($enrollmentId);
            $this->context_student_id = $enrollment->student_id;
            $this->context_enrollment_id = $enrollment->id;
            $this->filter_student_id = $enrollment->student_id;

            return;
        }

        if ($studentId) {
            $student = $this->findAvailableStudent($studentId);
            $this->context_student_id = $student->id;
            $this->filter_student_id = $student->id;
        }
    }

    protected function authorizeExistingNoteChange(StudentNote $note): void
    {
        if ($this->accessScopes()->isUnrestricted(auth()->user())) {
            return;
        }

        abort_unless($note->author_id === auth()->id(), 403);
    }

    protected function availableEnrollmentsQuery()
    {
        return $this->scopeEnrollmentsQuery(Enrollment::query());
    }

    protected function availableNotesQuery()
    {
        return $this->scopeStudentNotesQuery(
            StudentNote::query()
                ->with(['author', 'enrollment.group.course', 'student.parentProfile'])
                ->latest('noted_at')
                ->latest('id')
        );
    }

    protected function availableStudentsQuery()
    {
        return $this->scopeStudentsQuery(Student::query());
    }

    protected function defaultSource(): string
    {
        return $this->isTeacherRole() ? 'teacher' : 'management';
    }

    protected function defaultVisibility(): string
    {
        return $this->isTeacherRole() ? 'private_teacher' : 'shared_internal';
    }

    protected function filterSourceOptions(): array
    {
        return [
            'management' => __('notes.sources.management'),
            'teacher' => __('notes.sources.teacher'),
            'parent' => __('notes.sources.parent'),
            'system' => __('notes.sources.system'),
        ];
    }

    protected function findAvailableEnrollment(int $enrollmentId, ?int $studentId = null): Enrollment
    {
        $query = $this->availableEnrollmentsQuery();

        if ($studentId) {
            $query->where('student_id', $studentId);
        }

        $enrollment = $query->find($enrollmentId);

        abort_unless($enrollment, 403);

        return $enrollment;
    }

    protected function findAvailableNote(int $noteId): StudentNote
    {
        $note = $this->availableNotesQuery()->find($noteId);

        abort_unless($note, 403);

        return $note;
    }

    protected function findAvailableStudent(int $studentId): Student
    {
        $student = $this->availableStudentsQuery()->find($studentId);

        abort_unless($student, 403);

        return $student;
    }

    protected function formSourceOptions(): array
    {
        if ($this->isTeacherRole()) {
            return [
                'teacher' => __('notes.sources.teacher'),
            ];
        }

        return $this->filterSourceOptions();
    }

    protected function isTeacherRole(): bool
    {
        return auth()->user()?->teacherProfile !== null;
    }

    protected function visibilityOptions(): array
    {
        $options = [
            'private_teacher' => __('notes.visibility.private_teacher'),
            'private_management' => __('notes.visibility.private_management'),
            'shared_internal' => __('notes.visibility.shared_internal'),
            'visible_to_parent' => __('notes.visibility.visible_to_parent'),
        ];

        if (! $this->isTeacherRole()) {
            return $options;
        }

        unset($options['private_management']);

        return $options;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.students') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('notes.heading') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('notes.subheading') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('notes.stats.all') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['all']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('notes.stats.parent_visible') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['parent_visible']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('notes.stats.today') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['today']) }}</div>
        </article>
    </section>

    <div class="space-y-6">
        @if ($showForm)
        <section class="admin-modal" role="dialog" aria-modal="true">
            <div class="admin-modal__backdrop" wire:click="cancel"></div>
            <div class="admin-modal__viewport">
                <div class="admin-modal__dialog admin-modal__dialog--3xl">
                    <div class="admin-modal__header">
                        <div>
                            <div class="admin-modal__title">{{ $editingId ? __('notes.form.edit_title') : __('notes.form.create_title') }}</div>
                            <p class="admin-modal__description">{{ $teacherMode ? __('notes.form.teacher_help') : __('notes.form.manager_help') }}</p>
                        </div>
                        <button type="button" wire:click="cancel" class="admin-modal__close" aria-label="{{ __('crud.common.actions.cancel') }}">×</button>
                    </div>
                    <div class="admin-modal__body">
            @if (auth()->user()->can('student-notes.create') || auth()->user()->can('student-notes.update'))
                <div class="mb-4 md:hidden">
                    <h2 class="text-lg font-semibold">{{ $editingId ? __('notes.form.edit_title') : __('notes.form.create_title') }}</h2>
                    <p class="text-sm text-neutral-500">
                        @if ($teacherMode)
                            {{ __('notes.form.teacher_help') }}
                        @else
                            {{ __('notes.form.manager_help') }}
                        @endif
                    </p>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.student') }}</label>
                        <select wire:model="student_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('notes.form.placeholders.student') }}</option>
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}">{{ $student->first_name }} {{ $student->last_name }}{{ $student->parentProfile?->father_name ? ' | '.$student->parentProfile->father_name : '' }}</option>
                            @endforeach
                        </select>
                        @error('student_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.enrollment') }}</label>
                        <select wire:model="enrollment_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('notes.form.placeholders.enrollment') }}</option>
                            @foreach ($enrollments as $enrollment)
                                <option value="{{ $enrollment->id }}">{{ $enrollment->group?->name ?: __('notes.log.unknown_group') }}{{ $enrollment->group?->course ? ' | '.$enrollment->group->course->name : '' }}</option>
                            @endforeach
                        </select>
                        @error('enrollment_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.source') }}</label>
                            <select wire:model="source" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                @foreach ($formSourceOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('source') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.visibility') }}</label>
                            <select wire:model="visibility" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                @foreach ($visibilityOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('visibility') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.noted_at') }}</label>
                        <input wire:model="noted_at" type="datetime-local" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('noted_at') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.body') }}</label>
                        <textarea wire:model="body" rows="6" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        @error('body') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                            {{ $editingId ? __('notes.form.update_submit') : __('notes.form.create_submit') }}
                        </button>
                        <x-admin.create-and-new-button :show="! $editingId" click="saveAndNew('save', 'create')" />

                        @if ($editingId)
                            <button type="button" wire:click="cancel" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">
                                {{ __('crud.common.actions.cancel') }}
                            </button>
                        @endif
                    </div>
                </form>
            @else
                <div class="text-sm text-neutral-500">{{ __('notes.read_only') }}</div>
            @endif
                    </div>
                </div>
            </div>
        </section>
        @endif

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('notes.log.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('notes.log.subtitle') }}</div>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <select wire:model.live="filter_student_id" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option value="">{{ __('notes.log.filters.all_students') }}</option>
                        @foreach ($students as $student)
                            <option value="{{ $student->id }}">{{ $student->first_name }} {{ $student->last_name }}</option>
                        @endforeach
                    </select>

                    <select wire:model.live="filter_source" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option value="">{{ __('notes.log.filters.all_sources') }}</option>
                        @foreach ($filterSourceOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <div class="flex gap-3">
                        <select wire:model.live="filter_visibility" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('notes.log.filters.all_visibility') }}</option>
                            @foreach ($visibilityOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        <button type="button" wire:click="clearFilters" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700">
                            {{ __('notes.log.filters.clear') }}
                        </button>
                    </div>
                </div>

                @can('student-notes.create')
                    <button type="button" wire:click="create" class="pill-link pill-link--accent">
                        {{ __('notes.form.create_title') }}
                    </button>
                @endcan
            </div>

            @if ($notes->isEmpty())
                <div class="px-5 py-10 text-sm text-neutral-500">
                    {{ __('notes.log.empty') }}
                </div>
            @else
                <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($notes as $note)
                        @php
                            $canMutate = auth()->user()->can('student-notes.update') && (! $teacherMode || $note->author_id === auth()->id());
                            $canDelete = auth()->user()->can('student-notes.delete') && (! $teacherMode || $note->author_id === auth()->id());
                        @endphp

                        <article class="space-y-4 px-5 py-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="font-semibold">{{ $note->student?->first_name }} {{ $note->student?->last_name }}</span>
                                        <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium capitalize text-neutral-600 dark:bg-neutral-900 dark:text-neutral-300">
                                            {{ __('notes.sources.'.$note->source) }}
                                        </span>
                                        <span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-medium capitalize text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">
                                            {{ __('notes.visibility.'.$note->visibility) }}
                                        </span>
                                    </div>

                                    <div class="text-xs text-neutral-500">
                                        {{ $note->noted_at?->format('Y-m-d H:i') ?: '-' }}
                                        | {{ $note->author?->name ?: __('notes.log.unknown_author') }}
                                        | {{ $note->enrollment?->group?->name ?: __('notes.log.general_note') }}
                                    </div>
                                </div>

                                @if ($canMutate || $canDelete)
                                    <div class="flex gap-2">
                                        @if ($canMutate)
                                            <button type="button" wire:click="edit({{ $note->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700">
                                                {{ __('crud.common.actions.edit') }}
                                            </button>
                                        @endif

                                        @if ($canDelete)
                                            <button type="button" wire:click="delete({{ $note->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-700 dark:border-red-800 dark:text-red-300">
                                                {{ __('crud.common.actions.delete') }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="rounded-xl bg-neutral-50 px-4 py-3 text-sm leading-6 text-neutral-700 dark:bg-neutral-900/70 dark:text-neutral-200">
                                {{ $note->body }}
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
