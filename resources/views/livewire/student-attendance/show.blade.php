<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\StudentAttendanceDay;
use App\Services\PointLedgerService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public bool $unified = false;

    public StudentAttendanceDay $currentDay;

    public array $manual_group_ids = [];

    public bool $showManualGroupModal = false;

    public function mount(StudentAttendanceDay $studentAttendanceDay, bool $unified = false): void
    {
        $this->unified = $unified;
        $this->authorizePermission('attendance.student.view');

        $this->currentDay = StudentAttendanceDay::query()
            ->with([
                'course',
                'groupAttendanceDays' => fn ($query) => $this->dayGroupAttendanceDaysQuery($query),
            ])
            ->findOrFail($studentAttendanceDay->id);

        $this->authorizeScopedStudentAttendanceDayAccess($this->currentDay);
    }

    public function with(): array
    {
        $isArchived = (bool) $this->currentDay->fresh()->course_finished_at;
        $day = $this->currentDay->fresh([
            'course',
            'groupAttendanceDays' => fn ($query) => $isArchived
                ? $query->whereRaw('1 = 0')
                : $this->dayGroupAttendanceDaysQuery($query),
        ]);
        $existingGroupIds = $day->groupAttendanceDays()
            ->pluck('group_id')
            ->filter()
            ->values()
            ->all();

        return [
            'dayRecord' => $day,
            'canAddManualGroup' => $this->canPermission('attendance.student.take') && $day->status !== 'closed' && ! $day->course_finished_at,
            'canQuickAttend' => $this->canPermission('attendance.student.take') && $day->status !== 'closed' && ! $day->course_finished_at,
            'canToggleDayStatus' => $this->canPermission('attendance.student.toggle-day-status') && ! $day->course_finished_at,
            'availableExtraGroups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with(['course', 'teacher'])
                    ->where('is_active', true)
                    ->where('course_id', $day->course_id)
                    ->when($existingGroupIds !== [], fn ($query) => $query->whereNotIn('id', $existingGroupIds))
                    ->orderBy('name')
            )->get(),
            'stats' => [
                'groups' => $day->groupAttendanceDays->count(),
                'students' => $day->groupAttendanceDays->sum(fn ($groupDay) => (int) ($groupDay->group?->active_enrollments_count ?? 0)),
                'marked' => $day->groupAttendanceDays->sum('records_count'),
            ],
        ];
    }

    public function toggleDayStatus(): void
    {
        $this->authorizePermission('attendance.student.toggle-day-status');
        if (! $this->ensureDayIsEditable()) {
            return;
        }

        $this->closeManualGroupModal();

        $status = $this->currentDay->fresh()->status === 'closed' ? 'open' : 'closed';
        $this->currentDay = app(StudentAttendanceDayService::class)->setDayStatus($this->currentDay, $status);

        session()->flash(
            'status',
            $status === 'closed'
                ? __('workflow.student_attendance.day_details.messages.closed')
                : __('workflow.student_attendance.day_details.messages.reopened')
        );
    }

    public function deleteDay(): void
    {
        $this->authorizePermission('attendance.student.take');
        if (! $this->ensureDayIsEditable()) {
            return;
        }

        $day = $this->currentDay->fresh(['groupAttendanceDays.records']);
        $enrollmentIds = collect();

        DB::transaction(function () use ($day, &$enrollmentIds): void {
            foreach ($day->groupAttendanceDays as $groupDay) {
                foreach ($groupDay->records as $record) {
                    $enrollmentIds->push($record->enrollment_id);
                    app(PointLedgerService::class)->voidSourceTransactions(
                        'student_attendance_record',
                        $record->id,
                        __('workflow.student_attendance.messages.deleted_void_reason'),
                    );
                }
                $groupDay->records()->delete();
                $groupDay->delete();
            }
            $day->delete();
        });

        Enrollment::query()->with('student')->whereKey($enrollmentIds->filter()->unique()->values())->get()
            ->each(fn (Enrollment $enrollment) => app(PointLedgerService::class)->syncEnrollmentCaches($enrollment));

        session()->flash('status', __('workflow.student_attendance.days.messages.deleted'));
        $this->redirect(route('attendance.index'), navigate: true);
    }

    protected function dayGroupAttendanceDaysQuery($query)
    {
        return $this->scopeGroupAttendanceDaysQuery(
            $query->withCount([
                'records',
                'records as present_records_count' => fn ($recordQuery) => $recordQuery
                    ->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_present', true)),
            ])->with([
                'group' => fn ($groupQuery) => $groupQuery
                    ->with(['course', 'teacher'])
                    ->withCount([
                        'enrollments as active_enrollments_count' => fn ($enrollmentQuery) => $enrollmentQuery->where('status', 'active'),
                    ]),
            ])
        )->orderBy(
            Group::query()
                ->select('name')
                ->whereColumn('groups.id', 'group_attendance_days.group_id')
                ->limit(1)
        );
    }

    public function addManualGroup(): void
    {
        $this->authorizePermission('attendance.student.take');
        if (! $this->ensureDayIsOpen()) {
            return;
        }

        $validated = $this->validate(
            [
                'manual_group_ids' => ['required', 'array', 'min:1'],
                'manual_group_ids.*' => ['required', 'integer', 'distinct', 'exists:groups,id'],
            ],
            [],
            [
                'manual_group_ids' => __('workflow.student_attendance.day_details.manual_add.group'),
                'manual_group_ids.*' => __('workflow.student_attendance.day_details.manual_add.group'),
            ],
        );

        $added = DB::transaction(function () use ($validated): bool {
            $this->currentDay = StudentAttendanceDay::query()->lockForUpdate()->findOrFail($this->currentDay->id);
            if (! $this->ensureDayIsOpen()) {
                return false;
            }

            $groups = $this->scopeGroupsQuery(
                Group::query()
                    ->with(['course', 'teacher'])
                    ->where('is_active', true)
                    ->where('course_id', $this->currentDay->course_id)
                    ->whereKey($validated['manual_group_ids'])
            )->get();

            if ($groups->count() !== count($validated['manual_group_ids'])) {
                $this->addError('manual_group_ids', __('workflow.student_attendance.day_details.manual_add.errors.unavailable'));

                return false;
            }

            if ($this->currentDay->groupAttendanceDays()->whereIn('group_id', $groups->modelKeys())->exists()) {
                $this->addError('manual_group_ids', __('workflow.student_attendance.day_details.manual_add.errors.exists'));

                return false;
            }

            $this->currentDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
                $this->currentDay->attendance_date->format('Y-m-d'),
                $groups,
                auth()->user(),
                $this->currentDay->notes,
                'open',
                $this->defaultStudentAttendanceStatusId(),
                $this->currentDay->course_id,
            );

            return true;
        });

        if (! $added) {
            return;
        }

        $this->closeManualGroupModal();
        session()->flash('status', __('workflow.student_attendance.day_details.manual_add.messages.added'));
    }

    public function removeGroup(int $groupDayId): void
    {
        $this->authorizePermission('attendance.student.take');

        $removed = DB::transaction(function () use ($groupDayId): bool {
            $this->currentDay = StudentAttendanceDay::query()->lockForUpdate()->findOrFail($this->currentDay->id);
            if (! $this->ensureDayIsOpen()) {
                return false;
            }

            $groupDay = $this->scopeGroupAttendanceDaysQuery($this->currentDay->groupAttendanceDays())
                ->with('records')->findOrFail($groupDayId);
            $enrollmentIds = $groupDay->records->pluck('enrollment_id')->filter()->unique();
            $ledger = app(PointLedgerService::class);

            foreach ($groupDay->records as $record) {
                $ledger->voidSourceTransactions(
                    'student_attendance_record',
                    $record->id,
                    __('workflow.student_attendance.messages.deleted_void_reason'),
                );
            }

            $groupDay->records()->delete();
            $groupDay->delete();
            Enrollment::query()->with('student')->whereKey($enrollmentIds)->get()
                ->each(fn (Enrollment $enrollment) => $ledger->syncEnrollmentCaches($enrollment));

            return true;
        });

        if ($removed) {
            session()->flash('status', __('workflow.student_attendance.day_details.remove_group.removed'));
        }
    }

    public function openManualGroupModal(): void
    {
        $this->authorizePermission('attendance.student.take');
        if (! $this->ensureDayIsOpen()) {
            return;
        }

        $this->manual_group_ids = [];
        $this->showManualGroupModal = true;
        $this->resetValidation();
    }

    public function closeManualGroupModal(): void
    {
        $this->manual_group_ids = [];
        $this->showManualGroupModal = false;
        $this->resetValidation();
    }

    protected function ensureDayIsOpen(): bool
    {
        if (! $this->ensureDayIsEditable()) {
            return false;
        }

        if ($this->currentDay->fresh()->status === 'closed') {
            $this->addError('day', __('workflow.student_attendance.messages.closed_day_locked'));

            return false;
        }

        return true;
    }

    protected function defaultStudentAttendanceStatusId(): ?int
    {
        return AttendanceStatus::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->value('id') ?? AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->orderByDesc('is_present')
            ->orderBy('name')
            ->value('id');
    }

    protected function ensureDayIsEditable(): bool
    {
        if (! $this->currentDay->fresh()->course_finished_at) {
            return true;
        }

        $this->addError('day', __('workflow.student_attendance.messages.archived_day_locked'));

        return false;
    }
}; ?>

