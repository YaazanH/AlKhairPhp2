<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Group;
use App\Services\CourseLifecycleService;
use App\Services\CourseScheduleService;
use App\Support\ScheduleTimeSlots;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
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
    public bool $showScheduleModal = false;
    public ?int $schedulingCourseId = null;
    public array $scheduleRows = [];
    public string $scheduleDay = '';
    public string $scheduleTimeSlot = '';
    public ?int $editingScheduleRow = null;
    public bool $syncScheduleToGroups = false;
    public bool $copySetup = false;

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
            'scheduleDays' => collect(range(0, 6))->mapWithKeys(fn ($day) => [$day => __('schedules.group.days.'.$day)]),
            'scheduleTimeSlots' => ScheduleTimeSlots::options(),
            'schedulingCourse' => $this->schedulingCourseId ? Course::query()->find($this->schedulingCourseId) : null,
            'editingCourseCanBeDeleted' => $this->editingId ? $this->courseCanBeDeleted(Course::query()->findOrFail($this->editingId)) : false,
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
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
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
        $validated['academic_year_id'] = (int) $validated['academic_year_id'];
        $validated['is_active'] = $existingCourse?->is_active ?? true;
        $validated['is_default'] = $validated['is_default']
            || ! Course::query()->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))->where('is_default', true)->exists();

        $wasCreating = ! $this->editingId;
        $showScheduleAfterSave = $wasCreating || $this->copySetup;
        $syncGroups = $this->copySetup;

        $course = DB::transaction(function () use ($validated, $existingCourse): Course {
            $course = Course::query()->updateOrCreate(
                ['id' => $this->editingId],
                $validated,
            );

            if ($existingCourse && $existingCourse->academic_year_id !== $course->academic_year_id) {
                Group::withTrashed()->where('course_id', $course->id)->update([
                    'academic_year_id' => $course->academic_year_id,
                ]);
            }

            return $course;
        });

        session()->flash(
            'status',
            $this->editingId ? __('crud.courses.messages.updated') : __('crud.courses.messages.created'),
        );

        if ($showScheduleAfterSave) {
            $this->showFormModal = false;
            $this->openCourseSchedule($course->id, $syncGroups);
            $this->resetFormState();
            $this->copySetup = false;

            return;
        }

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
        $this->copySetup = false;

        $this->resetValidation();
    }

    public function delete(int $courseId): void
    {
        $this->authorizePermission('courses.delete');

        $course = Course::query()->findOrFail($courseId);

        if (! $this->courseCanBeDeleted($course)) {
            $this->addError('delete', __('crud.courses.errors.delete_linked'));

            return;
        }

        DB::transaction(function () use ($course): void {
            Group::withTrashed()->where('course_id', $course->id)->get()->each->forceDelete();
            $course->delete();
        });
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
            ->with(['groups', 'schedules'])
            ->findOrFail($courseId);

        $newCourseId = DB::transaction(function () use ($source): int {
            $newCourse = $source->replicate(['name', 'finished_at']);
            $newCourse->name = $this->uniqueCopyName($source->name);
            $newCourse->finished_at = null;
            $newCourse->is_active = true;
            $newCourse->is_default = false;
            $newCourse->save();

            foreach ($source->schedules as $schedule) {
                $newCourse->schedules()->create([
                    'day_of_week' => $schedule->day_of_week,
                    'time_slot' => $schedule->time_slot,
                ]);
            }

            foreach ($source->groups as $group) {
                $newGroup = $group->replicate(['course_id', 'name', 'course_finished_at']);
                $newGroup->course_id = $newCourse->id;
                $newGroup->name = $group->name;
                $newGroup->course_finished_at = null;
                $newGroup->save();
            }

            return $newCourse->id;
        });

        session()->flash('status', __('crud.courses.messages.copied'));
        $this->edit($newCourseId);
        $this->copySetup = true;
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

    public function openCourseSchedule(int $courseId, bool $syncGroups = false): void
    {
        $course = Course::query()->with('schedules')->findOrFail($courseId);
        $this->schedulingCourseId = $course->id;
        $this->scheduleRows = $course->schedules->map(fn ($schedule) => ['day_of_week' => (string) $schedule->day_of_week, 'time_slot' => $schedule->time_slot])->values()->all();
        $this->syncScheduleToGroups = $syncGroups;
        $this->showScheduleModal = true;
        $this->resetScheduleRow();
        $this->resetValidation();
    }

    public function saveScheduleRow(): void
    {
        $data = $this->validate([
            'scheduleDay' => ['required', 'integer', 'between:0,6'],
            'scheduleTimeSlot' => ['required', Rule::in(ScheduleTimeSlots::keys())],
        ]);
        $duplicate = collect($this->scheduleRows)->contains(fn ($row, $index) => $index !== $this->editingScheduleRow && (string) $row['day_of_week'] === (string) $data['scheduleDay'] && $row['time_slot'] === $data['scheduleTimeSlot']);
        if ($duplicate) {
            $this->addError('scheduleTimeSlot', __('validation.unique'));
            return;
        }
        $row = ['day_of_week' => (string) $data['scheduleDay'], 'time_slot' => $data['scheduleTimeSlot']];
        if ($this->editingScheduleRow === null) {
            $this->scheduleRows[] = $row;
        } else {
            $this->scheduleRows[$this->editingScheduleRow] = $row;
        }
        $this->resetValidation('scheduleRows');
        $this->resetScheduleRow();
    }

    public function updatedScheduleDay(): void
    {
        $this->addScheduleRowWhenComplete();
    }

    public function updatedScheduleTimeSlot(): void
    {
        $this->addScheduleRowWhenComplete();
    }

    protected function addScheduleRowWhenComplete(): void
    {
        if ($this->editingScheduleRow !== null || $this->scheduleDay === '' || $this->scheduleTimeSlot === '') {
            return;
        }

        $this->saveScheduleRow();
    }

    public function editScheduleRow(int $index): void
    {
        abort_unless(isset($this->scheduleRows[$index]), 404);
        $this->editingScheduleRow = $index;
        $this->scheduleDay = (string) $this->scheduleRows[$index]['day_of_week'];
        $this->scheduleTimeSlot = $this->scheduleRows[$index]['time_slot'];
        $this->resetValidation();
    }

    public function deleteScheduleRow(int $index): void
    {
        abort_unless(isset($this->scheduleRows[$index]), 404);

        if (count($this->scheduleRows) <= 1) {
            $this->addError('scheduleRows', __('schedules.errors.required'));
            return;
        }

        array_splice($this->scheduleRows, $index, 1);
        $this->resetValidation('scheduleRows');
        $this->resetScheduleRow();
    }

    public function saveCourseSchedule(): void
    {
        abort_unless($this->schedulingCourseId, 404);

        if ($this->scheduleRows === []) {
            $this->addError('scheduleRows', __('schedules.errors.required'));
            return;
        }

        $course = Course::query()->findOrFail($this->schedulingCourseId);
        app(CourseScheduleService::class)->replace($course, $this->scheduleRows, $this->syncScheduleToGroups);
        $this->closeCourseSchedule();
        session()->flash('status', __('schedules.group.messages.updated'));
    }

    public function closeCourseSchedule(): void
    {
        $this->showScheduleModal = false;
        $this->schedulingCourseId = null;
        $this->scheduleRows = [];
        $this->syncScheduleToGroups = false;
        $this->resetScheduleRow();
    }

    public function courseCanBeDeleted(Course $course): bool
    {
        $groupIds = Group::withTrashed()->where('course_id', $course->id)->pluck('id');
        if ($course->curricula()->exists()) {
            return false;
        }
        foreach (['barcode_scan_imports', 'student_attendance_days', 'teacher_attendance_days', 'student_card_prints'] as $table) {
            if (DB::table($table)->where('course_id', $course->id)->exists()) {
                return false;
            }
        }
        foreach (['enrollments', 'assessments', 'group_attendance_days', 'activities', 'group_curriculum_lesson_progresses', 'group_curriculum_topic_progresses', 'group_custom_curriculum_lessons'] as $table) {
            if ($groupIds->isNotEmpty() && DB::table($table)->whereIn('group_id', $groupIds)->exists()) {
                return false;
            }
        }
        return true;
    }

    protected function resetScheduleRow(): void
    {
        $this->editingScheduleRow = null;
        $this->scheduleDay = '';
        $this->scheduleTimeSlot = '';
        $this->resetValidation(['scheduleDay', 'scheduleTimeSlot']);
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
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="course-name" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.name') }}</label>
                    <input id="course-name" wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('name')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                </div>
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
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="course-starts-on" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.starts_on') }}</label>
                    <input id="course-starts-on" wire:model="starts_on" type="date" required class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('starts_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="course-ends-on" class="mb-1 block text-sm font-medium">{{ __('crud.courses.form.fields.ends_on') }}</label>
                    <input id="course-ends-on" wire:model="ends_on" type="date" required class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('ends_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 rounded-2xl border border-white/10 bg-white/[0.025] p-4 sm:grid-cols-2">
                <label class="flex items-center gap-3 text-sm"><input wire:model="is_default" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('crud.courses.form.default_course') }}</span></label>
                <label class="flex items-center gap-3 text-sm"><input wire:model="awards_points" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('crud.courses.form.awards_points') }}</span></label>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.courses.form.update_submit') : __('crud.courses.form.create_submit') }}
                </button>
                @if ($editingId)
                    @unless($copySetup)
                        <button type="button" wire:click="deactivate({{ $editingId }})" wire:confirm="{{ __('crud.courses.confirm_deactivate') }}" class="pill-link border-amber-300/30 bg-amber-400/10 text-amber-100">{{ __('crud.courses.actions.finish') }}</button>
                        @can('courses.create')<button type="button" wire:click="duplicate({{ $editingId }})" wire:confirm="{{ __('crud.courses.copy.confirm') }}" class="pill-link border-sky-300/30 bg-sky-400/10 text-sky-100">{{ __('crud.common.actions.copy') }}</button>@endcan
                    @endunless
                    @can('courses.delete')
                        @if($editingCourseCanBeDeleted)<button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--danger">{{ __('crud.common.actions.delete') }}</button>@endif
                    @endcan
                @endif
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showScheduleModal" :title="__('schedules.course.title', ['course' => $schedulingCourse?->name ?? ''])" max-width="3xl">
        <x-slot:header-actions>
            <button type="button" wire:click="saveCourseSchedule" class="admin-modal__close" title="{{ __('crud.common.actions.save') }}" aria-label="{{ __('crud.common.actions.save') }}" data-course-schedule-save>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3.75h11.25L19.5 7v13.25H5V3.75Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 3.75v5.5h8v-5.5M8.25 20.25v-6.5h8v6.5" />
                </svg>
            </button>
        </x-slot:header-actions>
        <section class="surface-table settings-record-table overflow-visible">
            <div class="overflow-visible"><table class="w-full table-fixed text-sm"><thead><tr><th class="px-4 py-3">{{ __('schedules.group.form.fields.day') }}</th><th class="px-4 py-3">{{ __('schedules.group.form.fields.timing') }}</th><th class="w-32 px-2 py-3">{{ __('schedules.group.table.headers.actions') }}</th></tr></thead><tbody>
                @foreach($scheduleRows as $index => $row)<tr wire:key="course-schedule-{{ $index }}"><td class="px-4 py-3">{{ $scheduleDays[$row['day_of_week']] }}</td><td class="px-4 py-3">{{ $scheduleTimeSlots[$row['time_slot']] }}</td><td class="px-2 py-3"><div class="flex flex-nowrap items-center justify-center gap-2"><button type="button" wire:click="editScheduleRow({{ $index }})" class="admin-icon-button" aria-label="{{ __('crud.common.actions.edit') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m4 20 4.2-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/></svg></button><button type="button" wire:click="deleteScheduleRow({{ $index }})" class="admin-icon-button admin-icon-button--danger" aria-label="{{ __('crud.common.actions.delete') }}"><x-icons.trash class="size-5" /></button></div></td></tr>@endforeach
                <tr class="schedule-add-row"><td class="px-4 py-3"><select wire:model.live="scheduleDay" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" class="h-11 w-full rounded-xl px-3"><option value="">{{ __('schedules.group.form.placeholders.day') }}</option>@foreach($scheduleDays as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('scheduleDay')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td><td class="px-4 py-3"><select wire:model.live="scheduleTimeSlot" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" class="h-11 w-full rounded-xl px-3"><option value="">{{ __('schedules.group.form.placeholders.timing') }}</option>@foreach($scheduleTimeSlots as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('scheduleTimeSlot')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td><td class="px-2 py-3 text-center">@if($editingScheduleRow !== null)<button type="button" wire:click="saveScheduleRow" class="admin-icon-button admin-icon-button--accent" aria-label="{{ __('crud.common.actions.update') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg></button>@endif</td></tr>
            </tbody></table></div>
        </section>
        @error('scheduleRows')<div class="mt-3 text-sm text-red-400">{{ $message }}</div>@enderror
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
