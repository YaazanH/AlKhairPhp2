<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $course_id = null;
    public ?int $academic_year_id = null;
    public ?int $teacher_id = null;
    public ?int $assistant_teacher_id = null;
    public ?int $grade_level_id = null;
    public string $name = '';
    public string $capacity = '0';
    public string $starts_on = '';
    public string $ends_on = '';
    public string $monthly_fee = '';
    public bool $is_active = true;
    public string $search = '';
    public string $statusFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public ?int $rosterGroupId = null;
    public ?int $roster_student_id = null;
    public string $roster_enrolled_at = '';
    public bool $showRosterModal = false;

    public function mount(): void
    {
        $this->authorizePermission('groups.view');
    }

    public function with(): array
    {
        $baseQuery = $this->scopeGroupsQuery(Group::query());
        $filteredQuery = $this->scopeGroupsQuery(Group::query())
            ->with(['academicYear', 'course', 'teacher', 'assistantTeacher', 'gradeLevel'])
            ->withCount(['enrollments', 'schedules'])
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('academicYear', fn ($yearQuery) => $yearQuery->where('name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('teacher', fn ($teacherQuery) => $teacherQuery
                            ->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('assistantTeacher', fn ($teacherQuery) => $teacherQuery
                            ->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'groups' => $filteredQuery->paginate($this->perPage),
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->get(['id', 'name']),
            'teachers' => $this->scopeTeachersQuery(Teacher::query()->where('status', '!=', 'blocked'))->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'rosterGroup' => $this->rosterGroupId
                ? $this->scopeGroupsQuery(Group::query()->with(['course', 'teacher']))->find($this->rosterGroupId)
                : null,
            'rosterEnrollments' => $this->rosterGroupId
                ? $this->scopeEnrollmentsQuery(
                    Enrollment::query()
                        ->with(['student.parentProfile'])
                        ->where('group_id', $this->rosterGroupId)
                )
                    ->orderBy('status')
                    ->orderBy('enrolled_at')
                    ->get()
                : collect(),
            'availableRosterStudents' => $this->rosterGroupId
                ? $this->availableRosterStudentsQuery()
                    ->with('parentProfile')
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'parent_id', 'first_name', 'last_name'])
                : collect(),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeGroupsQuery(Group::query()->where('is_active', true))->count(),
            ],
            'filteredCount' => $filteredCount,
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

    public function rules(): array
    {
        return [
            'course_id' => ['required', 'exists:courses,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'assistant_teacher_id' => ['nullable', 'exists:teachers,id', 'different:teacher_id'],
            'grade_level_id' => ['nullable', 'exists:grade_levels,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups', 'name')
                    ->where(fn ($query) => $query->where('academic_year_id', $this->academic_year_id))
                    ->ignore($this->editingId),
            ],
            'capacity' => ['required', 'integer', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('groups.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'groups.update' : 'groups.create');

        if ($this->editingId) {
            $this->authorizeScopedGroupAccess(Group::query()->findOrFail($this->editingId));
        }

        $validated = $this->validate();
        $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail($validated['teacher_id']));

        if ($validated['assistant_teacher_id']) {
            $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail($validated['assistant_teacher_id']));
        }

        $validated['assistant_teacher_id'] = $validated['assistant_teacher_id'] ?: null;
        $validated['grade_level_id'] = $validated['grade_level_id'] ?: null;
        $validated['starts_on'] = $validated['starts_on'] ?: null;
        $validated['ends_on'] = $validated['ends_on'] ?: null;
        $validated['monthly_fee'] = $validated['monthly_fee'] ?: null;

        Group::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        session()->flash(
            'status',
            $this->editingId ? __('crud.groups.messages.updated') : __('crud.groups.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $groupId): void
    {
        $this->authorizePermission('groups.update');

        $group = Group::query()->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        $this->editingId = $group->id;
        $this->course_id = $group->course_id;
        $this->academic_year_id = $group->academic_year_id;
        $this->teacher_id = $group->teacher_id;
        $this->assistant_teacher_id = $group->assistant_teacher_id;
        $this->grade_level_id = $group->grade_level_id;
        $this->name = $group->name;
        $this->capacity = (string) $group->capacity;
        $this->starts_on = $group->starts_on?->format('Y-m-d') ?? '';
        $this->ends_on = $group->ends_on?->format('Y-m-d') ?? '';
        $this->monthly_fee = $group->monthly_fee !== null ? number_format((float) $group->monthly_fee, 2, '.', '') : '';
        $this->is_active = $group->is_active;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->course_id = null;
        $this->academic_year_id = null;
        $this->teacher_id = null;
        $this->assistant_teacher_id = null;
        $this->grade_level_id = null;
        $this->name = '';
        $this->capacity = '0';
        $this->starts_on = '';
        $this->ends_on = '';
        $this->monthly_fee = '';
        $this->is_active = true;
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function delete(int $groupId): void
    {
        $this->authorizePermission('groups.delete');

        $group = Group::query()->withCount(['enrollments', 'schedules'])->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        if ($group->enrollments_count > 0 || $group->schedules_count > 0) {
            $this->addError('delete', __('crud.groups.errors.delete_linked'));

            return;
        }

        $group->delete();

        if ($this->editingId === $groupId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.groups.messages.deleted'));
    }

    public function openRosterModal(int $groupId): void
    {
        $group = Group::query()->findOrFail($groupId);
        $this->authorizeScopedGroupAccess($group);

        $this->rosterGroupId = $group->id;
        $this->roster_student_id = null;
        $this->roster_enrolled_at = now()->toDateString();
        $this->showRosterModal = true;

        $this->resetValidation();
    }

    public function closeRosterModal(): void
    {
        $this->rosterGroupId = null;
        $this->roster_student_id = null;
        $this->roster_enrolled_at = '';
        $this->showRosterModal = false;

        $this->resetValidation();
    }

    public function addStudentToRoster(): void
    {
        $this->authorizePermission('enrollments.create');

        $group = Group::query()->findOrFail($this->rosterGroupId);
        $this->authorizeScopedGroupAccess($group);

        $validated = $this->validate([
            'roster_student_id' => ['required', 'exists:students,id'],
            'roster_enrolled_at' => ['required', 'date'],
        ]);

        $student = Student::query()->findOrFail($validated['roster_student_id']);
        $this->authorizeScopedStudentAccess($student);

        $duplicateEnrollmentExists = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('group_id', $group->id)
            ->exists();

        if ($duplicateEnrollmentExists) {
            $this->addError('roster_student_id', __('crud.enrollments.errors.already_enrolled'));

            return;
        }

        Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => $validated['roster_enrolled_at'],
            'status' => 'active',
        ]);

        $this->roster_student_id = null;
        $this->roster_enrolled_at = now()->toDateString();

        session()->flash('status', __('crud.groups.messages.student_added'));
    }

    public function removeStudentFromRoster(int $enrollmentId): void
    {
        $this->authorizePermission('enrollments.delete');

        $enrollment = Enrollment::query()->findOrFail($enrollmentId);
        $this->authorizeScopedEnrollmentAccess($enrollment);

        abort_unless($this->rosterGroupId && $enrollment->group_id === $this->rosterGroupId, 404);

        $enrollment->delete();

        session()->flash('status', __('crud.groups.messages.student_removed'));
    }

    protected function availableRosterStudentsQuery()
    {
        return $this->scopeStudentsQuery(Student::query())
            ->whereDoesntHave('enrollments', function ($enrollmentQuery) {
                $enrollmentQuery->where('group_id', $this->rosterGroupId);
            });
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('crud.groups.hero.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.groups.hero.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.groups.hero.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('crud.groups.hero.badges.active_courses', ['count' => number_format($courses->count())]) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('crud.groups.hero.badges.academic_years', ['count' => number_format($academicYears->count())]) }}</span>
            <span class="badge-soft">{{ __('crud.groups.hero.badges.teachers_available', ['count' => number_format($teachers->count())]) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.groups.stats.all.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.groups.stats.all.description') }}</p>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.groups.stats.active.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.groups.stats.active.description') }}</p>
        </article>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('crud.groups.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('crud.groups.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="group-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="group-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="group-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="group-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('groups.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('groups.export', ['search' => $search, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('crud.groups.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($groups->isEmpty())
            <div class="admin-empty-state">{{ __('crud.groups.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.year') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.students') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.table.headers.status') }}</th>
                            @if (auth()->user()->can('groups.view') || auth()->user()->can('groups.update') || auth()->user()->can('groups.delete'))
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.groups.table.headers.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($groups as $group)
                            @php
                                $groupStatusClass = $group->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate';
                            @endphp
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $group->name }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">
                                        {{ $group->monthly_fee !== null
                                            ? __('crud.groups.table.capacity_fee', ['capacity' => $group->capacity, 'fee' => $group->monthly_fee])
                                            : __('crud.groups.table.capacity', ['capacity' => $group->capacity]) }}
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $group->course?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $group->teacher ? $group->teacher->first_name.' '.$group->teacher->last_name : __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $group->academicYear?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $group->enrollments_count }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="{{ $groupStatusClass }}">{{ $group->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}</span></td>
                                @if (auth()->user()->can('groups.view') || auth()->user()->can('groups.update') || auth()->user()->can('groups.delete'))
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @can('attendance.student.view')
                                                <a href="{{ route('groups.attendance', $group) }}" wire:navigate class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.attendance') }}
                                                </a>
                                            @endcan
                                            <button type="button" wire:click="openRosterModal({{ $group->id }})" class="pill-link pill-link--compact">
                                                {{ __('crud.common.actions.students') }}
                                            </button>
                                            <a href="{{ route('groups.schedules', $group) }}" wire:navigate class="pill-link pill-link--compact">
                                                {{ __('crud.common.actions.schedules') }}
                                            </a>
                                            @can('groups.update')
                                                <button type="button" wire:click="edit({{ $group->id }})" class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.edit') }}
                                                </button>
                                            @endcan
                                            @can('groups.delete')
                                                <button type="button" wire:click="delete({{ $group->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
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

            @if ($groups->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $groups->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.groups.form.edit_title') : __('crud.groups.form.create_title')"
        :description="__('crud.groups.form.help')"
        close-method="cancel"
        max-width="5xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="group-course" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.course') }}</label>
                    <select id="group-course" wire:model="course_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.select_course') }}</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                    @error('course_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-academic-year" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.academic_year') }}</label>
                    <select id="group-academic-year" wire:model="academic_year_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.select_academic_year') }}</option>
                        @foreach ($academicYears as $academicYear)
                            <option value="{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                        @endforeach
                    </select>
                    @error('academic_year_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="group-teacher" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.teacher') }}</label>
                    <select id="group-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.select_teacher') }}</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('teacher_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-assistant-teacher" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.assistant_teacher') }}</label>
                    <select id="group-assistant-teacher" wire:model="assistant_teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.no_assistant') }}</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('assistant_teacher_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="group-name" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.group_name') }}</label>
                    <input id="group-name" wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-grade-level" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.grade_level') }}</label>
                    <select id="group-grade-level" wire:model="grade_level_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.groups.form.placeholders.all_grade_levels') }}</option>
                        @foreach ($gradeLevels as $gradeLevel)
                            <option value="{{ $gradeLevel->id }}">{{ $gradeLevel->name }}</option>
                        @endforeach
                    </select>
                    @error('grade_level_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label for="group-capacity" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.capacity') }}</label>
                    <input id="group-capacity" wire:model="capacity" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('capacity')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-starts-on" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.starts_on') }}</label>
                    <input id="group-starts-on" wire:model="starts_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('starts_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="group-ends-on" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.ends_on') }}</label>
                    <input id="group-ends-on" wire:model="ends_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('ends_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div>
                <label for="group-monthly-fee" class="mb-1 block text-sm font-medium">{{ __('crud.groups.form.fields.monthly_fee') }}</label>
                <input id="group-monthly-fee" wire:model="monthly_fee" type="number" step="0.01" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('monthly_fee')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.groups.form.active_group') }}</span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.groups.form.update_submit') : __('crud.groups.form.create_submit') }}
                </button>
                <button type="button" wire:click="cancel" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showRosterModal"
        :title="__('crud.groups.roster.title', ['group' => $rosterGroup?->name ?? ''])"
        :description="__('crud.groups.roster.help')"
        close-method="closeRosterModal"
        max-width="6xl"
    >
        <div class="space-y-6">
            @if ($rosterGroup)
                <section class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.roster.summary.group') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $rosterGroup->name }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.roster.summary.course') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $rosterGroup->course?->name ?: __('crud.common.not_available') }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase tracking-[0.22em] text-neutral-500">{{ __('crud.groups.roster.summary.teacher') }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $rosterGroup->teacher ? $rosterGroup->teacher->first_name.' '.$rosterGroup->teacher->last_name : __('crud.common.not_available') }}</div>
                        </div>
                    </div>
                </section>

                @can('enrollments.create')
                    <form wire:submit="addStudentToRoster" class="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-white">{{ __('crud.groups.roster.add_title') }}</div>
                                <p class="mt-1 text-sm text-neutral-400">{{ __('crud.groups.roster.add_help') }}</p>
                            </div>
                            <button type="submit" class="pill-link pill-link--accent">{{ __('crud.groups.roster.add_submit') }}</button>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-[minmax(0,2fr)_220px]">
                            <div>
                                <label for="group-roster-student" class="mb-1 block text-sm font-medium">{{ __('crud.groups.roster.fields.student') }}</label>
                                <select id="group-roster-student" wire:model="roster_student_id" class="w-full rounded-xl px-4 py-3 text-sm">
                                    <option value="">{{ __('crud.groups.roster.placeholders.select_student') }}</option>
                                    @foreach ($availableRosterStudents as $student)
                                        <option value="{{ $student->id }}">
                                            {{ $student->first_name }} {{ $student->last_name }}
                                            @if ($student->parentProfile?->father_name)
                                                - {{ $student->parentProfile->father_name }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @if ($availableRosterStudents->isEmpty())
                                    <div class="mt-1 text-sm text-neutral-400">{{ __('crud.groups.roster.no_available_students') }}</div>
                                @endif
                                @error('roster_student_id')
                                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label for="group-roster-enrolled-at" class="mb-1 block text-sm font-medium">{{ __('crud.groups.roster.fields.enrolled_at') }}</label>
                                <input id="group-roster-enrolled-at" wire:model="roster_enrolled_at" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                                @error('roster_enrolled_at')
                                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </form>
                @endcan

                <section class="surface-table">
                    <div class="admin-grid-meta">
                        <div>
                            <div class="admin-grid-meta__title">{{ __('crud.groups.roster.table.title') }}</div>
                            <div class="admin-grid-meta__summary">{{ __('crud.groups.roster.table.summary', ['count' => number_format($rosterEnrollments->count())]) }}</div>
                        </div>
                    </div>

                    @if ($rosterEnrollments->isEmpty())
                        <div class="admin-empty-state">{{ __('crud.groups.roster.table.empty') }}</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.student') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.parent') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.enrolled_at') }}</th>
                                        <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.status') }}</th>
                                        @can('enrollments.delete')
                                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.groups.roster.table.headers.actions') }}</th>
                                        @endcan
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/6">
                                    @foreach ($rosterEnrollments as $enrollment)
                                        @php
                                            $rosterStatusClass = match ($enrollment->status) {
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
                                            <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->student?->parentProfile?->father_name ?: __('crud.common.not_available') }}</td>
                                            <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->enrolled_at?->format('Y-m-d') ?: __('crud.common.not_available') }}</td>
                                            <td class="px-5 py-4 lg:px-6"><span class="{{ $rosterStatusClass }}">{{ __('crud.common.status_options.'.$enrollment->status) }}</span></td>
                                            @can('enrollments.delete')
                                                <td class="px-5 py-4 lg:px-6">
                                                    <div class="flex justify-end">
                                                        <button
                                                            type="button"
                                                            wire:click="removeStudentFromRoster({{ $enrollment->id }})"
                                                            wire:confirm="{{ __('crud.common.confirm_delete.message') }}"
                                                            class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12"
                                                        >
                                                            {{ __('crud.groups.roster.remove_action') }}
                                                        </button>
                                                    </div>
                                                </td>
                                            @endcan
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </x-admin.modal>
</div>
