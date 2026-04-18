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
    public string $notes = '';
    public string $search = '';
    public string $entryTypeFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;

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
            )
            ->latest('recorded_on')
            ->latest('id');

        $studentOptions = $this->scopeStudentsQuery(
            Student::query()
                ->with(['parentProfile'])
                ->whereHas('enrollments', function (Builder $query) {
                    $this->scopeEnrollmentsQuery($query)->where('status', 'active');
                })
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return [
            'sessions' => $sessionsQuery->paginate($this->perPage),
            'filteredCount' => (clone $sessionsQuery)->count(),
            'studentOptions' => $studentOptions,
            'enrollmentOptions' => $this->availableEnrollmentsQuery()
                ->with(['group.course'])
                ->orderByDesc('enrolled_at')
                ->orderByDesc('id')
                ->get(),
            'teachers' => $this->currentTeacher()
                ? collect()
                : $this->scopeTeachersQuery(
                    Teacher::query()
                        ->whereIn('status', ['active', 'inactive'])
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                )->get(),
            'currentTeacher' => $this->currentTeacher(),
            'stats' => [
                'students' => $studentOptions->count(),
                'pages' => (int) $this->scopeMemorizationSessionsQuery(MemorizationSession::query())->sum('pages_count'),
                'sessions' => $this->scopeMemorizationSessionsQuery(MemorizationSession::query())->count(),
            ],
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

    public function updatedSelectedStudentId(): void
    {
        $enrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->selectedEnrollmentId = count($enrollmentIds) === 1
            ? $enrollmentIds[0]
            : null;

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
        $this->notes = $session->notes ?? '';
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizePermission('memorization.record');

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'teacher_id' => [$this->currentTeacher() ? 'nullable' : 'required', 'exists:teachers,id'],
            'recorded_on' => ['required', 'date'],
            'entry_type' => ['required', 'in:new,review,correction'],
            'from_page' => ['required', 'integer', 'between:1,604'],
            'to_page' => ['required', 'integer', 'between:1,604', 'gte:from_page'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'selectedStudentId' => __('workflow.memorization.workbench.form.student'),
            'selectedEnrollmentId' => __('workflow.memorization.workbench.form.group'),
        ]);

        $student = $this->scopeStudentsQuery(Student::query())->findOrFail($validated['selectedStudentId']);
        $this->authorizeScopedStudentAccess($student);

        $availableEnrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($availableEnrollmentIds === []) {
            $this->addError('selectedStudentId', __('workflow.memorization.errors.no_active_enrollment'));

            return;
        }

        if (! $validated['selectedEnrollmentId']) {
            if (count($availableEnrollmentIds) > 1) {
                $this->addError('selectedEnrollmentId', __('workflow.memorization.errors.select_group'));

                return;
            }

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

        $pageNumbers = range((int) $validated['from_page'], (int) $validated['to_page']);
        $existingPages = \App\Models\StudentPageAchievement::query()
            ->where('student_id', $enrollment->student_id)
            ->whereIn('page_no', $pageNumbers)
            ->when($this->editingSessionId, fn ($query) => $query->where('first_session_id', '!=', $this->editingSessionId))
            ->pluck('page_no')
            ->all();

        if ($validated['entry_type'] !== 'review' && $existingPages) {
            $this->addError('from_page', __('workflow.memorization.errors.duplicate_pages', ['pages' => implode(', ', $existingPages)]));

            return;
        }

        $session = $this->editingSessionId
            ? $this->scopeMemorizationSessionsQuery(
                MemorizationSession::query()->where('enrollment_id', $enrollment->id)
            )->findOrFail($this->editingSessionId)
            : null;

        app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacherId,
            'recorded_on' => $validated['recorded_on'],
            'entry_type' => $validated['entry_type'],
            'from_page' => $validated['from_page'],
            'to_page' => $validated['to_page'],
            'notes' => $validated['notes'] ?? null,
        ], $session);

        session()->flash(
            'status',
            $this->editingSessionId
                ? __('workflow.memorization.messages.updated')
                : __('workflow.memorization.messages.saved'),
        );

        $this->closeFormModal();
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
        $this->notes = '';

        $this->resetValidation();
    }

    protected function availableEnrollmentsQuery(): Builder
    {
        return $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->where('status', 'active')
                ->when($this->selectedStudentId, fn (Builder $query) => $query->where('student_id', $this->selectedStudentId))
                ->when(! $this->selectedStudentId, fn (Builder $query) => $query->whereRaw('1 = 0'))
        );
    }

    protected function currentTeacher(): ?Teacher
    {
        return auth()->user()?->teacherProfile;
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
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.memorization.workbench.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.memorization.workbench.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.memorization.workbench.stats.students') }}: {{ number_format($stats['students']) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.memorization.workbench.stats.pages') }}: {{ number_format($stats['pages']) }}</span>
            <span class="badge-soft">{{ __('workflow.memorization.workbench.stats.sessions') }}: {{ number_format($stats['sessions']) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.memorization.workbench.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.memorization.workbench.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="memorization-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="memorization-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="memorization-entry-filter">{{ __('workflow.memorization.workbench.filters.entry_type') }}</label>
                    <select id="memorization-entry-filter" wire:model.live="entryTypeFilter">
                        <option value="all">{{ __('workflow.memorization.workbench.filters.all_types') }}</option>
                        <option value="new">{{ __('workflow.common.entry_type.new') }}</option>
                        <option value="review">{{ __('workflow.common.entry_type.review') }}</option>
                        <option value="correction">{{ __('workflow.common.entry_type.correction') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('memorization.record')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.memorization.workbench.create') }}</button>
                    @endcan
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.memorization.workbench.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @if ($sessions->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.memorization.workbench.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.workbench.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.workbench.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.workbench.table.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.workbench.table.headers.type') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.workbench.table.headers.pages') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.workbench.table.headers.teacher') }}</th>
                            @can('memorization.record')
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.memorization.workbench.table.headers.actions') }}</th>
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
                                                <div class="student-inline__name">{{ $session->student->first_name }} {{ $session->student->last_name }}</div>
                                                <div class="student-inline__meta">{{ $session->student->parentProfile?->father_name ?: __('crud.common.not_available') }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div class="font-medium text-white">{{ $session->enrollment?->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $session->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->recorded_on?->format('Y-m-d') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.common.entry_type.'.$session->entry_type) }}</span></td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ __('workflow.memorization.table.page_range', ['from' => $session->from_page, 'to' => $session->to_page, 'count' => $session->pages_count]) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->teacher?->first_name }} {{ $session->teacher?->last_name }}</td>
                                @can('memorization.record')
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex justify-end">
                                            <button type="button" wire:click="editSession({{ $session->id }})" class="pill-link pill-link--compact">{{ __('workflow.common.actions.edit') }}</button>
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
            @if ($currentTeacher)
                <div class="soft-callout px-4 py-4 text-sm leading-6">
                    <div class="font-semibold text-white">{{ __('workflow.memorization.workbench.teacher_badge', ['name' => $currentTeacher->first_name.' '.$currentTeacher->last_name]) }}</div>
                    <div class="mt-2 text-neutral-300">{{ __('workflow.memorization.workbench.form.teacher_locked') }}</div>
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="memorization-student" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.workbench.form.student') }}</label>
                    <select id="memorization-student" wire:model.live="selectedStudentId" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.memorization.workbench.form.select_student') }}</option>
                        @foreach ($studentOptions as $student)
                            <option value="{{ $student->id }}">
                                {{ $student->first_name }} {{ $student->last_name }}
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

                <div>
                    <label for="memorization-enrollment" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.workbench.form.group') }}</label>
                    <select id="memorization-enrollment" wire:model="selectedEnrollmentId" class="w-full rounded-xl px-4 py-3 text-sm" @disabled($enrollmentOptions->isEmpty())>
                        <option value="">{{ __('workflow.memorization.workbench.form.select_group') }}</option>
                        @foreach ($enrollmentOptions as $enrollment)
                            <option value="{{ $enrollment->id }}">
                                {{ $enrollment->group?->name ?: __('workflow.common.no_group') }}
                                @if ($enrollment->group?->course?->name)
                                    - {{ $enrollment->group->course->name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @if ($selectedStudentId && $enrollmentOptions->count() === 1)
                        <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.memorization.workbench.form.group_auto') }}</div>
                    @endif
                    @error('selectedEnrollmentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if (! $currentTeacher)
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

            <div class="grid gap-4 md:grid-cols-[12rem_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
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

                <div>
                    <label for="memorization-recorded-on" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.recorded_on') }}</label>
                    <input id="memorization-recorded-on" wire:model="recorded_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('recorded_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

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

            <div>
                <label for="memorization-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.notes') }}</label>
                <textarea id="memorization-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingSessionId ? __('workflow.common.actions.update_memorization') : __('workflow.common.actions.save_memorization') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingSessionId" />
                <button type="button" wire:click="closeFormModal" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>
