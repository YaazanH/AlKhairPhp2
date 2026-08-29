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
    public string $sortField = 'enrolled_at';
    public string $sortDirection = 'desc';
    public int $perPage = 15;
    public bool $showFormModal = false;

    protected array $sortableFields = [
        'course',
        'enrolled_at',
        'group',
        'status',
        'student',
    ];

    public function mount(): void
    {
        $this->authorizePermission('enrollments.view');
        $this->courseFilter = (string) (Course::query()->where('is_default', true)->where('is_active', true)->value('id') ?? 'all');
    }

    public function with(): array
    {
        $filteredQuery = $this->scopeEnrollmentsQuery(Enrollment::query())
            ->with(['group.course', 'student'])
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->whereHas('student', fn ($studentQuery) => $studentQuery
                            ->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%')
                            ->orWhere('student_number', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('group', fn ($groupQuery) => $groupQuery
                            ->where('name', 'like', '%'.$this->search.'%')
                            ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$this->search.'%')));
                });
            })
            ->when($this->courseFilter !== 'all', fn ($query) => $query->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', (int) $this->courseFilter)))
            ->when($this->groupFilter !== 'all', fn ($query) => $query->where('group_id', (int) $this->groupFilter))
            ->when(in_array($this->statusFilter, ['active', 'completed', 'cancelled'], true), fn ($query) => $query->where('status', $this->statusFilter));
        $this->applyEnrollmentSort($filteredQuery);

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'enrollments' => $filteredQuery->paginate($this->perPage),
            'students' => $this->availableStudentsQuery()
                ->with('parentProfile')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'parent_id', 'first_name', 'last_name', 'student_number']),
            'groups' => $this->scopeGroupsQuery(Group::query()->with('course'))
                ->when(! $this->editingId, fn ($query) => $query
                    ->where('is_active', true)
                    ->whereHas('course', fn ($courseQuery) => $courseQuery
                        ->where('is_active', true)
                        ->whereNull('finished_at')))
                ->orderBy('name')
                ->get(['id', 'course_id', 'name']),
            'filterCourses' => Course::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filterGroups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with('course')
                    ->when($this->courseFilter !== 'all', fn ($query) => $query->where('course_id', (int) $this->courseFilter))
                    ->orderBy('name')
            )->get(['id', 'course_id', 'name']),
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

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = in_array($field, ['student', 'group', 'course', 'status'], true) ? 'asc' : 'desc';
        }

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
        if (! $this->editingId) {
            $this->enrolled_at = now()->toDateString();
            $this->status = 'active';
        }

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
        $student = Student::query()->findOrFail($validated['student_id']);
        $this->authorizeScopedStudentAccess($student);
        $group = Group::query()->with('course')->findOrFail($validated['group_id']);
        $this->authorizeScopedGroupAccess($group);

        if (! $this->editingId && (! $group->is_active || ! $group->course?->is_active || $group->course?->finished_at)) {
            $this->addError('group_id', __('crud.enrollments.errors.inactive_group'));

            return;
        }

        if ($student->status !== 'active') {
            $this->addError('student_id', __('crud.enrollments.errors.inactive_student'));

            return;
        }

        $activeEnrollmentExists = Enrollment::query()
            ->where('student_id', $validated['student_id'])
            ->where('status', 'active')
            ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
            ->exists();

        if ($activeEnrollmentExists) {
            $this->addError('student_id', __('crud.enrollments.errors.already_active'));

            return;
        }

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
            $validated['enrolled_at'] = now()->toDateString();
            $validated['status'] = 'active';
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
        $this->enrolled_at = now()->toDateString();
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
        $this->enrolled_at = now()->toDateString();
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
            ->where('status', 'active')
            ->whereDoesntHave('enrollments', function ($enrollmentQuery) {
                $enrollmentQuery
                    ->where('status', 'active')
                    ->when($this->editingId, fn ($innerQuery) => $innerQuery->whereKeyNot($this->editingId));
            })
            ->when($this->group_id, function ($query) {
                $query->whereDoesntHave('enrollments', function ($enrollmentQuery) {
                    $enrollmentQuery
                        ->where('group_id', $this->group_id)
                        ->when($this->editingId, fn ($innerQuery) => $innerQuery->whereKeyNot($this->editingId));
                });
            });
    }

    protected function applyEnrollmentSort($query): void
    {
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        match ($this->sortField) {
            'course' => $query
                ->orderBy(
                    Course::query()
                        ->select('name')
                        ->whereIn('courses.id', Group::query()
                            ->select('course_id')
                            ->whereColumn('groups.id', 'enrollments.group_id')
                            ->limit(1)),
                    $direction,
                )
                ->orderBy('enrolled_at', 'desc'),
            'enrolled_at' => $query->orderBy('enrolled_at', $direction)->orderByDesc('id'),
            'group' => $query
                ->orderBy(
                    Group::query()
                        ->select('name')
                        ->whereColumn('groups.id', 'enrollments.group_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy('enrolled_at', 'desc'),
            'status' => $query->orderBy('status', $direction)->orderBy('enrolled_at', 'desc'),
            default => $query
                ->orderBy(
                    Student::query()
                        ->select('first_name')
                        ->whereColumn('students.id', 'enrollments.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy(
                    Student::query()
                        ->select('last_name')
                        ->whereColumn('students.id', 'enrollments.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy('enrolled_at', 'desc'),
        };
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('crud.enrollments.hero.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.enrollments.hero.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.enrollments.hero.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('crud.enrollments.table.title') }}</div>
            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label class="sr-only" for="enrollment-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="enrollment-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="enrollment-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="enrollment-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        @foreach ($statuses as $enrollmentStatus)
                            <option value="{{ $enrollmentStatus }}">{{ __('crud.common.status_options.'.$enrollmentStatus) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="enrollment-course-filter">{{ __('crud.common.filters.course') }}</label>
                    <select id="enrollment-course-filter" wire:model.live="courseFilter">
                        <option value="all">{{ __('crud.common.filters.all_courses') }}</option>
                        @foreach ($filterCourses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="enrollment-group-filter">{{ __('crud.common.filters.group') }}</label>
                    <select id="enrollment-group-filter" wire:model.live="groupFilter">
                        <option value="all">{{ __('crud.common.filters.all_groups') }}</option>
                        @foreach ($filterGroups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' - '.$group->course->name : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('enrollments.create')
                        <x-add-action-button wire:click="openCreateModal" :label="__('crud.common.actions.create')" />
                    @endcan
                    <x-export-action-button :href="route('enrollments.export', ['search' => $search, 'status' => $statusFilter, 'course_id' => $courseFilter, 'group_id' => $groupFilter])" :label="__('crud.common.actions.export')" />
                </div>
            </div>
        </div>

        @if ($enrollments->isEmpty())
            <div class="admin-empty-state">{{ __('crud.enrollments.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('crud.enrollments.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('group')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('crud.enrollments.table.headers.group') }} <span>{{ $this->sortIndicator('group') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('course')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('crud.enrollments.table.headers.course') }} <span>{{ $this->sortIndicator('course') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('enrolled_at')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('crud.enrollments.table.headers.enrolled') }} <span>{{ $this->sortIndicator('enrolled_at') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('crud.enrollments.table.headers.status') }} <span>{{ $this->sortIndicator('status') }}</span>
                                </button>
                            </th>
                            @can('enrollments.update')
                                <th class="admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('crud.enrollments.table.headers.actions') }}</th>
                            @endcan
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
                                                <div class="student-inline__name">{{ $enrollment->student->full_name }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->group?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->group?->course?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->enrolled_at?->format('d-m-Y') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="{{ $enrollmentStatusClass }}">{{ __('crud.common.status_options.'.$enrollment->status) }}</span></td>
                                @can('enrollments.update')
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-wrap justify-center gap-2">
                                            <button type="button" wire:click="edit({{ $enrollment->id }})" class="admin-icon-button" title="{{ __('crud.common.actions.edit') }}" aria-label="{{ __('crud.common.actions.edit') }}">
                                                <x-admin-action-icon name="edit" />
                                            </button>
                                        </div>
                                    </td>
                                @endcan
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
        close-method="cancel"
        max-width="fit"
        compact
    >
        <form wire:submit="save" class="w-[min(28rem,calc(100vw-3rem))] space-y-3">
            <div>
                <label for="enrollment-student" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.student') }}</label>
                <select id="enrollment-student" wire:model="student_id" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('crud.enrollments.form.placeholders.select_student') }}</option>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}">{{ $student->full_name }}</option>
                    @endforeach
                </select>
                @if ($group_id && $students->isEmpty())
                    <div class="mt-1 text-sm text-neutral-400">{{ __('crud.enrollments.form.no_available_students') }}</div>
                @endif
                @error('student_id')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="enrollment-group" class="mb-1 block text-sm font-medium">{{ __('crud.enrollments.form.fields.group') }}</label>
                <select id="enrollment-group" wire:model.live="group_id" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('crud.enrollments.form.placeholders.select_group') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('crud.enrollments.form.placeholders.select_group') }}</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
                @error('group_id')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            @if ($editingId)
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

            @endif

            <div class="admin-action-cluster admin-action-cluster--end">
                @if ($editingId)
                    <button type="submit" class="admin-icon-button admin-icon-button--accent admin-modal-action-button" title="{{ __('crud.enrollments.form.update_submit') }}" aria-label="{{ __('crud.enrollments.form.update_submit') }}" data-enrollment-update-action>
                        <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                    </button>
                    @can('enrollments.delete')
                        <x-delete-action-button wire:click="delete({{ $editingId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('crud.common.actions.delete')" data-enrollment-delete-action />
                    @endcan
                @else
                    <x-admin.create-and-new-button />
                @endif
            </div>
        </form>
    </x-admin.modal>
</div>
