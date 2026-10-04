<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceExclusion;
use App\Models\TeacherAttendanceRecord;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public TeacherAttendanceDay $currentDay;

    public string $day_status = 'open';

    public string $notes = '';

    public string $manual_teacher_id = '';

    public bool $showManualTeacherModal = false;

    public array $selected_statuses = [];

    public function mount(TeacherAttendanceDay $teacherAttendanceDay): void
    {
        $this->authorizePermission('attendance.teacher.view');

        $this->currentDay = $this->scopeTeacherAttendanceDaysQuery(
            TeacherAttendanceDay::query()
        )
            ->where(function ($dayQuery): void {
                $dayQuery
                    ->whereHas('course', fn ($courseQuery) => $courseQuery->whereNull('finished_at'))
                    ->orWhere(function ($legacyDayQuery): void {
                        $legacyDayQuery
                            ->whereNull('course_id')
                            ->where(function ($recordQuery): void {
                                $recordQuery
                                    ->whereDoesntHave('records')
                                    ->orWhereHas('records', fn ($query) => $query->whereNull('course_finished_at'));
                            });
                    });
            })
            ->findOrFail($teacherAttendanceDay->id);

        $this->authorizeScopedTeacherAttendanceDayAccess($this->currentDay);
        $this->loadDay();
    }

    public function with(): array
    {
        $day = $this->currentDay->fresh([
            'records' => fn ($query) => $this->scopeTeacherAttendanceRecordsQuery(
                $query->with(['teacher.accessRole', 'status'])->whereNull('course_finished_at')
            ),
        ]);

        $this->authorizeScopedTeacherAttendanceDayAccess($day);

        $teacherRecords = $day->records
            ->filter(fn (TeacherAttendanceRecord $record) => $record->teacher)
            ->sortBy(fn (TeacherAttendanceRecord $record) => trim(($record->teacher->first_name ?? '').' '.($record->teacher->last_name ?? '')))
            ->values();

        $existingTeacherIds = $teacherRecords
            ->pluck('teacher_id')
            ->map(fn ($teacherId) => (int) $teacherId)
            ->all();

        $teacherAttendancePercentages = $existingTeacherIds === []
            ? collect()
            : $this->scopeTeacherAttendanceRecordsQuery(
                TeacherAttendanceRecord::query()
                    ->with('status:id,is_present')
                    ->whereIn('teacher_id', $existingTeacherIds)
                    ->whereNull('course_finished_at')
                    ->whereHas('attendanceDay', function ($query) use ($day): void {
                        $query->whereDate('attendance_date', '<=', $day->attendance_date);

                        if ($day->course_id) {
                            $query->where('course_id', $day->course_id);
                        } else {
                            $query->whereNull('course_id');
                        }
                    })
            )
                ->get(['id', 'teacher_attendance_day_id', 'teacher_id', 'attendance_status_id'])
                ->groupBy('teacher_id')
                ->map(function ($records): int {
                    $listedDays = $records->pluck('teacher_attendance_day_id')->unique()->count();
                    $presentDays = $records
                        ->filter(fn (TeacherAttendanceRecord $record) => (bool) $record->status?->is_present)
                        ->pluck('teacher_attendance_day_id')
                        ->unique()
                        ->count();

                    return $listedDays > 0 ? (int) ceil(($presentDays / $listedDays) * 100) : 0;
                });

        $scheduledTeacherIds = $this->scheduledTeacherIdsForDate($day->attendance_date?->format('Y-m-d'));
        $statuses = AttendanceStatus::query()->where('is_active', true)
            ->whereIn('scope', ['teacher', 'both'])->orderBy('name')->get();

        return [
            'dayRecord' => $day,
            'teacherRecords' => $teacherRecords,
            'teacherAttendancePercentages' => $teacherAttendancePercentages,
            'availableExtraTeachers' => $this->availableTeachersScopeQuery()
                ->with('accessRole')
                ->when($existingTeacherIds !== [], fn ($query) => $query->whereNotIn('id', $existingTeacherIds))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(),
            'statuses' => $statuses,
            'attendanceChoices' => collect([
                $statuses->firstWhere('code', 'present') ?? $statuses->firstWhere('is_present', true),
                $statuses->firstWhere('code', 'absent') ?? $statuses->firstWhere('is_present', false),
            ])->filter(),
            'stats' => [
                'teachers' => $teacherRecords->count(),
                'scheduled' => count(array_intersect($existingTeacherIds, $scheduledTeacherIds)),
                'marked' => $teacherRecords
                    ->filter(fn (TeacherAttendanceRecord $record) => (bool) $record->status?->is_present)
                    ->count(),
            ],
        ];
    }

    public function saveAttendance(): void
    {
        $this->authorizePermission('attendance.teacher.take');
        abort_if($this->currentDay->fresh()->status === 'closed', 409, __('workflow.teacher_attendance.errors.day_closed'));

        $validated = $this->validate([
            'day_status' => ['required', 'in:open,closed'],
            'notes' => ['nullable', 'string'],
            'selected_statuses' => ['array'],
            'selected_statuses.*' => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        $archivedTeacherIds = $this->currentDay->records()
            ->whereNotNull('course_finished_at')
            ->pluck('teacher_id')
            ->map(fn ($teacherId) => (int) $teacherId)
            ->all();

        $editableStatuses = collect($validated['selected_statuses'])
            ->reject(fn ($statusId, $teacherId) => in_array((int) $teacherId, $archivedTeacherIds, true))
            ->all();

        $allowedTeacherIds = $this->currentDay->records()
            ->whereNull('course_finished_at')
            ->pluck('teacher_id')
            ->map(fn ($teacherId) => (int) $teacherId)
            ->all();

        $selectedTeacherIds = collect(array_keys(array_filter($editableStatuses)))
            ->map(fn ($teacherId) => (int) $teacherId)
            ->values();

        if ($selectedTeacherIds->diff($allowedTeacherIds)->isNotEmpty()) {
            $this->addError('selected_statuses', __('workflow.teacher_attendance.errors.teacher_not_helping'));

            return;
        }

        $this->currentDay->update([
            'status' => $validated['day_status'],
            'notes' => $validated['notes'] ?: null,
        ]);

        foreach (array_filter($editableStatuses) as $teacherId => $statusId) {
            TeacherAttendanceRecord::query()->updateOrCreate(
                [
                    'teacher_attendance_day_id' => $this->currentDay->id,
                    'teacher_id' => (int) $teacherId,
                ],
                [
                    'attendance_status_id' => $statusId,
                ],
            );
        }

        $this->loadDay();
        session()->flash('status', __('workflow.teacher_attendance.messages.saved'));
    }

    public function saveDaySummary(): void
    {
        $this->authorizePermission('attendance.teacher.take');

        $validated = $this->validate([
            'day_status' => ['required', 'in:open,closed'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->currentDay->update([
            'status' => $validated['day_status'],
            'notes' => $validated['notes'] ?: null,
        ]);

        $this->loadDay();
    }

    public function toggleDayStatus(): void
    {
        $this->authorizePermission('attendance.teacher.take');

        $this->closeManualTeacherModal();

        $this->day_status = $this->currentDay->fresh()->status === 'closed' ? 'open' : 'closed';
        $this->currentDay->update(['status' => $this->day_status]);
        $this->loadDay();
    }

    public function chooseTeacherStatus(int $teacherId, int $statusId): void
    {
        $this->authorizePermission('attendance.teacher.take');
        AttendanceStatus::query()->where('is_active', true)->whereIn('scope', ['teacher', 'both'])->findOrFail($statusId);
        $this->selected_statuses[$teacherId] = (string) $statusId;
        $this->saveTeacherStatus($teacherId);
    }

    public function saveTeacherStatus(int $teacherId): void
    {
        $this->authorizePermission('attendance.teacher.take');
        abort_if($this->currentDay->fresh()->status === 'closed', 409, __('workflow.teacher_attendance.errors.day_closed'));
        abort_if(
            $this->currentDay->records()->where('teacher_id', $teacherId)->whereNotNull('course_finished_at')->exists(),
            409,
            __('workflow.teacher_attendance.errors.archived_record_locked'),
        );

        $this->validate([
            'selected_statuses.'.$teacherId => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        $statusId = $this->selected_statuses[$teacherId] ?? null;

        if (! $statusId) {
            return;
        }

        abort_unless(
            $this->currentDay->records()->where('teacher_id', $teacherId)->exists(),
            403,
        );

        $teacher = $this->availableTeachersScopeQuery()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        TeacherAttendanceRecord::query()->updateOrCreate(
            [
                'teacher_attendance_day_id' => $this->currentDay->id,
                'teacher_id' => $teacher->id,
            ],
            [
                'attendance_status_id' => $statusId,
            ],
        );

        $this->loadDay();
    }

    public function openManualTeacherModal(): void
    {
        $this->authorizePermission('attendance.teacher.take');
        abort_if($this->currentDay->fresh()->status === 'closed', 409, __('workflow.teacher_attendance.errors.day_closed'));

        $this->manual_teacher_id = '';
        $this->showManualTeacherModal = true;
        $this->resetValidation('manual_teacher_id');
    }

    public function closeManualTeacherModal(): void
    {
        $this->manual_teacher_id = '';
        $this->showManualTeacherModal = false;
        $this->resetValidation('manual_teacher_id');
    }

    public function addManualTeacher(): void
    {
        $this->authorizePermission('attendance.teacher.take');
        abort_if($this->currentDay->fresh()->status === 'closed', 409, __('workflow.teacher_attendance.errors.day_closed'));

        $validated = $this->validate(
            ['manual_teacher_id' => ['required', 'integer', 'exists:teachers,id']],
            [],
            ['manual_teacher_id' => __('workflow.teacher_attendance.day_details.manual_add.teacher')]
        );

        $teacher = $this->availableTeachersScopeQuery()
            ->whereKey((int) $validated['manual_teacher_id'])
            ->first();

        if (! $teacher) {
            $this->addError('manual_teacher_id', __('workflow.teacher_attendance.day_details.manual_add.errors.unavailable'));

            return;
        }

        DB::transaction(function () use ($teacher): void {
            TeacherAttendanceExclusion::query()->where('teacher_id', $teacher->id)->delete();

            app(TeacherAttendanceDayService::class)->createOrSyncDay(
                $this->currentDay->attendance_date->format('Y-m-d'),
                collect([$teacher]),
                auth()->user(),
                $this->notes,
                $this->day_status,
                $this->defaultTeacherAttendanceStatusId(),
            );
        });

        $this->loadDay();
        $this->closeManualTeacherModal();

        session()->flash('status', __('workflow.teacher_attendance.day_details.manual_add.messages.added'));
    }

    public function removeTeacher(int $teacherId): void
    {
        $this->authorizePermission('attendance.teacher.take');
        abort_if($this->currentDay->fresh()->status === 'closed', 409, __('workflow.teacher_attendance.errors.day_closed'));

        $record = $this->scopeTeacherAttendanceRecordsQuery(
            TeacherAttendanceRecord::query()->where('teacher_attendance_day_id', $this->currentDay->id)
        )->where('teacher_id', $teacherId)->firstOrFail();
        abort_if($record->course_finished_at, 409, __('workflow.teacher_attendance.errors.archived_record_locked'));
        $teacher = $this->availableTeachersScopeQuery()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        DB::transaction(function () use ($teacher): void {
            TeacherAttendanceExclusion::query()->updateOrCreate(
                ['teacher_id' => $teacher->id],
                ['excluded_by' => auth()->id(), 'excluded_at' => now()],
            );

            $this->scopeTeacherAttendanceRecordsQuery(
                TeacherAttendanceRecord::query()
                    ->where('teacher_id', $teacher->id)
                    ->whereNull('course_finished_at')
                    ->whereHas('attendanceDay', fn ($query) => $query->whereDate('attendance_date', '>=', $this->currentDay->attendance_date))
            )->delete();
        });

        unset($this->selected_statuses[$teacherId]);
        $this->loadDay();
        session()->flash('status', __('workflow.teacher_attendance.messages.teacher_removed'));
    }

    public function deleteDay(): void
    {
        $this->authorizePermission('attendance.teacher.take');
        abort_if(
            $this->currentDay->records()->whereNotNull('course_finished_at')->exists(),
            409,
            __('workflow.teacher_attendance.errors.archived_day_locked'),
        );

        $this->currentDay->records()->delete();
        $this->currentDay->delete();

        session()->flash('status', __('workflow.teacher_attendance.messages.deleted'));

        $this->redirect(route('teacher-attendance.index'), navigate: true);
    }

    protected function loadDay(): void
    {
        app(TeacherAttendanceDayService::class)->fillMissingStatuses(
            $this->currentDay,
            $this->defaultTeacherAttendanceStatusId(),
        );

        $this->currentDay = $this->scopeTeacherAttendanceDaysQuery(
            TeacherAttendanceDay::query()->with([
                'records' => fn ($query) => $query->whereNull('course_finished_at'),
            ])
        )->findOrFail($this->currentDay->id);

        $this->authorizeScopedTeacherAttendanceDayAccess($this->currentDay);

        $this->day_status = $this->currentDay->status ?? 'open';
        $this->notes = $this->currentDay->notes ?? '';
        $this->manual_teacher_id = '';
        $this->selected_statuses = $this->currentDay->records
            ->mapWithKeys(fn (TeacherAttendanceRecord $record) => [$record->teacher_id => $record->attendance_status_id])
            ->toArray();
    }

    protected function attendanceStatusLabel(?AttendanceStatus $status): string
    {
        return match ($status?->code) {
            'present', 'absent' => __('workflow.teacher_attendance.table.'.$status->code),
            default => $status?->name ?: '-',
        };
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

    protected function availableTeachersScopeQuery()
    {
        return $this->scopeTeachersQuery(
            Teacher::query()->whereIn('status', ['active', 'inactive'])
        );
    }

    protected function scheduledTeacherIdsForDate(?string $attendanceDate): array
    {
        if (blank($attendanceDate)) {
            return [];
        }

        $dayOfWeek = Carbon::parse($attendanceDate)->dayOfWeek;

        return $this->scopeGroupsQuery(
            Group::query()
                ->select(['teacher_id', 'assistant_teacher_id'])
                ->where('is_active', true)
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
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
            <div>
                <x-back-link :href="route('teacher-attendance.index')" navigate />
                <div class="eyebrow mt-4">{{ __('ui.nav.tracking') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.teacher_attendance.day_details.title') }}</h1>
            </div>
            <div class="shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner" data-teacher-attendance-day-date-metric>
                <div class="text-xs text-neutral-300">{{ __('workflow.teacher_attendance.form.attendance_date') }}</div>
                <bdi dir="ltr" class="mt-1 block text-lg font-semibold text-emerald-100">{{ \App\Support\DateDisplay::html($dayRecord->attendance_date?->format('d-m-Y') ?: __('workflow.common.not_available')) }}</bdi>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @can('attendance.teacher.take')
        <x-admin.modal
            :show="$showManualTeacherModal"
            :title="__('workflow.teacher_attendance.day_details.manual_add.title')"
            :description="__('workflow.teacher_attendance.day_details.manual_add.help')"
            close-method="closeManualTeacherModal"
            max-width="xl"
            compact
        >
            <form wire:submit="addManualTeacher" class="space-y-4">
                <div>
                    <label for="manual-attendance-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.day_details.manual_add.teacher') }}</label>
                    <select id="manual-attendance-teacher" wire:model="manual_teacher_id" class="w-full rounded-xl px-4 py-3 text-sm" data-record-label="person">
                        <option value="">{{ __('workflow.teacher_attendance.day_details.manual_add.select_teacher') }}</option>
                        @foreach ($availableExtraTeachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('manual_teacher_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="admin-action-cluster admin-action-cluster--end">
                    <button type="button" wire:click="closeManualTeacherModal" class="pill-link pill-link--compact">{{ __('crud.common.actions.cancel') }}</button>
                    <button type="submit" class="pill-link pill-link--accent pill-link--compact">{{ __('workflow.teacher_attendance.day_details.manual_add.action') }}</button>
                </div>
            </form>
        </x-admin.modal>
    @endcan

    @can('attendance.teacher.take')
        @error('selected_statuses')
            <div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
        @enderror
    @endcan

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls attendance-day-toolbar teacher-attendance-toolbar">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.teacher_attendance.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ trans_choice('workflow.teacher_attendance.table.summary', $stats['marked'], ['count' => number_format($stats['marked'])]) }}</div>
            </div>
            @can('attendance.teacher.take')
                <div class="admin-toolbar__actions">
                    @php
                        $dayStatusActionLabel = $dayRecord->status === 'closed'
                            ? __('workflow.student_attendance.day_details.controls.reopen_day')
                            : __('workflow.student_attendance.day_details.controls.close_day');
                    @endphp
                    <button wire:click="toggleDayStatus" wire:key="teacher-attendance-day-status-action-{{ $dayRecord->id }}" type="button" class="admin-icon-button" title="{{ $dayStatusActionLabel }}" aria-label="{{ $dayStatusActionLabel }}" data-teacher-attendance-day-status-action>
                        @if ($dayRecord->status === 'closed')
                            <x-admin-action-icon name="unlock" />
                        @else
                            <x-admin-action-icon name="lock" />
                        @endif
                    </button>
                    @if ($dayRecord->status !== 'closed')
                        <x-add-action-button wire:click="openManualTeacherModal" wire:key="teacher-attendance-add-teacher-action-{{ $dayRecord->id }}" :label="__('workflow.teacher_attendance.day_details.manual_add.action')" @disabled($availableExtraTeachers->isEmpty()) />
                        <button wire:click="deleteDay" wire:key="teacher-attendance-day-delete-action-{{ $dayRecord->id }}" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" type="button" class="admin-icon-button admin-icon-button--danger" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-teacher-attendance-day-delete-action>
                            <x-admin-action-icon name="delete" />
                        </button>
                    @endif
                </div>
            @endcan
        </div>

        @if ($teacherRecords->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.teacher_attendance.table.empty') }}</div>
        @else
            <div class="overflow-x-auto overflow-y-visible">
                <table class="attendance-records-table teacher-attendance-records-table text-sm {{ $dayRecord->status === 'closed' ? 'teacher-attendance-records-table--closed' : '' }}" data-attendance-records>
                    <thead>
                        <tr>
                            <th data-table-number-column scope="col" class="attendance-row-number px-3 py-4 text-center">#</th>
                            <th class="attendance-person-column px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.teacher') }}</th>
                            <th class="attendance-desktop-only px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.access_role') }}</th>
                            <th class="attendance-desktop-only px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.percentage') }}</th>
                            <th class="teacher-attendance-status-column px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.attendance') }}</th>
                            @can('attendance.teacher.take')
                                @if ($dayRecord->status !== 'closed')
                                    <th class="admin-actions-column teacher-attendance-actions-column px-5 py-4 text-center lg:px-6">{{ __('workflow.teacher_attendance.table.headers.actions') }}</th>
                                @endif
                            @endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($teacherRecords as $record)
                            @php
                                $teacher = $record->teacher;
                                $accessRoleName = $teacher?->accessRole?->name;
                                $accessRoleLabel = in_array($accessRoleName, ['super_admin', 'superadmin', 'admin', 'manager'], true)
                                    ? __('ui.roles.manager')
                                    : ($accessRoleName
                                    ? ((__('ui.roles.'.$accessRoleName) === 'ui.roles.'.$accessRoleName)
                                        ? \Illuminate\Support\Str::of($accessRoleName)->replace('_', ' ')->headline()->toString()
                                        : __('ui.roles.'.$accessRoleName))
                                    : __('workflow.common.not_available'));
                            @endphp
                            <tr wire:key="teacher-attendance-row-{{ $record->id }}-{{ $record->teacher_id }}">
                                <td class="attendance-row-number px-3 py-4 text-center text-neutral-300">{{ $loop->iteration }}</td>
                                <td class="attendance-person-column px-5 py-4 lg:px-6">
                                    <div class="student-inline student-inline--teacher-attendance">
                                        <x-teacher-avatar :teacher="$teacher" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="record-person-name student-inline__name">{{ $teacher?->first_name }} {{ $teacher?->last_name }}</div>
                                            <div class="student-inline__meta"><bdi dir="ltr" class="record-phone inline-block">{{ $teacher?->phone }}</bdi></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="attendance-desktop-only px-5 py-4 text-neutral-300 lg:px-6">{{ $accessRoleLabel }}</td>
                                <td class="attendance-desktop-only px-5 py-4 text-neutral-200 lg:px-6" data-teacher-attendance-percentage="{{ $teacherAttendancePercentages->get($record->teacher_id, 0) }}">
                                    <bdi dir="ltr">{{ number_format($teacherAttendancePercentages->get($record->teacher_id, 0)) }}%</bdi>
                                </td>
                                <td class="teacher-attendance-status-column px-5 py-4 lg:px-6">
                                    @if ($dayRecord->status === 'closed' || $record->course_finished_at)
                                        <span class="text-neutral-200">{{ $this->attendanceStatusLabel($statuses->firstWhere('id', (int) ($selected_statuses[$record->teacher_id] ?? 0)) ?? $statuses->firstWhere('is_default', true) ?? $statuses->first()) }}</span>
                                    @else
                                        <div id="teacher-attendance-record-{{ $record->teacher_id }}" class="attendance-type-switch" role="group" aria-label="{{ __('workflow.teacher_attendance.table.headers.attendance') }} — {{ $teacher?->first_name }} {{ $teacher?->last_name }}">
                                            @foreach ($attendanceChoices as $attendanceStatus)
                                                <button type="button"
                                                    wire:key="teacher-attendance-choice-{{ $record->id }}-{{ $attendanceStatus->id }}"
                                                    wire:click="chooseTeacherStatus({{ $record->teacher_id }}, {{ $attendanceStatus->id }})"
                                                    wire:loading.attr="disabled" wire:target="chooseTeacherStatus({{ $record->teacher_id }}, {{ $attendanceStatus->id }})"
                                                    @disabled(! auth()->user()->can('attendance.teacher.take'))
                                                    aria-pressed="{{ (int) ($selected_statuses[$record->teacher_id] ?? 0) === $attendanceStatus->id ? 'true' : 'false' }}"
                                                    @class(['attendance-type-switch__option', 'attendance-type-switch__option--present' => $attendanceStatus->is_present, 'attendance-type-switch__option--absent' => ! $attendanceStatus->is_present])
                                                >{{ __('workflow.teacher_attendance.table.'.($attendanceStatus->is_present ? 'present' : 'absent')) }}</button>
                                            @endforeach
                                        </div>
                                        @if (! $attendanceChoices->contains('id', (int) ($selected_statuses[$record->teacher_id] ?? 0)))
                                            <div class="mt-1 text-xs text-neutral-400">{{ $record->status?->name }}</div>
                                        @endif
                                    @endif
                                </td>
                                @can('attendance.teacher.take')
                                    @if ($dayRecord->status !== 'closed')
                                        <td class="teacher-attendance-actions-column px-5 py-4 text-center lg:px-6">
                                            @if (! $record->course_finished_at)
                                                <button type="button" wire:click="removeTeacher({{ $record->teacher_id }})" wire:confirm="{{ __('workflow.teacher_attendance.messages.confirm_remove_teacher') }}" class="admin-icon-button admin-icon-button--danger" title="{{ __('workflow.teacher_attendance.table.remove_teacher') }}" aria-label="{{ __('workflow.teacher_attendance.table.remove_teacher') }}" data-teacher-attendance-remove-action>
                                                    <x-admin-action-icon name="minus" />
                                                </button>
                                            @endif
                                        </td>
                                    @endif
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
