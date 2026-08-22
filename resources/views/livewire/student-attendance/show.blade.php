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

    public StudentAttendanceDay $currentDay;

    public string $manual_group_id = '';

    public bool $showManualGroupModal = false;

    public function mount(StudentAttendanceDay $studentAttendanceDay): void
    {
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
        $day = $this->currentDay->fresh([
            'course',
            'groupAttendanceDays' => fn ($query) => $this->dayGroupAttendanceDaysQuery($query),
        ]);
        $existingGroupIds = $day->groupAttendanceDays
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
                    ->when($day->course_id, fn ($query) => $query->where('course_id', $day->course_id))
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
        $this->redirect(route('student-attendance.index'), navigate: true);
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
        if (! $this->ensureDayIsEditable()) {
            return;
        }

        $validated = $this->validate(
            ['manual_group_id' => ['required', 'integer', 'exists:groups,id']],
            [],
            ['manual_group_id' => __('workflow.student_attendance.day_details.manual_add.group')],
        );

        $group = $this->scopeGroupsQuery(
            Group::query()
                ->with(['course', 'teacher'])
                ->where('is_active', true)
                ->whereKey((int) $validated['manual_group_id'])
        )->first();

        if (! $group) {
            $this->addError('manual_group_id', __('workflow.student_attendance.day_details.manual_add.errors.unavailable'));

            return;
        }

        $alreadyExists = $this->currentDay
            ->groupAttendanceDays()
            ->where('group_id', $group->id)
            ->exists();

        if ($alreadyExists) {
            $this->addError('manual_group_id', __('workflow.student_attendance.day_details.manual_add.errors.exists'));

            return;
        }

        if ($this->currentDay->fresh()->status === 'closed') {
            $this->addError('manual_group_id', __('workflow.student_attendance.messages.closed_day_locked'));

            return;
        }

        $day = app(StudentAttendanceDayService::class)->createOrSyncDay(
            $this->currentDay->attendance_date->format('Y-m-d'),
            collect([$group]),
            auth()->user(),
            $this->currentDay->notes,
            'open',
            $this->defaultStudentAttendanceStatusId(),
        );

        $this->currentDay = $day;
        $this->manual_group_id = '';
        $this->showManualGroupModal = false;
        $this->resetValidation('manual_group_id');

        session()->flash('status', __('workflow.student_attendance.day_details.manual_add.messages.added'));
    }

    public function openManualGroupModal(): void
    {
        $this->authorizePermission('attendance.student.take');
        if (! $this->ensureDayIsEditable()) {
            return;
        }

        $this->manual_group_id = '';
        $this->showManualGroupModal = true;
        $this->resetValidation('manual_group_id');
    }

    public function closeManualGroupModal(): void
    {
        $this->manual_group_id = '';
        $this->showManualGroupModal = false;
        $this->resetValidation('manual_group_id');
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
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="eyebrow">{{ __('ui.nav.student_attendance') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.day_details.title') }}</h1>
            </div>
            <a href="{{ route('student-attendance.index') }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.student_attendance.day_details.back') }}</a>
        </div>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_attendance.day_details.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $dayRecord->attendance_date?->format('d-m-Y') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ $dayRecord->course?->name ?: __('workflow.common.no_course') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.student_attendance.day_details.stats.groups') }}: {{ number_format($stats['groups']) }}</span>
            <span class="badge-soft">{{ __('workflow.student_attendance.day_details.stats.students') }}: {{ number_format($stats['students']) }}</span>
            <span class="badge-soft">{{ __('workflow.student_attendance.day_details.stats.marked') }}: {{ number_format($stats['marked']) }}</span>
        </div>
    </section>

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
                max-width="3xl"
            >
                <form wire:submit="addManualGroup" class="space-y-5">
                    <div>
                        <label for="manual-attendance-group" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.day_details.manual_add.group') }}</label>
                        <select id="manual-attendance-group" wire:model="manual_group_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('workflow.student_attendance.day_details.manual_add.select_group') }}</option>
                            @foreach ($availableExtraGroups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>
                            @endforeach
                        </select>
                        @error('manual_group_id')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-action-cluster admin-action-cluster--end">
                        <button type="button" wire:click="closeManualGroupModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                        <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.student_attendance.day_details.manual_add.action') }}</button>
                    </div>
                </form>
            </x-admin.modal>
        @endif
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.day_details.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($dayRecord->groupAttendanceDays->count())]) }}</div>
            </div>
            @if ($canAddManualGroup || $canQuickAttend || $canToggleDayStatus)
                <div class="admin-toolbar__actions">
                    @if ($canQuickAttend)
                        <a href="{{ route('student-attendance.quick', $dayRecord) }}" wire:navigate class="pill-link pill-link--accent">{{ __('workflow.student_attendance.day_details.controls.quick_attend') }}</a>
                    @endif
                    @if ($canAddManualGroup)
                        <button type="button" wire:click="openManualGroupModal" class="pill-link" @disabled($availableExtraGroups->isEmpty())>{{ __('workflow.student_attendance.day_details.manual_add.action') }}</button>
                    @endif
                    @if ($canToggleDayStatus)
                        <button type="button" wire:click="toggleDayStatus" class="pill-link">{{ $dayRecord->status === 'closed' ? __('workflow.student_attendance.day_details.controls.reopen_day') : __('workflow.student_attendance.day_details.controls.close_day') }}</button>
                    @endif
                    @can('attendance.student.take')
                        @if ($dayRecord->status !== 'closed')
                            <button type="button" wire:click="deleteDay" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                        @endif
                    @endcan
                </div>
            @endif
        </div>

        @if ($canAddManualGroup && $availableExtraGroups->isEmpty())
            <div class="px-5 pt-4 text-sm text-neutral-400">{{ __('workflow.student_attendance.day_details.manual_add.empty') }}</div>
        @endif

        @if ($dayRecord->groupAttendanceDays->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_attendance.day_details.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.students') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.present') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($dayRecord->groupAttendanceDays as $groupDay)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $groupDay->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $groupDay->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    {{ $groupDay->group?->teacher ? $groupDay->group->teacher->first_name.' '.$groupDay->group->teacher->last_name : __('workflow.common.no_teacher_assigned') }}
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) ($groupDay->group?->active_enrollments_count ?? 0)) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $groupDay->present_records_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex justify-end">
                                        <a href="{{ route('student-attendance.mark', $groupDay) }}" wire:navigate class="pill-link pill-link--compact">
                                            {{ __('workflow.student_attendance.day_details.table.open') }}
                                        </a>
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