<div class="page-stack">
    <div class="attendance-day-header">
        <section class="page-hero attendance-day-hero p-6 lg:p-8">
            <div class="attendance-day-hero-layout flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
                <div>
                    <x-back-link :href="route('attendance.index')" navigate />
                    <div class="eyebrow mt-4">{{ __('ui.nav.student_attendance') }}</div>
                    <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.day_details.title') }}</h1>
                </div>
                <div class="attendance-day-metrics"><div class="shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner" data-student-attendance-day-date-metric>
                    <div class="text-xs text-neutral-300">{{ __('workflow.student_attendance.form.attendance_date') }}</div>
                    <bdi dir="ltr" class="mt-1 block text-lg font-semibold text-emerald-100">{{ \App\Support\DateDisplay::html($dayRecord->attendance_date?->format('d-m-Y') ?: __('workflow.common.not_available')) }}</bdi>
                </div>
                <div class="attendance-day-present-metric shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner" data-attendance-present-count>
                    <div class="text-xs text-neutral-300">{{ __('attendance.present_students') }}</div>
                    <span class="mt-1 block text-lg font-semibold text-emerald-100">{{ number_format($dayRecord->groupAttendanceDays->sum('present_records_count')) }}</span>
                </div></div>
            </div>
        </section>

        <div class="attendance-day-navigation-row">
            @if ($unified)
                <x-attendance-type-switch selected="students" action="$parent.switchTab" />
            @endif
            <div class="attendance-day-navigation-actions">
                        @if ($canToggleDayStatus)
                            @php
                                $dayStatusActionLabel = $dayRecord->status === 'closed'
                                    ? __('workflow.student_attendance.day_details.controls.reopen_day')
                                    : __('workflow.student_attendance.day_details.controls.close_day');
                                $dayStatusAction = $unified && auth()->user()->can('attendance.teacher.take')
                                    ? '$parent.toggleDayStatus'
                                    : 'toggleDayStatus';
                            @endphp
                            <button type="button" wire:click="{{ $dayStatusAction }}" wire:key="student-attendance-day-status-action-{{ $dayRecord->id }}" class="admin-icon-button" title="{{ $dayStatusActionLabel }}" aria-label="{{ $dayStatusActionLabel }}" data-student-attendance-day-status-action>
                                @if ($dayRecord->status === 'closed')
                                    <x-admin-action-icon name="unlock" />
                                @else
                                    <x-admin-action-icon name="lock" />
                                @endif
                            </button>
                        @endif
                        @can('attendance.student.take')
                            @if ($dayRecord->status !== 'closed')
                                <button type="button" wire:click="{{ $unified && auth()->user()->can('attendance.teacher.take') ? '$parent.deleteDay' : 'deleteDay' }}" wire:key="student-attendance-day-delete-action-{{ $dayRecord->id }}" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="admin-icon-button admin-icon-button--danger" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-student-attendance-day-delete-action>
                                    <x-admin-action-icon name="delete" />
                                </button>
                            @endif
                        @endcan
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @error('day')
        <div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>
    @enderror

    @if ($canAddManualGroup || $canQuickAttend || $canToggleDayStatus)
        @if ($canAddManualGroup)
            <x-admin.modal
                :show="$showManualGroupModal"
                :title="__('workflow.student_attendance.day_details.manual_add.title')"
                :description="__('workflow.student_attendance.day_details.manual_add.help')"
                close-method="closeManualGroupModal"
                max-width="xl"
                compact
            >
                <form wire:submit="addManualGroup" class="space-y-4">
                    <fieldset>
                        <legend class="mb-3 text-sm font-medium">{{ __('workflow.student_attendance.day_details.manual_add.group') }}</legend>
                        <div class="attendance-group-picker" data-attendance-group-picker>
                            @foreach ($availableExtraGroups as $group)
                                <label class="attendance-group-picker__option" wire:key="attendance-extra-group-{{ $group->id }}">
                                    <input type="checkbox" wire:model="manual_group_ids" value="{{ $group->id }}">
                                    <span class="min-w-0">
                                        <span class="block font-semibold">{{ $group->name }}</span>
                                        <span class="record-person-name mt-1 block text-xs text-neutral-400">{{ $group->teacher ? $group->teacher->first_name.' '.$group->teacher->last_name : __('workflow.common.no_teacher_assigned') }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('manual_group_ids')
                            <div class="mt-2 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                        @error('manual_group_ids.*')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </fieldset>

                    <div class="admin-action-cluster admin-action-cluster--end">
                        <button type="button" wire:click="closeManualGroupModal" class="pill-link pill-link--compact">{{ __('crud.common.actions.cancel') }}</button>
                        <button type="submit" class="pill-link pill-link--accent pill-link--compact" wire:loading.attr="disabled" wire:target="addManualGroup" @disabled($availableExtraGroups->isEmpty())>{{ __('workflow.student_attendance.day_details.manual_add.action') }}</button>
                    </div>
                </form>
            </x-admin.modal>
        @endif
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls attendance-day-toolbar student-attendance-toolbar">
            <div class="attendance-day-toolbar__heading">
                <div class="admin-grid-meta__title" title="{{ __('workflow.student_attendance.day_details.table.title') }}">{{ __('workflow.student_attendance.day_details.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ trans_choice('workflow.student_attendance.day_details.table.groups_in_view', $dayRecord->groupAttendanceDays->count(), ['count' => number_format($dayRecord->groupAttendanceDays->count())]) }}</div>
            </div>
            @if ($canAddManualGroup || $canQuickAttend || $canToggleDayStatus)
                <div class="admin-toolbar__actions">
                    @if ($canQuickAttend)
                        <a href="{{ route('student-attendance.quick', $dayRecord) }}" wire:navigate wire:key="student-attendance-quick-action-{{ $dayRecord->id }}" class="admin-icon-button admin-icon-button--accent attendance-quick-action" title="{{ __('workflow.student_attendance.day_details.controls.quick_attend') }}" aria-label="{{ __('workflow.student_attendance.day_details.controls.quick_attend') }}" data-student-quick-attendance-action>
                            <x-quick-attendance-icon />
                        </a>
                    @endif
                    @if ($canAddManualGroup && $availableExtraGroups->isNotEmpty())
                        <x-add-action-button wire:click="openManualGroupModal" wire:key="student-attendance-add-group-action-{{ $dayRecord->id }}" :label="__('workflow.student_attendance.day_details.manual_add.action')" :accent="false" data-student-attendance-add-groups-action />
                    @endif

                </div>
            @endif
        </div>

        @if ($dayRecord->groupAttendanceDays->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_attendance.day_details.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="attendance-day-groups-table text-sm">
                    <thead>
                        <tr>
                            <th data-table-number-column scope="col" class="attendance-row-number px-3 py-4 text-center">#</th>
                            <th class="attendance-person-column px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.group') }}</th>
                            <th class="attendance-desktop-only px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.teacher') }}</th>
                            <th class="attendance-group-count px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.students') }}</th>
                            <th class="attendance-group-count px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.present') }}</th>
                            <th class="admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($dayRecord->groupAttendanceDays as $groupDay)
                            @php
                                $teacherName = $groupDay->group?->teacher ? $groupDay->group->teacher->first_name.' '.$groupDay->group->teacher->last_name : __('workflow.common.no_teacher_assigned');
                            @endphp
                            <tr wire:key="attendance-day-group-{{ $groupDay->id }}">
                                <td class="attendance-row-number px-3 py-4 text-center text-neutral-300">{{ $loop->iteration }}</td>
                                <td class="attendance-person-column px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $groupDay->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 text-xs font-normal leading-relaxed text-neutral-400 md:hidden">{{ $teacherName }}</div>
                                </td>
                                <td class="attendance-desktop-only record-person-name px-5 py-4 text-neutral-300 lg:px-6">
                                    {{ $teacherName }}
                                </td>
                                <td class="attendance-group-count px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) ($groupDay->group?->active_enrollments_count ?? 0)) }}</td>
                                <td class="attendance-group-count px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $groupDay->present_records_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex justify-end gap-2">
                                        <x-open-action-button :href="route('student-attendance.mark', $groupDay)" wire:navigate :label="__('workflow.student_attendance.day_details.table.open')" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
