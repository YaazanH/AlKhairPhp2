<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\MemorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingSessionId = null;
    public ?int $selectedStudentId = null;
    public ?int $selectedEnrollmentId = null;
    public ?int $teacher_id = null;
    public string $recorded_on = '';
    public string $entry_type = 'new';
    public string $from_page = '';
    public string $to_page = '';
    public string $search = '';
    public string $entryTypeFilter = 'all';
    public string $sortField = 'recorded_on';
    public string $sortDirection = 'desc';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showDuplicateModal = false;
    public array $duplicatePages = [];
    public array $uniquePages = [];
    public array $pendingMemorizationPayload = [];
    public ?int $pendingEnrollmentId = null;
    public ?int $pendingSessionId = null;

    protected array $sortableFields = [
        'entry_type',
        'pages_count',
        'recorded_on',
        'student',
        'teacher',
    ];

    public function mount(): void
    {
        $this->authorizePermission('memorization.view');
        $this->resetForm();
    }

    public function with(): array
    {
        $sessionsQuery = $this->scopeMemorizationSessionsQuery(
            MemorizationSession::query()
                ->with([
                    'enrollment.group.course',
                    'student.parentProfile',
                    'teacher',
                ])
        )
            ->when(filled($this->search), function (Builder $query) {
                $search = '%'.$this->search.'%';

                $query->where(function (Builder $builder) use ($search) {
                    $builder
                        ->whereHas('student', function (Builder $studentQuery) use ($search) {
                            $studentQuery
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        })
                        ->orWhereHas('enrollment.group', fn (Builder $groupQuery) => $groupQuery->where('name', 'like', $search))
                        ->orWhereHas('teacher', function (Builder $teacherQuery) use ($search) {
                            $teacherQuery
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        })
                        ->orWhere('notes', 'like', $search);
                });
            })
            ->when(
                in_array($this->entryTypeFilter, ['new', 'review', 'correction'], true),
                fn (Builder $query) => $query->where('entry_type', $this->entryTypeFilter)
            );
        $this->applySessionSort($sessionsQuery);

        $studentOptions = $this->scopeStudentsQuery(
            Student::query()
                ->with(['parentProfile'])
                ->where('status', 'active')
                ->whereHas('enrollments', function (Builder $query) {
                    $this->scopeEnrollmentsQuery($query)
                        ->where('status', 'active')
                        ->whereHas('group.course', fn (Builder $courseQuery) => $courseQuery->where('is_active', true));
                })
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return [
            'sessions' => $sessionsQuery->paginate($this->perPage),
            'filteredCount' => (clone $sessionsQuery)->count(),
            'studentOptions' => $studentOptions,
            'teachers' => $this->currentTeacher()
                ? collect()
                : $this->scopeTeachersQuery(
                    Teacher::query()
                        ->whereIn('status', ['active', 'inactive'])
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                )->get(),
            'currentTeacher' => $this->currentTeacher(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEntryTypeFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = in_array($field, ['student', 'teacher', 'entry_type'], true) ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function updatedSelectedStudentId(): void
    {
        $this->selectedEnrollmentId = $this->availableEnrollmentsQuery()
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->value('id');

        if ($this->editingSessionId) {
            $this->editingSessionId = null;
        }

        $this->resetValidation([
            'selectedStudentId',
            'selectedEnrollmentId',
            'from_page',
            'to_page',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('memorization.record');
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        $this->resetForm();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->resetForm();
        $this->showFormModal = false;
    }

    public function editSession(int $sessionId): void
    {
        $this->authorizePermission('memorization.record');

        $session = $this->scopeMemorizationSessionsQuery(
            MemorizationSession::query()->with('enrollment.group')
        )->findOrFail($sessionId);

        $this->editingSessionId = $session->id;
        $this->selectedStudentId = $session->student_id;
        $this->selectedEnrollmentId = $session->enrollment_id;
        $this->teacher_id = $session->teacher_id;
        $this->recorded_on = $session->recorded_on?->format('Y-m-d') ?? now()->toDateString();
        $this->entry_type = $session->entry_type;
        $this->from_page = (string) $session->from_page;
        $this->to_page = (string) $session->to_page;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizePermission('memorization.record');

        if (! $this->editingSessionId) {
            \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        }

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'teacher_id' => [$this->currentTeacher() ? 'nullable' : 'required', 'exists:teachers,id'],
            'recorded_on' => ['required', 'date'],
            'entry_type' => ['required', 'in:new,review,correction'],
            'from_page' => ['required', 'integer', 'between:1,604'],
            'to_page' => ['required', 'integer', 'between:1,604', 'gte:from_page'],
        ], [], [
            'selectedStudentId' => __('workflow.memorization.workbench.form.student'),
            'selectedEnrollmentId' => __('workflow.memorization.workbench.form.group'),
        ]);

        $student = $this->scopeStudentsQuery(Student::query()->where('status', 'active'))->findOrFail($validated['selectedStudentId']);
        $this->authorizeScopedStudentAccess($student);

        $availableEnrollmentIds = $this->availableEnrollmentsQuery()
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($availableEnrollmentIds === []) {
            $this->addError('selectedStudentId', __('workflow.memorization.errors.no_active_enrollment'));

            return;
        }

        if (! $validated['selectedEnrollmentId']) {
            $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
            $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];
        }

        abort_unless(in_array((int) $validated['selectedEnrollmentId'], $availableEnrollmentIds, true), 403);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['student', 'group.teacher'])
        )->findOrFail((int) $validated['selectedEnrollmentId']);

        $teacherId = $this->resolveTeacherId($validated);

        if (! $teacherId) {
            $this->addError('teacher_id', __('validation.required', ['attribute' => __('workflow.memorization.form.teacher')]));

            return;
        }

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $session = $this->editingSessionId
            ? $this->scopeMemorizationSessionsQuery(
                MemorizationSession::query()->where('enrollment_id', $enrollment->id)
            )->findOrFail($this->editingSessionId)
            : null;

        $payload = [
            'teacher_id' => $teacherId,
            'recorded_on' => $validated['recorded_on'],
            'entry_type' => $validated['entry_type'],
            'from_page' => $validated['from_page'],
            'to_page' => $validated['to_page'],
        ];

        $service = app(MemorizationService::class);
        $duplicatePages = $service->findDuplicatePages(
            $enrollment,
            range((int) $validated['from_page'], (int) $validated['to_page']),
            $validated['entry_type'],
            $session,
        );

        if ($duplicatePages !== []) {
            $this->openDuplicateModal($enrollment, $payload, $duplicatePages, $session);

            return;
        }

        $service->saveSession($enrollment, $payload, $session);

        session()->flash(
            'status',
            $this->editingSessionId
                ? __('workflow.memorization.messages.updated')
                : __('workflow.memorization.messages.saved'),
        );

        $this->closeFormModal();
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

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['student', 'group.teacher'])
        )->findOrFail($this->pendingEnrollmentId);

        $session = $this->pendingSessionId
            ? $this->scopeMemorizationSessionsQuery(
                MemorizationSession::query()->where('enrollment_id', $enrollment->id)
            )->findOrFail($this->pendingSessionId)
            : null;

        app(MemorizationService::class)->saveSession(
            $enrollment,
            $this->pendingMemorizationPayload,
            $session,
            true,
        );

        session()->flash(
            'status',
            $this->pendingSessionId
                ? __('workflow.memorization.messages.updated_partial', ['pages' => implode(', ', $this->duplicatePages)])
                : __('workflow.memorization.messages.saved_partial', ['pages' => implode(', ', $this->duplicatePages)]),
        );

        $this->closeFormModal();
    }

    public function deleteSession(int $sessionId): void
    {
        $this->authorizePermission('memorization.record');

        $session = $this->scopeMemorizationSessionsQuery(
            MemorizationSession::query()->with(['student', 'pages'])
        )->findOrFail($sessionId);

        $student = $session->student;

        app(MemorizationService::class)->deleteSession($session);

        if ($this->editingSessionId === $sessionId) {
            $this->closeFormModal();
        }

        session()->flash('status', __('workflow.memorization.messages.deleted'));
    }

    public function resetForm(): void
    {
        $this->editingSessionId = null;
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->recorded_on = now()->toDateString();
        $this->entry_type = 'new';
        $this->from_page = '';
        $this->to_page = '';

        $this->closeDuplicateModal();
        $this->resetValidation();
    }

    public function closeDuplicateModal(): void
    {
        $this->showDuplicateModal = false;
        $this->duplicatePages = [];
        $this->uniquePages = [];
        $this->pendingMemorizationPayload = [];
        $this->pendingEnrollmentId = null;
        $this->pendingSessionId = null;
    }

    protected function availableEnrollmentsQuery(): Builder
    {
        return $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->where('status', 'active')
                ->whereHas('group.course', fn (Builder $courseQuery) => $courseQuery->where('is_active', true))
                ->when($this->selectedStudentId, fn (Builder $query) => $query->where('student_id', $this->selectedStudentId))
                ->when(! $this->selectedStudentId, fn (Builder $query) => $query->whereRaw('1 = 0'))
        );
    }

    protected function currentTeacher(): ?Teacher
    {
        return auth()->user()?->teacherProfile;
    }

    protected function applySessionSort(Builder $query): void
    {
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        match ($this->sortField) {
            'entry_type' => $query->orderBy('entry_type', $direction)->orderByDesc('id'),
            'pages_count' => $query->orderBy('pages_count', $direction)->orderByDesc('id'),
            'student' => $query
                ->orderBy(
                    Student::query()
                        ->select('first_name')
                        ->whereColumn('students.id', 'memorization_sessions.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy(
                    Student::query()
                        ->select('last_name')
                        ->whereColumn('students.id', 'memorization_sessions.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
            'teacher' => $query
                ->orderBy(
                    Teacher::query()
                        ->select('first_name')
                        ->whereColumn('teachers.id', 'memorization_sessions.teacher_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy(
                    Teacher::query()
                        ->select('last_name')
                        ->whereColumn('teachers.id', 'memorization_sessions.teacher_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
            default => $query->orderBy('recorded_on', $direction)->orderBy('id', $direction),
        };
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    protected function resolveTeacherId(array $validated): ?int
    {
        if ($this->currentTeacher() && ! $this->editingSessionId) {
            return $this->currentTeacher()->id;
        }

        return filled($validated['teacher_id'] ?? null)
            ? (int) $validated['teacher_id']
            : ($this->teacher_id ?: $this->currentTeacher()?->id);
    }

    protected function openDuplicateModal(
        Enrollment $enrollment,
        array $payload,
        array $duplicatePages,
        ?MemorizationSession $session = null,
    ): void {
        $pageNumbers = range((int) $payload['from_page'], (int) $payload['to_page']);

        $this->duplicatePages = $duplicatePages;
        $this->uniquePages = array_values(array_diff($pageNumbers, $duplicatePages));
        $this->pendingMemorizationPayload = $payload;
        $this->pendingEnrollmentId = $enrollment->id;
        $this->pendingSessionId = $session?->id;
        $this->showDuplicateModal = true;
        $this->resetValidation();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.memorization.workbench.title') }}</h1>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('workflow.memorization.workbench.table.title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                <div class="admin-filter-field">
                    <label class="sr-only" for="memorization-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="memorization-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="memorization-entry-filter">{{ __('workflow.memorization.workbench.filters.entry_type') }}</label>
                    <select id="memorization-entry-filter" wire:model.live="entryTypeFilter">
                        <option value="all">{{ __('workflow.memorization.workbench.filters.all_types') }}</option>
                        <option value="new">{{ __('workflow.common.entry_type.new') }}</option>
                        <option value="review">{{ __('workflow.common.entry_type.review') }}</option>
                        <option value="correction">{{ __('workflow.common.entry_type.correction') }}</option>
                    </select>
                </div>

                </div>
        </div>

        @if ($sessions->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.memorization.workbench.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.memorization.workbench.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.workbench.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('recorded_on')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.memorization.workbench.table.headers.date') }} <span>{{ $this->sortIndicator('recorded_on') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('entry_type')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.memorization.workbench.table.headers.type') }} <span>{{ $this->sortIndicator('entry_type') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('pages_count')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.memorization.workbench.table.headers.pages') }} <span>{{ $this->sortIndicator('pages_count') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('teacher')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.memorization.workbench.table.headers.teacher') }} <span>{{ $this->sortIndicator('teacher') }}</span>
                                </button>
                            </th>
                            @can('memorization.record')
                                <th class="admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('workflow.memorization.workbench.table.headers.actions') }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($session->student)
                                        <div class="student-inline">
                                            <x-student-avatar :student="$session->student" size="sm" />
                                            <div class="student-inline__body">
                                                <div class="student-inline__name">{{ $session->student->full_name }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div class="font-medium text-white">{{ $session->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->recorded_on?->format('d-m-Y') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.common.entry_type.'.$session->entry_type) }}</span></td>
                                <td class="px-5 py-4 text-white lg:px-6">
                                    @if ((int) $session->from_page === (int) $session->to_page)
                                        <bdi dir="ltr">{{ $session->from_page }}</bdi>
                                    @else
                                        <span dir="ltr" class="inline-flex items-center gap-1.5"><span>({{ $session->pages_count }})</span><bdi>{{ $session->from_page }} - {{ $session->to_page }}</bdi></span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->teacher?->first_name }} {{ $session->teacher?->last_name }}</td>
                                @can('memorization.record')
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-wrap justify-center gap-2">
                                            <button type="button" wire:click="editSession({{ $session->id }})" class="admin-icon-button" title="{{ __('workflow.common.actions.edit') }}" aria-label="{{ __('workflow.common.actions.edit') }}"><x-admin-action-icon name="edit" /></button>
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($sessions->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $sessions->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingSessionId ? __('workflow.memorization.workbench.form.edit_title') : __('workflow.memorization.workbench.form.title')"
        :description="__('workflow.memorization.workbench.form.help')"
        close-method="closeFormModal"
        max-width="5xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="memorization-student" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.workbench.form.student') }}</label>
                    <select id="memorization-student" wire:model.live="selectedStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.memorization.workbench.form.select_student') }}</option>
                        @foreach ($studentOptions as $student)
                            <option value="{{ $student->id }}">{{ $student->full_name }}</option>
                        @endforeach
                    </select>
                    @error('selectedStudentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                @if ($currentTeacher)
                    <div>
                        <label for="memorization-teacher-readonly" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.teacher') }}</label>
                        <input id="memorization-teacher-readonly" type="text" value="{{ $currentTeacher->first_name }} {{ $currentTeacher->last_name }}" readonly class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>
                @else
                    <div>
                    <label for="memorization-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.teacher') }}</label>
                    <select id="memorization-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.memorization.form.select_teacher') }}</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('teacher_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                    </div>
                @endif
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="memorization-recorded-on" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.recorded_on') }}</label>
                    <input id="memorization-recorded-on" wire:model="recorded_on" value="{{ $recorded_on }}" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('recorded_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="memorization-entry-type" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.entry_type') }}</label>
                    <select id="memorization-entry-type" wire:model="entry_type" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="new">{{ __('workflow.common.entry_type.new') }}</option>
                        <option value="review">{{ __('workflow.common.entry_type.review') }}</option>
                        <option value="correction">{{ __('workflow.common.entry_type.correction') }}</option>
                    </select>
                    @error('entry_type')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="memorization-from-page" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.from_page') }}</label>
                    <input id="memorization-from-page" wire:model="from_page" type="number" min="1" max="604" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('from_page')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="memorization-to-page" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.to_page') }}</label>
                    <input id="memorization-to-page" wire:model="to_page" type="number" min="1" max="604" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('to_page')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="memorization-modal-actions flex w-full flex-wrap items-center gap-3">
                @if ($editingSessionId)
                    <x-delete-action-button
                        wire:click="deleteSession({{ $editingSessionId }})"
                        wire:confirm="{{ __('crud.common.confirm_delete.message') }}"
                        :label="__('crud.common.actions.delete')"
                        data-memorization-session-delete-action
                    />
                    <button
                        type="submit"
                        class="admin-icon-button admin-icon-button--accent admin-modal-action-button"
                        title="{{ __('workflow.common.actions.update_memorization') }}"
                        aria-label="{{ __('workflow.common.actions.update_memorization') }}"
                        data-memorization-session-save-action
                    >
                        <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                    </button>
                @else
                    <x-admin.create-and-new-button />
                @endif
            </div>
        </form>
    </x-admin.modal>

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
