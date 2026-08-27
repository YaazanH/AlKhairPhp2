<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
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
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

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
            'notes' => $notesQuery->get(),
            'filterSourceOptions' => $this->filterSourceOptions(),
            'formSourceOptions' => $this->formSourceOptions(),
            'teacherMode' => $this->isTeacherRole(),
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
        $this->noted_at = $note->noted_at?->format('Y-m-d') ?? now()->format('Y-m-d');
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
        $this->noted_at = now()->format('Y-m-d');
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
                ->with('student')
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

    <div class="space-y-6">
        @if ($showForm)
        <section class="admin-modal" role="dialog" aria-modal="true">
            <div class="admin-modal__backdrop" wire:click="cancel"></div>
            <div class="admin-modal__viewport">
                <div class="admin-modal__dialog admin-modal__dialog--3xl">
                    <div class="admin-modal__header">
                        <div>
                            <div class="admin-modal__title">{{ $editingId ? __('notes.form.edit_title') : __('notes.form.create_title') }}</div>
                        </div>
                        <button type="button" wire:click="cancel" class="admin-modal__close" aria-label="{{ __('crud.common.actions.cancel') }}">×</button>
                    </div>
                    <div class="admin-modal__body">
            @if (auth()->user()->can('student-notes.create') || auth()->user()->can('student-notes.update'))
                <form wire:submit="save" class="date-control-peer-group space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.student') }}</label>
                        <select wire:model="student_id" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('notes.form.placeholders.student') }}</option>
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}">{{ trim($student->first_name.' '.$student->last_name) }}</option>
                            @endforeach
                        </select>
                        @error('student_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
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
                        <input wire:model="noted_at" type="date" class="date-control--match-select w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('noted_at') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('notes.form.fields.body') }}</label>
                        <textarea wire:model="body" rows="6" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        @error('body') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="pill-link pill-link--accent">
                            {{ $editingId ? __('notes.form.update_submit') : __('notes.form.create_submit') }}
                        </button>
                        <x-admin.create-and-new-button :show="! $editingId" click="saveAndNew('save', 'create')" />
                        @if ($editingId)
                            @can('student-notes.delete')
                                <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--danger" data-student-note-delete>
                                    {{ __('crud.common.actions.delete') }}
                                </button>
                            @endcan
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
            <div class="admin-grid-meta admin-grid-meta--controls">
                <div class="admin-grid-meta__title">{{ __('notes.log.title') }}</div>

                <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                    <div class="admin-filter-field">
                        <label class="sr-only" for="student-notes-student-filter">{{ __('notes.log.filters.all_students') }}</label>
                        <select id="student-notes-student-filter" wire:model.live="filter_student_id" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}">
                            <option value="">{{ __('notes.log.filters.all_students') }}</option>
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}">{{ trim($student->first_name.' '.$student->last_name) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="admin-filter-field">
                        <label class="sr-only" for="student-notes-source-filter">{{ __('notes.log.filters.all_sources') }}</label>
                        <select id="student-notes-source-filter" wire:model.live="filter_source">
                            <option value="">{{ __('notes.log.filters.all_sources') }}</option>
                            @foreach ($filterSourceOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="admin-filter-field">
                        <label class="sr-only" for="student-notes-visibility-filter">{{ __('notes.log.filters.all_visibility') }}</label>
                        <select id="student-notes-visibility-filter" wire:model.live="filter_visibility">
                            <option value="">{{ __('notes.log.filters.all_visibility') }}</option>
                            @foreach ($visibilityOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    @can('student-notes.create')
                        <div class="admin-toolbar__actions">
                            <button type="button" wire:click="create" class="pill-link pill-link--accent">
                                {{ __('notes.form.create_title') }}
                            </button>
                        </div>
                    @endcan
                </div>
            </div>

            @if ($notes->isEmpty())
                <div class="admin-empty-state">{{ __('notes.log.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="student-notes-table text-sm" data-student-notes-generic-table>
                        <colgroup>
                            <col class="student-notes-table__number-column">
                            <col class="student-notes-table__student-column">
                            <col class="student-notes-table__date-column">
                            <col class="student-notes-table__visibility-column">
                            <col class="student-notes-table__note-column">
                            <col class="student-notes-table__actions-column" data-student-notes-actions-column>
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="student-notes-table__number px-4 py-4 text-left">#</th>
                                <th class="student-notes-table__student px-4 py-4 text-left">{{ __('notes.log.table.student') }}</th>
                                <th class="student-notes-table__date px-4 py-4 text-left">{{ __('notes.log.table.date') }}</th>
                                <th class="student-notes-table__visibility px-4 py-4 text-left">{{ __('notes.log.table.visibility') }}</th>
                                <th class="student-notes-table__note px-4 py-4 text-left">{{ __('notes.log.table.note') }}</th>
                                <th class="student-notes-table__actions px-4 py-4 text-left">{{ __('notes.log.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($notes as $note)
                                @php
                                    $canMutate = auth()->user()->can('student-notes.update') && (! $teacherMode || $note->author_id === auth()->id());
                                @endphp
                                <tr>
                                    <td class="px-4 py-4">{{ $loop->iteration }}</td>
                                    <td class="px-4 py-4 font-semibold">{{ $note->student ? trim($note->student->first_name.' '.$note->student->last_name) : '-' }}</td>
                                    <td class="px-4 py-4"><bdi dir="ltr">{{ $note->noted_at?->format('d-m-Y') ?: '-' }}</bdi></td>
                                    <td class="px-4 py-4">{{ __('notes.visibility.'.$note->visibility) }}</td>
                                    <td class="px-4 py-4">
                                        <div class="student-notes-table__note-content">{{ $note->body }}</div>
                                    </td>
                                    <td class="student-notes-table__actions px-4 py-4">
                                        @if ($canMutate)
                                            <button type="button" wire:click="edit({{ $note->id }})" class="pill-link pill-link--compact" data-student-note-edit>{{ __('crud.common.actions.edit') }}</button>
                                        @else
                                            <span aria-hidden="true">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
