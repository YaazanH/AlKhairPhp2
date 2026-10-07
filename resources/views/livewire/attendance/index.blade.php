<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Group;
use App\Models\StudentAttendanceDay;
use App\Models\TeacherAttendanceDay;
use App\Services\StudentAttendanceDayService;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public string $exportType = 'students';

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

    public string $export_course_id = '';

    public string $export_date_from = '';

    public string $export_date_to = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->canAny(['attendance.student.view', 'attendance.teacher.view']), 403);
        $this->exportType = $this->canPermission('attendance.student.view') ? 'students' : 'teachers';
        $this->attendance_date = now()->toDateString();
        $this->course_id = (string) ($this->availableCoursesQuery()->where('is_default', true)->value('courses.id')
            ?? $this->availableCoursesQuery()->value('courses.id')
            ?? '');
        $this->export_course_id = (string) ($this->availableCoursesQuery()->where('is_default', true)->value('courses.id') ?? '');
        $this->export_date_from = now()->startOfMonth()->toDateString();
        $this->export_date_to = now()->toDateString();
    }

    public function with(): array
    {
        $daysQuery = $this->scopeStudentAttendanceDaysQuery(
            StudentAttendanceDay::query()->with([
                'course',
                'groupAttendanceDays' => fn ($query) => $this->scopeGroupAttendanceDaysQuery(
                    $query->withCount([
                        'records',
                        'records as present_records_count' => fn ($recordQuery) => $recordQuery
                            ->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_present', true)),
                    ])->with([
                        'group' => fn ($groupQuery) => $groupQuery->withCount([
                            'enrollments as active_enrollments_count' => fn ($enrollmentQuery) => $enrollmentQuery->where('status', 'active'),
                        ]),
                    ])
                ),
            ])
        )
            ->whereNull('course_finished_at');

        $studentDays = $this->canPermission('attendance.student.view') ? $daysQuery->get() : collect();
        $teacherDays = $this->canPermission('attendance.teacher.view')
            ? $this->scopeTeacherAttendanceDaysQuery(TeacherAttendanceDay::query()->with('course')
                ->where(fn ($query) => $query->whereHas('course', fn ($course) => $course->whereNull('finished_at'))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('course_id')->where(fn ($records) => $records->whereDoesntHave('records')->orWhereHas('records', fn ($record) => $record->whereNull('course_finished_at')))))
                ->withCount(['records as present_records_count' => fn ($query) => $this->scopeTeacherAttendanceRecordsQuery($query)->whereNull('course_finished_at')->whereHas('status', fn ($status) => $status->where('is_present', true))]))->get()
            : collect();
        $rows = $studentDays->map(function ($day) {
            $day->source_type = 'students';
            $day->row_key = $day->attendance_date->toDateString().':'.$day->course_id;
            $day->teacher_present_count = null;

            return $day;
        })->keyBy('row_key');
        foreach ($teacherDays as $teacherDay) {
            $key = $teacherDay->attendance_date->toDateString().':'.$teacherDay->course_id;
            if ($rows->has($key)) {
                $rows[$key]->teacher_present_count = $teacherDay->present_records_count;
                $rows[$key]->status = $rows[$key]->status === 'open' || $teacherDay->status === 'open' ? 'open' : 'closed';
            } else {
                $teacherDay->source_type = 'teachers';
                $teacherDay->row_key = $key;
                $teacherDay->teacher_present_count = $teacherDay->present_records_count;
                $teacherDay->setRelation('groupAttendanceDays', collect());
                $rows->put($key, $teacherDay);
            }
        }
        $rows = $rows->sortBy('row_key')->values();
        $dayNumbers = $rows->pluck('row_key')->flip()->map(fn ($index) => $index + 1);
        $rows = $rows->filter(fn ($day) => (! filled($this->search) || $day->attendance_date->toDateString() === $this->search)
            && (! in_array($this->statusFilter, ['open', 'closed'], true) || $day->status === $this->statusFilter))->reverse()->values();
        $days = new LengthAwarePaginator($rows->forPage($this->getPage(), $this->perPage), $rows->count(), $this->perPage, $this->getPage(), ['path' => request()->url()]);

        $scheduledGroupCount = filled($this->attendance_date) && $this->normalizedCourseId()
            ? $this->scheduledGroupsForDate($this->attendance_date, $this->normalizedCourseId())->count()
            : 0;

        return [
            'days' => $days,
            'dayNumbers' => $dayNumbers,
            'filteredCount' => $rows->count(),
            'courseOptions' => $this->availableCoursesQuery()
                ->orderBy('name')
                ->get(['id', 'name']),
            'scheduledGroupCount' => $scheduledGroupCount,
            'defaultStatusOptions' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', [$this->creationScope(), 'both'])
                ->orderByDesc('is_default')
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->get(),
            'exportCourseOptions' => $this->availableCoursesQuery()->orderBy('name')->get(['id', 'name']),
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
        $this->authorizePermission('attendance.'.$this->creationScope().'.view');
        $this->authorizePermission('attendance.'.$this->creationScope().'.take');

        $this->attendance_date = now()->toDateString();
        $this->course_id = (string) ($this->availableCoursesQuery()->where('is_default', true)->value('courses.id')
            ?? $this->availableCoursesQuery()->value('courses.id')
            ?? '');
        $this->day_status = 'open';
        $this->default_attendance_status_id = (string) ($this->defaultStudentAttendanceStatusId() ?? '');
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
        $this->authorizePermission('attendance.'.$this->creationScope().'.view');
        $this->authorizePermission('attendance.'.$this->creationScope().'.take');

        if (! AttendanceStatus::query()->where('is_active', true)->whereIn('scope', [$this->creationScope(), 'both'])->exists()) {
            $this->addError('default_attendance_status_id', __('workflow.student_attendance.days.form.no_default_status'));

            return null;
        }

        $validated = $this->validate([
            'attendance_date' => ['required', 'date'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'default_attendance_status_id' => [
                'required',
                'integer',
                Rule::exists('attendance_statuses', 'id')->where(fn ($query) => $query->where('is_active', true)->whereIn('scope', [$this->creationScope(), 'both'])),
            ],
        ]);

        $course = $this->availableCoursesQuery()
            ->whereKey((int) $validated['course_id'])
            ->first();

        if (! $course) {
            $this->addError('course_id', __('workflow.student_attendance.days.form.course_unavailable'));

            return null;
        }

        $groups = $this->scheduledGroupsForDate($validated['attendance_date'], (int) $validated['course_id']);

        $day = DB::transaction(function () use ($validated, $groups) {
            $studentDay = null;
            if ($this->canPermission('attendance.student.take') && $this->canPermission('attendance.student.view')) {
                $studentDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
                    $validated['attendance_date'], $groups, auth()->user(), null, 'open',
                    (int) $validated['default_attendance_status_id'], (int) $validated['course_id'],
                );
            }
            if ($this->canPermission('attendance.teacher.take') && $this->canPermission('attendance.teacher.view')) {
                $teachers = app(TeacherAttendanceDayService::class);
                $teacherDay = $teachers->createOrSyncDay($validated['attendance_date'],
                    $teachers->scheduledTeachers($validated['attendance_date'], (int) $validated['course_id'], auth()->user()),
                    auth()->user(), null, $studentDay?->status ?? 'open',
                    $studentDay ? null : (int) $validated['default_attendance_status_id'], (int) $validated['course_id']);
            }

            return $studentDay ?? $teacherDay;
        });

        session()->flash('status', __('workflow.student_attendance.days.messages.created'));

        $this->closeCreateModal();

        return redirect()->route('attendance.show', ['type' => $day instanceof StudentAttendanceDay ? 'students' : 'teachers', 'day' => $day->id]);
    }

    protected function creationScope(): string
    {
        return $this->canPermission('attendance.student.take') && $this->canPermission('attendance.student.view') ? 'student' : 'teacher';
    }

    public function switchExportType(string $type): void
    {
        abort_unless(in_array($type, ['students', 'teachers'], true), 404);
        $this->authorizePermission('attendance.'.($type === 'students' ? 'student' : 'teacher').'.view');
        $this->exportType = $type;
    }

    protected function defaultStudentAttendanceStatusId(): ?int
    {
        return AttendanceStatus::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereIn('scope', [$this->creationScope(), 'both'])
            ->value('id') ?? AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', [$this->creationScope(), 'both'])
            ->orderByDesc('is_present')
            ->orderBy('name')
            ->value('id');
    }

    protected function scheduledGroupsForDate(string $attendanceDate, ?int $courseId = null)
    {
        if (! $courseId) {
            return collect();
        }

        try {
            $dayOfWeek = Carbon::parse($attendanceDate)->dayOfWeek;
        } catch (Throwable) {
            return collect();
        }

        return $this->scopeGroupsQuery(
            Group::query()
                ->with(['course', 'teacher'])
                ->where('is_active', true)
                ->where('course_id', $courseId)
                ->whereHas('schedules', fn ($scheduleQuery) => $scheduleQuery
                    ->where('is_active', true)
                    ->where('day_of_week', $dayOfWeek))
                ->orderBy('name')
        )->get();
    }

    protected function availableCoursesQuery()
    {
        return Course::query()
            ->where('is_active', true)
            ->whereHas('groups', fn (Builder $query) => $this->scopeGroupsQuery($query->where('is_active', true)));
    }

    protected function normalizedCourseId(): ?int
    {
        $courseId = (int) $this->course_id;

        return $courseId > 0 ? $courseId : null;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('attendance.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('attendance.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls attendance-days-toolbar">
            <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.days.table.title') }}</div>
            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label class="sr-only" for="student-attendance-search">{{ __('workflow.student_attendance.days.form.attendance_date') }}</label>
                    <input id="student-attendance-search" wire:model.live="search" type="date" aria-label="{{ __('workflow.student_attendance.days.form.attendance_date') }}" data-date-placeholder="{{ __('workflow.student_attendance.days.form.attendance_date') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="student-attendance-status">{{ __('workflow.student_attendance.days.form.status') }}</label>
                    <select id="student-attendance-status" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                        <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @if (($this->canPermission('attendance.student.view') && $this->canPermission('attendance.student.take')) || ($this->canPermission('attendance.teacher.view') && $this->canPermission('attendance.teacher.take')))
                        <x-add-action-button wire:click="openCreateModal" :label="__('workflow.student_attendance.days.create')" />
                    @endif
                    <x-export-action-button wire:click="openExportModal" :label="__('workflow.student_attendance.export.action')" />
                    @if (auth()->user()->can('barcode-scans.import'))
                    @if ((bool) (\App\Models\AppSetting::groupValues('dashboard')->get('barcode_scanner_enabled') ?? true))
                        <a href="{{ route('barcode-actions.import') }}" wire:navigate class="pill-link">{{ __('ui.nav.scanner_import') }}</a>
                    @endif
                    @endif
                </div>
            </div>
        </div>

    @teleport('body')
    <div class="admin-modal-portal attendance-export-modal">
        <x-admin.modal :show="$showExportModal" :title="__('attendance.export')" close-method="closeExportModal" max-width="2xl" full-viewport>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.export.course') }}</label><select wire:model.live="export_course_id" class="w-full rounded-xl px-4 py-3" data-record-label="course"><option value="">{{ __('workflow.student_attendance.export.select_course') }}</option>@foreach($exportCourseOptions as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.export.from') }}</label><input wire:model.live="export_date_from" type="date" class="w-full rounded-xl px-4 py-3"></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.export.to') }}</label><input wire:model.live="export_date_to" type="date" class="w-full rounded-xl px-4 py-3"></div>
            </div>
            <div class="mt-5"><x-attendance-type-switch :selected="$exportType" action="switchExportType" /></div>
            <div class="attendance-export-actions mt-5 flex justify-end"><x-export-action-button :href="route($exportType === 'students' ? 'student-attendance.export' : 'teacher-attendance.export', ['course_id' => $export_course_id, 'date_from' => $export_date_from, 'date_to' => $export_date_to])" target="_blank" class="admin-icon-button--accent {{ $export_course_id === '' ? 'pointer-events-none opacity-50' : '' }}" :label="__('workflow.student_attendance.export.action')" /></div>
        </x-admin.modal>
    </div>
    @endteleport

        @if ($days->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_attendance.days.table.empty') }}</div>
        @else
            <div class="attendance-days-table-wrapper overflow-x-auto">
                <table class="attendance-days-table text-sm">
                    <thead>
                        <tr>
                            <th data-table-number-column scope="col" class="attendance-days-number px-5 py-4 text-center lg:px-6">#</th>
                            <th class="attendance-days-date px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.date') }}</th>
                            <th class="attendance-days-course px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.course') }}</th>
                            <th class="attendance-days-mobile-hidden px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.students') }}</th>
                            <th class="attendance-days-mobile-hidden px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.attended') }}</th>
                            <th class="attendance-days-mobile-hidden px-5 py-4 text-left lg:px-6">{{ __('attendance.teachers_column') }}</th>
                            <th class="attendance-days-mobile-hidden px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.status') }}</th>
                            <th class="attendance-days-actions admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('workflow.student_attendance.days.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($days as $day)
                            @php
                                $groupCount = $day->groupAttendanceDays->count();
                                $studentCount = $day->groupAttendanceDays->sum(fn ($groupDay) => (int) ($groupDay->group?->active_enrollments_count ?? 0));
                                $attendedCount = $day->groupAttendanceDays->sum('present_records_count');
                            @endphp
                            <tr>
                                <td class="attendance-days-number px-5 py-4 text-center text-neutral-300 lg:px-6">{{ $dayNumbers[$day->row_key] }}</td>
                                <td class="attendance-days-date px-5 py-4 text-white lg:px-6">
                                    <div class="flex flex-col items-start font-semibold">
                                        <span class="text-xs font-medium text-neutral-400">{{ $day->attendance_date?->locale(app()->getLocale())->translatedFormat('l') }}</span>
                                        <span class="mt-1">{{ \App\Support\DateDisplay::html($day->attendance_date?->format('d-m-Y')) }}</span>
                                    </div>
                                </td>
                                <td class="attendance-days-course px-5 py-4 text-neutral-300 lg:px-6">
                                    <div><span class="record-course-name">{{ $day->course?->name ?: __('workflow.common.no_course') }}</span></div>
                                </td>
                                <td class="attendance-days-mobile-hidden px-5 py-4 text-neutral-300 lg:px-6">{{ $day->source_type === 'teachers' ? '—' : number_format($studentCount) }}</td>
                                <td class="attendance-days-mobile-hidden px-5 py-4 text-neutral-300 lg:px-6">{{ $day->source_type === 'teachers' ? '—' : number_format($attendedCount) }}</td>
                                <td class="attendance-days-mobile-hidden px-5 py-4 text-neutral-300 lg:px-6">{{ $day->teacher_present_count === null ? '—' : number_format($day->teacher_present_count) }}</td>
                                <td class="attendance-days-mobile-hidden px-5 py-4 lg:px-6">
                                    <span class="{{ $day->status === 'closed' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('workflow.common.day_status.'.$day->status) }}
                                    </span>
                                </td>
                                <td class="attendance-days-actions px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <x-open-action-button :href="route('attendance.show', ['type' => $day->source_type, 'day' => $day->id])" :class="$day->status === 'open' ? 'admin-icon-button--accent' : ''" wire:navigate :label="__('workflow.student_attendance.days.table.view')" />
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
        :title="__('attendance.create')"
        close-method="closeCreateModal"
        max-width="3xl"
        compact
    >
        <form wire:submit="saveDay" class="date-control-peer-group space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="attendance-day-course" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.days.form.course') }}</label>
                    <select id="attendance-day-course" wire:model.live="course_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true" class="h-12 min-h-12 w-full rounded-xl px-4 py-0 text-sm" data-record-label="course">
                        <option value="" disabled hidden>{{ __('workflow.student_attendance.days.form.select_course') }}</option>
                        @foreach ($courseOptions as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                    @error('course_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex h-12 min-h-12 box-border items-center self-end rounded-xl border border-white/10 bg-white/5 px-4 text-sm font-semibold">
                    {{ __('counts.groups', ['count' => number_format($scheduledGroupCount)]) }}
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="attendance-day-date" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.days.form.attendance_date') }}</label>
                    <input id="attendance-day-date" wire:model.live="attendance_date" value="{{ $attendance_date }}" type="date" required aria-required="true" data-clearable="false" class="date-control--match-select h-12 min-h-12 w-full rounded-xl px-4 py-0 text-sm">
                    @error('attendance_date')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
                <div>
                    <label for="attendance-day-default-status" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.days.form.default_status') }}</label>
                    <select id="attendance-day-default-status" wire:model="default_attendance_status_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true" class="h-12 min-h-12 w-full rounded-xl px-4 py-0 text-sm">
                        <option value="" disabled hidden>{{ __('workflow.student_attendance.days.form.no_default_status') }}</option>
                        @foreach ($defaultStatusOptions as $status)
                            <option value="{{ $status->id }}">{{ $status->name }}</option>
                        @endforeach
                    </select>
                    @error('default_attendance_status_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="admin-icon-button admin-icon-button--accent admin-modal-action-button"
                    title="{{ __('workflow.student_attendance.days.create') }}"
                    aria-label="{{ __('workflow.student_attendance.days.create') }}"
                    data-student-attendance-day-save-action
                >
                    <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                </button>
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
