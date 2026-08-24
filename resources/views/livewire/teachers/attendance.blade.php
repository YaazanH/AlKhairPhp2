<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceExclusion;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public string $attendance_date = '';
    public string $course_id = '';
    public string $day_status = 'open';
    public string $default_attendance_status_id = '';
    public string $notes = '';
    public string $search = '';
    public string $statusFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showExportModal = false;
    public string $export_date_from = '';
    public string $export_date_to = '';

    public function mount(): void
    {
        $this->authorizePermission('attendance.teacher.view');
        $this->attendance_date = now()->toDateString();
        $this->course_id = (string) ($this->availableCoursesQuery()->where('is_default', true)->value('courses.id')
            ?? $this->availableCoursesQuery()->value('courses.id')
            ?? '');
        $this->export_date_from = now()->startOfMonth()->toDateString();
        $this->export_date_to = now()->toDateString();
    }

    public function with(): array
    {
        $daysQuery = $this->scopeTeacherAttendanceDaysQuery(
            TeacherAttendanceDay::query()->with('course')->withCount([
                'records',
                'records as present_records_count' => fn (Builder $query) => $query
                    ->whereHas('status', fn (Builder $statusQuery) => $statusQuery->where('is_present', true)),
            ])
        )
            ->when(filled($this->search), fn (Builder $query) => $query->whereDate('attendance_date', $this->search))
            ->when(
                in_array($this->statusFilter, ['open', 'closed'], true),
                fn (Builder $query) => $query->where('status', $this->statusFilter)
            )
            ->latest('attendance_date')
            ->latest('id');

        $scheduledTeacherCount = filled($this->attendance_date) && filled($this->course_id)
            ? $this->scheduledTeachersForDate($this->attendance_date, (int) $this->course_id)->count()
            : 0;

        return [
            'days' => $daysQuery->paginate($this->perPage),
            'filteredCount' => (clone $daysQuery)->count(),
            'scheduledTeacherCount' => $scheduledTeacherCount,
            'courseOptions' => $this->availableCoursesQuery()->orderBy('name')->get(['id', 'name']),
            'defaultStatusOptions' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['teacher', 'both'])
                ->orderByDesc('is_default')
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->get(),
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

    public function openCreateModal(): void
    {
        $this->authorizePermission('attendance.teacher.take');

        $this->attendance_date = now()->toDateString();
        $this->course_id = (string) ($this->availableCoursesQuery()->where('is_default', true)->value('courses.id')
            ?? $this->availableCoursesQuery()->value('courses.id')
            ?? '');
        $this->day_status = 'open';
        $this->default_attendance_status_id = (string) ($this->defaultTeacherAttendanceStatusId() ?? '');
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function closeCreateModal(): void
    {
        $this->showFormModal = false;
        $this->resetValidation();
    }

    public function closeExportModal(): void
    {
        $this->showExportModal = false;
    }

    public function openExportModal(): void
    {
        $this->showExportModal = true;
    }

    public function saveDay()
    {
        $this->authorizePermission('attendance.teacher.take');

        if (! AttendanceStatus::query()->where('is_active', true)->whereIn('scope', ['teacher', 'both'])->exists()) {
            $this->addError('default_attendance_status_id', __('workflow.teacher_attendance.days.form.no_default_status'));

            return null;
        }

        $validated = $this->validate([
            'attendance_date' => ['required', 'date'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'default_attendance_status_id' => [
                'required',
                'integer',
                Rule::exists('attendance_statuses', 'id')->where(fn ($query) => $query->where('is_active', true)->whereIn('scope', ['teacher', 'both'])),
            ],
        ]);

        abort_unless($this->availableCoursesQuery()->whereKey((int) $validated['course_id'])->exists(), 403);

        $day = app(TeacherAttendanceDayService::class)->createOrSyncDay(
            $validated['attendance_date'],
            $this->scheduledTeachersForDate($validated['attendance_date'], (int) $validated['course_id']),
            auth()->user(),
            null,
            'open',
            (int) $validated['default_attendance_status_id'],
            (int) $validated['course_id'],
        );

        session()->flash('status', __('workflow.teacher_attendance.days.messages.created'));

        $this->closeCreateModal();

        return redirect()->route('teacher-attendance.show', $day);
    }

    public function deleteDay(int $dayId): void
    {
        $this->authorizePermission('attendance.teacher.take');

        $day = $this->scopeTeacherAttendanceDaysQuery(
            TeacherAttendanceDay::query()->with('records')
        )->findOrFail($dayId);

        DB::transaction(function () use ($day): void {
            $day->records()->delete();
            $day->delete();
        });

        session()->flash('status', __('workflow.teacher_attendance.messages.deleted'));
    }

    protected function defaultTeacherAttendanceStatusId(): ?int
    {
        return AttendanceStatus::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereIn('scope', ['teacher', 'both'])
            ->value('id') ?? AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['teacher', 'both'])
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->value('id');
    }

    protected function scheduledTeachersForDate(string $attendanceDate, ?int $courseId = null)
    {
        try {
            $dayOfWeek = Carbon::parse($attendanceDate)->dayOfWeek;
        } catch (\Throwable) {
            return collect();
        }

        $scheduledTeacherIds = $this->scopeGroupsQuery(
            Group::query()
                ->select(['teacher_id', 'assistant_teacher_id'])
                ->where('is_active', true)
                ->when($courseId, fn (Builder $query) => $query->where('course_id', $courseId))
                ->whereHas('schedules', fn ($query) => $query
                    ->where('is_active', true)
                    ->where('day_of_week', $dayOfWeek))
        )
            ->get()
            ->flatMap(fn (Group $group) => [$group->teacher_id, $group->assistant_teacher_id])
            ->filter()
            ->map(fn ($teacherId) => (int) $teacherId)
            ->unique()
            ->values()
            ->all();

        $unassignedHelpingTeacherIds = $this->scopeTeachersQuery(
            Teacher::query()
                ->where('is_helping', true)
                ->whereIn('status', ['active', 'inactive'])
                ->whereDoesntHave('assignedGroups', fn ($query) => $query->where('is_active', true))
                ->whereDoesntHave('assistedGroups', fn ($query) => $query->where('is_active', true))
        )
            ->pluck('id')
            ->map(fn ($teacherId) => (int) $teacherId)
            ->all();

        $teacherIds = collect($scheduledTeacherIds)
            ->merge($unassignedHelpingTeacherIds)
            ->unique()
            ->values()
            ->all();

        if ($teacherIds === []) {
            return collect();
        }

        return $this->scopeTeachersQuery(
            Teacher::query()
                ->whereIn('status', ['active', 'inactive'])
                ->whereIn('id', $teacherIds)
                ->whereNotIn('id', TeacherAttendanceExclusion::query()->select('teacher_id'))
                ->orderBy('first_name')
                ->orderBy('last_name')
        )->get();
    }

    protected function availableCoursesQuery(): Builder
    {
        return Course::query()
            ->where('is_active', true)
            ->whereHas('groups', fn (Builder $query) => $this->scopeGroupsQuery($query->where('is_active', true)));
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.teacher_attendance.days.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.teacher_attendance.days.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls attendance-days-toolbar">
            <div class="admin-grid-meta__title">{{ __('workflow.teacher_attendance.days.table.title') }}</div>
            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label class="sr-only" for="teacher-attendance-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="teacher-attendance-search" wire:model.live.debounce.300ms="search" type="text" placeholder="DD-MM-YYYY">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="teacher-attendance-status-filter">{{ __('workflow.teacher_attendance.days.form.status') }}</label>
                    <select id="teacher-attendance-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                        <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('attendance.teacher.take')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.teacher_attendance.days.create') }}</button>
                    @endcan
                    <button type="button" wire:click="openExportModal" class="pill-link">{{ __('workflow.teacher_attendance.export.action') }}</button>
                </div>
            </div>
        </div>

        @if ($days->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.teacher_attendance.days.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.days.table.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.days.table.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.days.table.headers.teachers') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.days.table.headers.marked') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.days.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.teacher_attendance.days.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($days as $day)
                            <tr>
                                <td class="px-5 py-4 text-white lg:px-6">
                                    <div class="flex flex-col items-start font-semibold">
                                        <span class="text-xs font-medium text-neutral-400">{{ $day->attendance_date?->locale(app()->getLocale())->translatedFormat('l') }}</span>
                                        <span class="mt-1">{{ $day->attendance_date?->format('d-m-Y') }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $day->course?->name ?: __('workflow.common.no_course') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $day->records_count) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $day->present_records_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $day->status === 'closed' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('workflow.common.day_status.'.$day->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('teacher-attendance.show', $day) }}" wire:navigate class="pill-link pill-link--compact">
                                            {{ __('workflow.teacher_attendance.days.table.view') }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($days->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $days->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="__('workflow.teacher_attendance.days.form.title')"
        close-method="closeCreateModal"
        max-width="3xl"
        compact
    >
        <form wire:submit="saveDay" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="teacher-attendance-day-course" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.days.form.course') }}</label>
                    <select id="teacher-attendance-day-course" wire:model.live="course_id" class="h-12 min-h-12 w-full rounded-xl px-4 py-0 text-sm">
                        <option value="">{{ __('workflow.teacher_attendance.days.form.select_course') }}</option>
                        @foreach ($courseOptions as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                    @error('course_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex h-12 min-h-12 box-border items-center self-end rounded-xl border border-white/10 bg-white/5 px-4 text-sm font-semibold">
                    {{ __(app()->isLocale('ar') && $scheduledTeacherCount > 10 ? 'workflow.teacher_attendance.days.form.scheduled_teacher_singular_help' : 'workflow.teacher_attendance.days.form.scheduled_teachers_help', ['count' => number_format($scheduledTeacherCount)]) }}
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="teacher-attendance-day-date" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.days.form.attendance_date') }}</label>
                    <input id="teacher-attendance-day-date" wire:model.live="attendance_date" value="{{ $attendance_date }}" type="date" class="h-12 min-h-12 w-full rounded-xl px-4 py-0 text-sm">
                    @error('attendance_date')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
                <div>
                    <label for="teacher-attendance-day-default-status" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.days.form.default_status') }}</label>
                    <select id="teacher-attendance-day-default-status" wire:model="default_attendance_status_id" class="h-12 min-h-12 w-full rounded-xl px-4 py-0 text-sm">
                        <option value="">{{ __('workflow.teacher_attendance.days.form.no_default_status') }}</option>
                        @foreach ($defaultStatusOptions as $status)
                            <option value="{{ $status->id }}">{{ $status->name }}{{ $status->is_default ? ' - '.__('settings.tracking.labels.default_attendance_status') : '' }}</option>
                        @endforeach
                    </select>
                    @error('default_attendance_status_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.teacher_attendance.days.create') }}</button>
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>

    @teleport('body')
    <div class="admin-modal-portal">
        <x-admin.modal :show="$showExportModal" :title="__('workflow.teacher_attendance.export.title')" close-method="closeExportModal" max-width="2xl" full-viewport>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.export.from') }}</label><input wire:model.live="export_date_from" type="date" class="w-full rounded-xl px-4 py-3"></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.export.to') }}</label><input wire:model.live="export_date_to" type="date" class="w-full rounded-xl px-4 py-3"></div>
            </div>
            <div class="attendance-export-actions mt-5 flex justify-end"><a href="{{ route('teacher-attendance.export', ['date_from' => $export_date_from, 'date_to' => $export_date_to]) }}" target="_blank" class="pill-link pill-link--accent">{{ __('workflow.teacher_attendance.export.action') }}</a></div>
        </x-admin.modal>
    </div>
    @endteleport
</div>
