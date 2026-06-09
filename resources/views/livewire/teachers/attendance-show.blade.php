<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Support\Carbon;
use Livewire\Volt\Component;

new class extends Component {
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
        )->findOrFail($teacherAttendanceDay->id);

        $this->authorizeScopedTeacherAttendanceDayAccess($this->currentDay);
        $this->loadDay();
    }

    public function with(): array
    {
        $day = $this->currentDay->fresh([
            'records' => fn ($query) => $this->scopeTeacherAttendanceRecordsQuery(
                $query->with(['teacher.accessRole', 'status'])
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

        $scheduledTeacherIds = $this->scheduledTeacherIdsForDate($day->attendance_date?->format('Y-m-d'));

        return [
            'dayRecord' => $day,
            'teacherRecords' => $teacherRecords,
            'availableExtraTeachers' => $this->availableTeachersScopeQuery()
                ->with('accessRole')
                ->when($existingTeacherIds !== [], fn ($query) => $query->whereNotIn('id', $existingTeacherIds))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(),
            'statuses' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['teacher', 'both'])
                ->orderBy('name')
                ->get(),
            'stats' => [
                'teachers' => $teacherRecords->count(),
                'scheduled' => count(array_intersect($existingTeacherIds, $scheduledTeacherIds)),
                'marked' => $teacherRecords->filter(fn (TeacherAttendanceRecord $record) => filled($record->attendance_status_id))->count(),
            ],
        ];
    }

    public function saveAttendance(): void
    {
        $this->authorizePermission('attendance.teacher.take');

        $validated = $this->validate([
            'day_status' => ['required', 'in:open,closed'],
            'notes' => ['nullable', 'string'],
            'selected_statuses' => ['array'],
            'selected_statuses.*' => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        $allowedTeacherIds = $this->currentDay->records()
            ->pluck('teacher_id')
            ->map(fn ($teacherId) => (int) $teacherId)
            ->all();

        $selectedTeacherIds = collect(array_keys(array_filter($validated['selected_statuses'])))
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

        foreach (array_filter($validated['selected_statuses']) as $teacherId => $statusId) {
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

    public function saveTeacherStatus(int $teacherId): void
    {
        $this->authorizePermission('attendance.teacher.take');

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

        app(TeacherAttendanceDayService::class)->createOrSyncDay(
            $this->currentDay->attendance_date->format('Y-m-d'),
            collect([$teacher]),
            auth()->user(),
            $this->notes,
            $this->day_status,
            $this->defaultTeacherAttendanceStatusId(),
        );

        $this->loadDay();
        $this->closeManualTeacherModal();

        session()->flash('status', __('workflow.teacher_attendance.day_details.manual_add.messages.added'));
    }

    public function deleteDay(): void
    {
        $this->authorizePermission('attendance.teacher.take');

        $this->currentDay->records()->delete();
        $this->currentDay->delete();

        session()->flash('status', __('workflow.teacher_attendance.messages.deleted'));

        $this->redirect(route('teachers.attendance'), navigate: true);
    }

    protected function loadDay(): void
    {
        $this->currentDay = $this->scopeTeacherAttendanceDaysQuery(
            TeacherAttendanceDay::query()->with('records')
        )->findOrFail($this->currentDay->id);

        $this->authorizeScopedTeacherAttendanceDayAccess($this->currentDay);

        $this->day_status = $this->currentDay->status ?? 'open';
        $this->notes = $this->currentDay->notes ?? '';
        $this->manual_teacher_id = '';
        $this->selected_statuses = $this->currentDay->records
            ->mapWithKeys(fn (TeacherAttendanceRecord $record) => [$record->teacher_id => $record->attendance_status_id])
            ->toArray();
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
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.teacher_attendance.day_details.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.teacher_attendance.day_details.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $dayRecord->attendance_date?->format('Y-m-d') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.teacher_attendance.day_details.stats.scheduled') }}: {{ number_format($stats['scheduled']) }}</span>
            <span class="badge-soft">{{ __('workflow.teacher_attendance.day_details.stats.teachers') }}: {{ number_format($stats['teachers']) }}</span>
            <span class="badge-soft">{{ __('workflow.teacher_attendance.day_details.stats.marked') }}: {{ number_format($stats['marked']) }}</span>
        </div>
    </section>

    <div>
        <a href="{{ route('teachers.attendance') }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.teacher_attendance.day_details.back') }}</a>
    </div>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @can('attendance.teacher.take')
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('workflow.teacher_attendance.day_details.manual_add.title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('workflow.teacher_attendance.day_details.manual_add.help') }}</p>
                </div>

                <div class="admin-toolbar__actions">
                    <button type="button" wire:click="openManualTeacherModal" class="pill-link pill-link--accent" @disabled($availableExtraTeachers->isEmpty())>
                        {{ __('workflow.teacher_attendance.day_details.manual_add.action') }}
                    </button>
                </div>
            </div>

            @if ($availableExtraTeachers->isEmpty())
                <div class="mt-4 text-sm text-neutral-400">{{ __('workflow.teacher_attendance.day_details.manual_add.empty') }}</div>
            @endif
        </section>

        <x-admin.modal
            :show="$showManualTeacherModal"
            :title="__('workflow.teacher_attendance.day_details.manual_add.title')"
            :description="__('workflow.teacher_attendance.day_details.manual_add.help')"
            close-method="closeManualTeacherModal"
            max-width="3xl"
        >
            <form wire:submit="addManualTeacher" class="space-y-5">
                <div>
                    <label for="manual-attendance-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.day_details.manual_add.teacher') }}</label>
                    <select id="manual-attendance-teacher" wire:model="manual_teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
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
                    <button type="button" wire:click="closeManualTeacherModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                    <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.teacher_attendance.day_details.manual_add.action') }}</button>
                </div>
            </form>
        </x-admin.modal>
    @endcan

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.teacher_attendance.form.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.teacher_attendance.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="teacher-attendance-status" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.day_status') }}</label>
                    <select id="teacher-attendance-status" wire:model.live="day_status" wire:change="saveDaySummary" data-searchable="false">
                        <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                        <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="teacher-attendance-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.notes') }}</label>
                    <input id="teacher-attendance-notes" wire:model.live.debounce.800ms="notes" wire:change="saveDaySummary" type="text">
                </div>

                <div class="admin-toolbar__actions">
                    @can('attendance.teacher.take')
                        <button wire:click="saveAttendance" type="button" class="pill-link pill-link--accent">
                            {{ __('workflow.common.actions.save_teacher_attendance') }}
                        </button>
                        <button wire:click="deleteDay" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" type="button" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                            {{ __('crud.common.actions.delete') }}
                        </button>
                    @endcan
                </div>
            </div>
        </div>
    </section>

    @can('attendance.teacher.take')
        @error('selected_statuses')
            <div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
        @enderror
    @endcan

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.teacher_attendance.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('workflow.teacher_attendance.table.summary', ['count' => number_format($teacherRecords->count())]) }}</div>
            </div>
        </div>

        @if ($teacherRecords->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.teacher_attendance.table.empty') }}</div>
        @else
            <div class="overflow-x-auto overflow-y-visible pb-24">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.access_role') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.attendance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($teacherRecords as $record)
                            @php
                                $teacher = $record->teacher;
                                $accessRoleName = $teacher?->accessRole?->name;
                                $accessRoleLabel = $accessRoleName
                                    ? ((__('ui.roles.'.$accessRoleName) === 'ui.roles.'.$accessRoleName)
                                        ? \Illuminate\Support\Str::of($accessRoleName)->replace('_', ' ')->headline()->toString()
                                        : __('ui.roles.'.$accessRoleName))
                                    : __('workflow.common.not_available');
                            @endphp
                            <tr wire:key="teacher-attendance-row-{{ $record->id }}-{{ $record->teacher_id }}">
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="student-inline student-inline--teacher-attendance">
                                        <x-teacher-avatar :teacher="$teacher" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $teacher?->first_name }} {{ $teacher?->last_name }}</div>
                                            <div class="student-inline__meta">{{ $teacher?->phone }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $accessRoleLabel }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $teacher?->status === 'active' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('crud.common.status_options.'.($teacher?->status ?: 'inactive')) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <select
                                        id="teacher-attendance-record-{{ $record->teacher_id }}"
                                        wire:key="teacher-attendance-select-{{ $record->id }}-{{ $record->teacher_id }}"
                                        wire:model="selected_statuses.{{ $record->teacher_id }}"
                                        wire:change="saveTeacherStatus({{ $record->teacher_id }})"
                                        class="w-full rounded-xl px-4 py-3 text-sm"
                                        data-searchable="false"
                                    >
                                        <option value="">{{ __('workflow.teacher_attendance.table.not_marked') }}</option>
                                        @foreach ($statuses as $attendanceStatus)
                                            <option value="{{ $attendanceStatus->id }}">{{ $attendanceStatus->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
