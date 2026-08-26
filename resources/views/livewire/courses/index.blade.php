<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Group;
use App\Services\CourseLifecycleService;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $academic_year_id = null;
    public string $name = '';
    public string $description = '';
    public string $starts_on = '';
    public string $ends_on = '';
    public bool $is_active = true;
    public bool $is_default = false;
    public bool $awards_points = true;
    public string $search = '';
    public string $statusFilter = 'active';
    public string $academicYearFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showArchiveModal = false;
    public ?int $archivedCourseId = null;
    public bool $editingAcademicYearIsActive = true;

    public function mount(): void
    {
        $this->authorizePermission('courses.view');
        $currentAcademicYearId = AcademicYear::query()->where('is_current', true)->value('id');
        $this->academicYearFilter = $currentAcademicYearId ? (string) $currentAcademicYearId : 'all';
        $this->resetFormState();
    }

    public function with(): array
    {
        $baseQuery = Course::query()
            ->with('academicYear')
            ->withCount('groups')
            ->orderBy('name');

        $filteredQuery = Course::query()
            ->with('academicYear')
            ->withCount('groups')
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->academicYearFilter !== 'all', fn ($query) => $query->where('academic_year_id', (int) $this->academicYearFilter))
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->orderBy('name');

        $filteredCount = (clone $filteredQuery)->count();

        $archivedCourse = $this->archivedCourseId
            ? Course::query()->with('academicYear')->find($this->archivedCourseId)
            : null;

        return [
            'courses' => $filteredQuery->paginate($this->perPage),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'is_active']),
            'activeAcademicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('is_current')->orderByDesc('starts_on')->get(['id', 'name']),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => Course::query()->where('is_active', true)->count(),
            ],
            'filteredCount' => $filteredCount,
            'archivedCourse' => $archivedCourse,
            'archiveSummary' => $archivedCourse
                ? app(CourseLifecycleService::class)->archiveSummary($archivedCourse)
                : [],
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

    public function updatedAcademicYearFilter(): void
    {
        $this->resetPage();
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('courses', 'name')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string'],
            'academic_year_id' => [
                Rule::requiredIf(! $this->editingId),
                'nullable',
                'integer',
                Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_default' => ['boolean'],
            'awards_points' => ['boolean'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('courses.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'courses.update' : 'courses.create');

        $existingCourse = $this->editingId
            ? Course::query()->findOrFail($this->editingId)
            : null;

        if ($existingCourse && ! $existingCourse->is_active) {
            $this->addError('course', __('crud.courses.errors.finished_read_only'));

            return;
        }

        $validated = $this->validate();
        $validated['description'] = $validated['description'] ?: null;
        $validated['starts_on'] = $validated['starts_on'] ?: null;
        $validated['ends_on'] = $validated['ends_on'] ?: null;
        $validated['academic_year_id'] = $existingCourse?->academic_year_id ?: (int) $validated['academic_year_id'];
        $validated['is_active'] = $existingCourse?->is_active ?? true;
        $validated['is_default'] = $validated['is_default']
            || ! Course::query()->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))->where('is_default', true)->exists();

        $course = Course::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        session()->flash(
            'status',
            $this->editingId ? __('crud.courses.messages.updated') : __('crud.courses.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $courseId): void
    {
        $this->authorizePermission('courses.update');

        $course = Course::query()->with('academicYear')->findOrFail($courseId);

        if (! $course->is_active) {
            $this->openArchive($courseId);

            return;
        }

        $this->editingId = $course->id;
        $this->academic_year_id = $course->academic_year_id;
        $this->name = $course->name;
        $this->description = $course->description ?? '';
        $this->starts_on = $course->starts_on?->format('Y-m-d') ?? '';
        $this->ends_on = $course->ends_on?->format('Y-m-d') ?? '';
        $this->is_active = $course->is_active;
        $this->is_default = $course->is_default;
        $this->awards_points = $course->awards_points;
        $this->editingAcademicYearIsActive = $course->academicYear?->is_active ?? true;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetFormState();
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function delete(int $courseId): void
    {
        $this->authorizePermission('courses.delete');

        $course = Course::query()->withCount('groups')->findOrFail($courseId);

        if ($course->groups_count > 0) {
            $this->addError('delete', __('crud.courses.errors.delete_linked'));

            return;
        }

        $course->delete();
        Course::query()->where('is_default', true)->exists()
            ?: Course::query()->where('is_active', true)->orderBy('name')->first()?->update(['is_default' => true]);

        if ($this->editingId === $courseId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.courses.messages.deleted'));
    }

    public function deactivate(int $courseId): void
    {
        $this->authorizePermission('courses.update');

        $course = Course::query()->findOrFail($courseId);
        app(CourseLifecycleService::class)->finish($course);
        $this->cancel();

        session()->flash('status', __('crud.courses.messages.deactivated'));
    }

    public function reactivate(int $courseId): void
    {
        $this->authorizePermission('courses.update');

        $course = Course::query()->findOrFail($courseId);
        app(CourseLifecycleService::class)->reactivate($course);
        $this->cancel();
        $this->closeArchive();

        session()->flash('status', __('crud.courses.messages.reactivated'));
    }

    public function openArchive(int $courseId): void
    {
        $this->authorizePermission('courses.update');

        $course = Course::query()->with('academicYear')->findOrFail($courseId);
        abort_if($course->is_active, 409);

        $this->cancel();
        $this->archivedCourseId = $course->id;
        $this->editingAcademicYearIsActive = $course->academicYear?->is_active ?? true;
        $this->showArchiveModal = true;
        $this->resetValidation();
    }

    public function closeArchive(): void
    {
        $this->showArchiveModal = false;
        $this->archivedCourseId = null;
        $this->editingAcademicYearIsActive = true;
        $this->resetValidation();
    }

    public function duplicate(int $courseId): void
    {
        $this->authorizePermission('courses.create');

        $source = Course::query()
            ->with(['groups'])
            ->findOrFail($courseId);

        DB::transaction(function () use ($source): void {
            $newCourse = $source->replicate(['name', 'finished_at']);
            $newCourse->name = $this->uniqueCopyName($source->name);
            $newCourse->finished_at = null;
            $newCourse->is_active = true;
            $newCourse->is_default = false;
            $newCourse->save();

            foreach ($source->groups as $group) {
                $newGroup = $group->replicate(['course_id', 'name', 'course_finished_at']);
                $newGroup->course_id = $newCourse->id;
                $newGroup->name = $this->uniqueGroupCopyName($group->name, $group->academic_year_id);
                $newGroup->course_finished_at = null;
                $newGroup->save();
            }
        });

        $this->cancel();
        session()->flash('status', __('crud.courses.messages.copied'));
    }

    protected function uniqueCopyName(string $baseName): string
    {
        $name = __('crud.courses.copy.name', ['name' => $baseName]);
        $candidate = $name;
        $counter = 2;

        while (Course::query()->where('name', $candidate)->exists()) {
            $candidate = __('crud.courses.copy.name_numbered', ['name' => $baseName, 'number' => $counter]);
            $counter++;
        }

        return $candidate;
    }

    protected function uniqueGroupCopyName(string $baseName, ?int $academicYearId): string
    {
        $candidate = __('crud.courses.copy.name', ['name' => $baseName]);
        $counter = 2;

        while (
            Group::withTrashed()
                ->where('name', $candidate)
                ->when($academicYearId, fn ($query) => $query->where('academic_year_id', $academicYearId), fn ($query) => $query->whereNull('academic_year_id'))
                ->exists()
        ) {
            $candidate = __('crud.courses.copy.name_numbered', ['name' => $baseName, 'number' => $counter]);
            $counter++;
        }

        return $candidate;
    }

    protected function resetFormState(): void
    {
        $this->editingId = null;
        $this->academic_year_id = AcademicYear::query()
            ->where('is_current', true)
            ->where('is_active', true)
            ->value('id');
        $this->name = '';
        $this->description = '';
        $this->starts_on = '';
        $this->ends_on = '';
        $this->is_active = true;
        $this->is_default = Course::query()->where('is_default', true)->doesntExist();
        $this->awards_points = true;
        $this->editingAcademicYearIsActive = true;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.academics') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.courses.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.courses.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('crud.courses.table.title') }}</div>
            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label class="sr-only" for="course-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="course-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="course-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="course-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>

                <div class="admin-filter-field course-academic-year-filter">
                    <label class="sr-only" for="course-academic-year-filter">{{ __('crud.common.filters.academic_year') }}</label>
                    <select id="course-academic-year-filter" wire:model.live="academicYearFilter">
                        <option value="all">{{ __('crud.common.filters.all_academic_years') }}</option>
                        @foreach ($academicYears as $academicYear)
                            <option value="{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('courses.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('courses.export', ['search' => $search, 'status' => $statusFilter, 'academic_year_id' => $academicYearFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($courses->isEmpty())
            <div class="admin-empty-state">{{ __('crud.courses.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.dates') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.academic_year') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.groups') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.points') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.courses.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.courses.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($courses as $course)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $course->name }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    {{ $course->starts_on || $course->ends_on
                                        ? __('crud.courses.table.date_range', [
                                            'start' => $course->starts_on?->format('d-m-Y') ?: __('crud.common.not_available'),
                                            'end' => $course->ends_on?->format('d-m-Y') ?: __('crud.common.not_available'),
                                        ])
                                        : __('crud.common.not_available') }}
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $course->academicYear?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($course->groups_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $course->awards_points ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ $course->awards_points ? __('crud.courses.table.points_enabled') : __('crud.courses.table.points_disabled') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($course->is_default)
                                        <span class="status-chip status-chip--gold">{{ __('crud.courses.table.default') }}</span>
                                    @else
                                        <span class="{{ $course->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                            {{ $course->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.finished') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-nowrap justify-end gap-2">
                                        @if ($course->is_active && $course->awards_points)
                                            <a href="{{ route('courses.end', $course) }}" wire:navigate class="pill-link pill-link--compact pill-link--accent min-w-max px-4">{{ __('crud.courses.actions.end_course') }}</a>
                                        @endif
                                        @can('courses.update')
                                            @if ($course->is_active)
                                                <button type="button" wire:click="edit({{ $course->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                            @else
                                                <button type="button" wire:click="openArchive({{ $course->id }})" class="pill-link pill-link--compact border-red-400/30 bg-red-500/10 text-red-200">{{ __('crud.courses.actions.archive') }}</button>
                                            @endif
                                        @endcan
                                        @can('courses.delete')
                                            @if ($course->groups_count === 0)<button type="button" wire:click="delete({{ $course->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>@endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($courses->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $courses->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.courses.form.edit_title') : __('crud.courses.form.create_title')"
        close-method="cancel"
        max-width="3xl"
    >
        <form wire:submit="save" class="space-y-4">
            @if (! $editingId)
                <div>
                    <label for="course-academic-year" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.academic_year') }}</label>
                    <select id="course-academic-year" wire:model="academic_year_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.courses.form.select_academic_year') }}</option>
                        @foreach ($activeAcademicYears as $academicYear)
                            <option value="{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                        @endforeach
                    </select>
                    @error('academic_year_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            <div>
                <label for="course-name" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.name') }}</label>
                <input id="course-name" wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('name')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="course-starts-on" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.starts_on') }}</label>
                    <input id="course-starts-on" wire:model="starts_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('starts_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="course-ends-on" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.ends_on') }}</label>
                    <input id="course-ends-on" wire:model="ends_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('ends_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="is_default" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.courses.form.default_course') }}</span>
            </label>

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="awards_points" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.courses.form.awards_points') }}</span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.courses.form.update_submit') : __('crud.courses.form.create_submit') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingId" />
                <button type="button" wire:click="cancel" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
                @if ($editingId)
                    <button type="button" wire:click="deactivate({{ $editingId }})" wire:confirm="{{ __('crud.courses.confirm_deactivate') }}" class="pill-link border-amber-300/30 bg-amber-400/10 text-amber-100">{{ __('crud.courses.actions.finish') }}</button>
                    @can('courses.create')<button type="button" wire:click="duplicate({{ $editingId }})" wire:confirm="{{ __('crud.courses.copy.confirm') }}" class="pill-link border-sky-300/30 bg-sky-400/10 text-sky-100">{{ __('crud.common.actions.copy') }}</button>@endcan
                @endif
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showArchiveModal"
        :title="__('crud.courses.archive.title', ['course' => $archivedCourse?->name ?? ''])"
        close-method="closeArchive"
        max-width="3xl"
    >
        @if ($archivedCourse)
            <div class="space-y-5">
                <div class="rounded-2xl border border-amber-300/20 bg-amber-400/10 p-5 text-sm leading-6 text-amber-100">
                    {{ __('crud.courses.archive.read_only') }}
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (['groups', 'enrollments', 'assessments', 'student_attendance', 'teacher_attendance'] as $archiveKey)
                        <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                            <div class="text-xs uppercase tracking-[0.16em] text-neutral-400">{{ __('crud.courses.archive.'.$archiveKey) }}</div>
                            <div class="mt-2 text-2xl font-semibold text-white">{{ number_format($archiveSummary[$archiveKey] ?? 0) }}</div>
                        </div>
                    @endforeach
                </div>

                @error('course')
                    <div class="text-sm text-red-400">{{ $message }}</div>
                @enderror

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <button type="button" wire:click="closeArchive" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                    @if ($editingAcademicYearIsActive)
                        <button type="button" wire:click="reactivate({{ $archivedCourse->id }})" class="pill-link border-red-400/30 bg-red-500/10 text-red-200">{{ __('crud.courses.actions.reactivate') }}</button>
                    @endif
                </div>
            </div>
        @endif
    </x-admin.modal>
</div>
