<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $student_id = null;
    public ?int $group_id = null;
    public string $enrolled_at = '';
    public string $status = 'active';
    public string $left_at = '';
    public string $notes = '';
    public string $search = '';
    public string $statusFilter = 'all';
    public string $courseFilter = 'all';
    public string $groupFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;

    public function mount(): void
    {
        $this->authorizePermission('enrollments.view');
    }

    public function with(): array
    {
        $baseQuery = $this->scopeEnrollmentsQuery(Enrollment::query());
        $filteredQuery = $this->scopeEnrollmentsQuery(Enrollment::query())
            ->with(['group.course', 'student'])
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->whereHas('student', fn ($studentQuery) => $studentQuery
                            ->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('group', fn ($groupQuery) => $groupQuery
                            ->where('name', 'like', '%'.$this->search.'%')
                            ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$this->search.'%')));
                });
            })
            ->when($this->courseFilter !== 'all', fn ($query) => $query->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', (int) $this->courseFilter)))
            ->when($this->groupFilter !== 'all', fn ($query) => $query->where('group_id', (int) $this->groupFilter))
            ->when(in_array($this->statusFilter, ['active', 'completed', 'cancelled'], true), fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByDesc('enrolled_at');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'enrollments' => $filteredQuery->paginate($this->perPage),
            'students' => $this->availableStudentsQuery()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name']),
            'groups' => $this->scopeGroupsQuery(Group::query()->with('course'))->orderBy('name')->get(['id', 'course_id', 'name']),
            'filterCourses' => Course::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filterGroups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with('course')
                    ->when($this->courseFilter !== 'all', fn ($query) => $query->where('course_id', (int) $this->courseFilter))
                    ->orderBy('name')
            )->get(['id', 'course_id', 'name']),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeEnrollmentsQuery(Enrollment::query()->where('status', 'active'))->count(),
                'completed' => $this->scopeEnrollmentsQuery(Enrollment::query()->where('status', 'completed'))->count(),
            ],
            'filteredCount' => $filteredCount,
            'statuses' => ['active', 'completed', 'cancelled'],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCourseFilter(): void
    {
        $this->groupFilter = 'all';
        $this->resetPage();
    }

    public function updatedGroupFilter(): void
    {
        $this->resetPage();
    }

    public function updatedGroupId(): void
    {
        if (! $this->student_id) {
            return;
        }

        $studentStillAvailable = $this->availableStudentsQuery()
            ->whereKey($this->student_id)
            ->exists();

        if (! $studentStillAvailable) {
            $this->student_id = null;
        }
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'exists:students,id'],
            'group_id' => ['required', 'exists:groups,id'],
            'enrolled_at' => [
                'required',
                'date',
                Rule::unique('enrollments', 'enrolled_at')
                    ->where(fn ($query) => $query
                        ->where('student_id', $this->student_id)
                        ->where('group_id', $this->group_id))
                    ->ignore($this->editingId),
            ],
            'status' => ['required', 'in:active,completed,cancelled'],
            'left_at' => ['nullable', 'date', 'after_or_equal:enrolled_at'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('enrollments.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'enrollments.update' : 'enrollments.create');

        if ($this->editingId) {
            $this->authorizeScopedEnrollmentAccess(Enrollment::query()->findOrFail($this->editingId));
        }

        $validated = $this->validate();
        $this->authorizeScopedStudentAccess(Student::query()->findOrFail($validated['student_id']));
        $this->authorizeScopedGroupAccess(Group::query()->findOrFail($validated['group_id']));

        $duplicateEnrollmentExists = Enrollment::query()
            ->where('student_id', $validated['student_id'])
            ->where('group_id', $validated['group_id'])
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($duplicateEnrollmentExists) {
            $this->addError('student_id', __('crud.enrollments.errors.already_enrolled'));

            return;
        }

        if ($this->editingId) {
            $validated['left_at'] = $validated['left_at'] ?: null;
        } else {
            $validated['left_at'] = null;
            $validated['notes'] = null;
        }

        Enrollment::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        session()->flash(
            'status',
            $this->editingId ? __('crud.enrollments.messages.updated') : __('crud.enrollments.messages.created'),
        );

        $this->cancel();
    }

    public function saveAndNew(): void
    {
        $preservedGroupId = $this->group_id;
        $errorCount = $this->getErrorBag()->count();

        $this->save();

        if ($this->getErrorBag()->count() > $errorCount) {
            return;
        }

        $this->editingId = null;
        $this->student_id = null;
        $this->group_id = $preservedGroupId;
        $this->enrolled_at = '';
        $this->status = 'active';
        $this->left_at = '';
        $this->notes = '';
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function edit(int $enrollmentId): void
    {
        $this->authorizePermission('enrollments.update');

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);
        $this->authorizeScopedEnrollmentAccess($enrollment);

        $this->editingId = $enrollment->id;
        $this->student_id = $enrollment->student_id;
        $this->group_id = $enrollment->group_id;
        $this->enrolled_at = $enrollment->enrolled_at?->format('Y-m-d') ?? '';
        $this->status = $enrollment->status;
        $this->left_at = $enrollment->left_at?->format('Y-m-d') ?? '';
        $this->notes = $enrollment->notes ?? '';
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->student_id = null;
        $this->group_id = null;
        $this->enrolled_at = '';
        $this->status = 'active';
        $this->left_at = '';
        $this->notes = '';
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function delete(int $enrollmentId): void
    {
        $this->authorizePermission('enrollments.delete');

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);
        $this->authorizeScopedEnrollmentAccess($enrollment);
        $enrollment->delete();

        if ($this->editingId === $enrollmentId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.enrollments.messages.deleted'));
    }

    protected function availableStudentsQuery()
    {
        return $this->scopeStudentsQuery(Student::query())
            ->when($this->group_id, function ($query) {
                $query->whereDoesntHave('enrollments', function ($enrollmentQuery) {
                    $enrollmentQuery
                        ->where('group_id', $this->group_id)
                        ->when($this->editingId, fn ($innerQuery) => $innerQuery->whereKeyNot($this->editingId));
                });
            });
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('crud.enrollments.hero.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.enrollments.hero.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.enrollments.hero.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('crud.enrollments.hero.badges.students_available', ['count' => number_format($students->count())]) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('crud.enrollments.hero.badges.groups_available', ['count' => number_format($groups->count())]) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.enrollments.stats.all.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.enrollments.stats.all.description') }}</p>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.enrollments.stats.active.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.enrollments.stats.active.description') }}</p>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.enrollments.stats.completed.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['completed']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.enrollments.stats.completed.description') }}</p>
        </article>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('crud.enrollments.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('crud.enrollments.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="enrollment-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="enrollment-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="enrollment-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="enrollment-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        @foreach ($statuses as $enrollmentStatus)
                            <option value="{{ $enrollmentStatus }}">{{ __('crud.common.status_options.'.$enrollmentStatus) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="enrollment-course-filter">{{ __('crud.common.filters.course') }}</label>
                    <select id="enrollment-course-filter" wire:model.live="courseFilter">
                        <option value="all">{{ __('crud.common.filters.all_courses') }}</option>
                        @foreach ($filterCourses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="enrollment-group-filter">{{ __('crud.common.filters.group') }}</label>
                    <select id="enrollment-group-filter" wire:model.live="groupFilter">
                        <option value="all">{{ __('crud.common.filters.all_groups') }}</option>
                        @foreach ($filterGroups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' - '.$group->course->name : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('enrollments.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('enrollments.export', ['search' => $search, 'status' => $statusFilter, 'course_id' => $courseFilter, 'group_id' => $groupFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('crud.enrollments.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @if ($enrollments->isEmpty())
            <div class="admin-empty-state">{{ __('crud.enrollments.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.enrollments.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.enrollments.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.enrollments.table.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.enrollments.table.headers.enrolled') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.enrollments.table.headers.status') }}</th>
                            @if (auth()->user()->can('memorization.view') || auth()->user()->can('quran-awqaf-tests.view') || auth()->user()->can('quran-tests.view') || auth()->user()->can('points.view') || auth()->user()->can('enrollments.update') || auth()->user()->can('enrollments.delete'))
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.enrollments.table.headers.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($enrollments as $enrollment)
                            @php
                                $enrollmentStatusClass = match ($enrollment->status) {
                                    'active' => 'status-chip status-chip--emerald',
                                    'completed' => 'status-chip status-chip--gold',
                                    default => 'status-chip status-chip--slate',
                                };
                            @endphp
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($enrollment->student)
                                        <div class="student-inline">
                                            <x-student-avatar :student="$enrollment->student" size="sm" />
                                            <div class="student-inline__body">
                                                <div class="student-inline__name">{{ $enrollment->student->first_name }} {{ $enrollment->student->last_name }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->group?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->group?->course?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->enrolled_at?->format('Y-m-d') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="{{ $enrollmentStatusClass }}">{{ __('crud.common.status_options.'.$enrollment->status) }}</span></td>
                                @if (auth()->user()->can('memorization.view') || auth()->user()->can('quran-awqaf-tests.view') || auth()->user()->can('quran-tests.view') || auth()->user()->can('points.view') || auth()->user()->can('enrollments.update') || auth()->user()->can('enrollments.delete'))
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @can('memorization.view')
                                                <a href="{{ route('enrollments.memorization', $enrollment) }}" wire:navigate class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.memorization') }}
                                                </a>
                                            @endcan
                                            @canany(['quran-awqaf-tests.view', 'quran-tests.view'])
                                                <a href="{{ route('enrollments.quran-tests', $enrollment) }}" wire:navigate class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.tests') }}
                                                </a>
                                            @endcanany
                                            @can('points.view')
                                                <a href="{{ route('enrollments.points', $enrollment) }}" wire:navigate class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.points') }}
                                                </a>
                                            @endcan
                                            @can('enrollments.update')
                                                <button type="button" wire:click="edit({{ $enrollment->id }})" class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.edit') }}
                                                </button>
                                            @endcan
                                            @can('enrollments.delete')
                                                <button type="button" wire:click="delete({{ $enrollment->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                                    {{ __('crud.common.actions.delete') }}
                                                </button>
                                            @endcan
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($enrollments->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $enrollments->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.enrollments.form.edit_title') : __('crud.enrollments.form.create_title')"
        :description="__('crud.enrollments.form.help')"
        close-method="cancel"
        max-width="4xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div>
                <label for="enrollment-group" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.group') }}</label>
                <select id="enrollment-group" wire:model.live="group_id" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('crud.enrollments.form.placeholders.select_group') }}</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' - '.$group->course->name : '' }}</option>
                    @endforeach
                </select>
                @error('group_id')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="enrollment-student" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.student') }}</label>
                <select id="enrollment-student" wire:model="student_id" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('crud.enrollments.form.placeholders.select_student') }}</option>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}">{{ $student->first_name }} {{ $student->last_name }}</option>
                    @endforeach
                </select>
                @if ($group_id && $students->isEmpty())
                    <div class="mt-1 text-sm text-neutral-400">{{ __('crud.enrollments.form.no_available_students') }}</div>
                @endif
                @error('student_id')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="enrollment-date" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.enrolled_at') }}</label>
                    <input id="enrollment-date" wire:model="enrolled_at" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('enrolled_at')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="enrollment-status" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.status') }}</label>
                    <select id="enrollment-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                        @foreach ($statuses as $enrollmentStatus)
                            <option value="{{ $enrollmentStatus }}">{{ __('crud.common.status_options.'.$enrollmentStatus) }}</option>
                        @endforeach
                    </select>
                    @error('status')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if ($editingId)
                <div>
                    <label for="enrollment-left-at" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.left_at') }}</label>
                    <input id="enrollment-left-at" wire:model="left_at" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('left_at')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="enrollment-notes" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.notes') }}</label>
                    <textarea id="enrollment-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                    @error('notes')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.enrollments.form.update_submit') : __('crud.enrollments.form.create_submit') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingId" />
                <button type="button" wire:click="cancel" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>
