<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\GroupAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Services\PointLedgerService;
use App\Services\StudentAttendanceDayService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public GroupAttendanceDay $currentGroupDay;
    public string $day_status = 'open';
    public string $notes = '';
    public array $selected_statuses = [];

    public function mount(GroupAttendanceDay $groupAttendanceDay): void
    {
        $this->authorizePermission('attendance.student.view');

        $this->currentGroupDay = GroupAttendanceDay::query()
            ->with(['studentAttendanceDay', 'group.course', 'group.academicYear', 'group.teacher', 'records'])
            ->findOrFail($groupAttendanceDay->id);

        $this->authorizeScopedGroupAttendanceDayAccess($this->currentGroupDay);

        $this->currentGroupDay = $this->ensureParentAttendanceDay($this->currentGroupDay);

        $this->loadDay();
    }

    public function with(): array
    {
        $groupDay = $this->currentGroupDay->fresh(['studentAttendanceDay', 'group.course', 'group.academicYear', 'group.teacher']);
        $enrollments = Enrollment::query()
            ->with('student')
            ->where('group_id', $groupDay->group_id)
            ->where('status', 'active')
            ->orderBy('enrolled_at')
            ->get();

        return [
            'groupDayRecord' => $groupDay,
            'enrollments' => $enrollments,
            'statuses' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderBy('name')
                ->get(),
            'markedCount' => collect($this->selected_statuses)->filter()->count(),
            'activeEnrollmentCount' => $enrollments->count(),
        ];
    }

    public function saveAttendance(): void
    {
        $this->authorizePermission('attendance.student.take');

        $validated = $this->validate([
            'day_status' => ['required', 'in:open,closed'],
            'notes' => ['nullable', 'string'],
            'selected_statuses' => ['array'],
            'selected_statuses.*' => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        $this->currentGroupDay->update([
            'status' => $validated['day_status'],
            'notes' => $validated['notes'] ?: null,
        ]);

        $ledger = app(PointLedgerService::class);

        $enrollments = Enrollment::query()
            ->with('student')
            ->where('group_id', $this->currentGroupDay->group_id)
            ->where('status', 'active')
            ->get();

        foreach ($enrollments as $enrollment) {
            $statusId = $validated['selected_statuses'][$enrollment->id] ?? null;

            if (! $statusId) {
                continue;
            }

            $record = StudentAttendanceRecord::query()->updateOrCreate(
                [
                    'group_attendance_day_id' => $this->currentGroupDay->id,
                    'enrollment_id' => $enrollment->id,
                ],
                [
                    'attendance_status_id' => $statusId,
                ],
            );

            $ledger->voidSourceTransactions('student_attendance_record', $record->id, __('workflow.student_attendance.messages.void_reason'));
            $status = AttendanceStatus::query()->find($statusId);

            if ($status) {
                $ledger->recordAttendanceStatusPoints(
                    $enrollment,
                    'student_attendance_record',
                    $record->id,
                    $status,
                    __('workflow.student_attendance.messages.automatic_points', ['status' => $status->name]),
                );
            }

            $ledger->syncEnrollmentCaches($enrollment->fresh());
        }

        $this->currentGroupDay = $this->currentGroupDay->fresh(['studentAttendanceDay', 'records']);

        if ($this->currentGroupDay->studentAttendanceDay) {
            app(StudentAttendanceDayService::class)->syncAggregateStatus($this->currentGroupDay->studentAttendanceDay);
        }

        session()->flash('status', __('workflow.student_attendance.messages.saved'));
    }

    protected function loadDay(): void
    {
        $this->day_status = $this->currentGroupDay->status ?? 'open';
        $this->notes = $this->currentGroupDay->notes ?? '';
        $this->selected_statuses = $this->currentGroupDay->records
            ->mapWithKeys(fn (StudentAttendanceRecord $record) => [$record->enrollment_id => $record->attendance_status_id])
            ->toArray();
    }

    protected function ensureParentAttendanceDay(GroupAttendanceDay $groupAttendanceDay): GroupAttendanceDay
    {
        if ($groupAttendanceDay->student_attendance_day_id) {
            return $groupAttendanceDay;
        }

        $parentDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
            $groupAttendanceDay->attendance_date->format('Y-m-d'),
            collect([$groupAttendanceDay->group]),
            auth()->user(),
        );

        return GroupAttendanceDay::query()
            ->with(['studentAttendanceDay', 'group.course', 'group.academicYear', 'group.teacher', 'records'])
            ->where('student_attendance_day_id', $parentDay->id)
            ->where('group_id', $groupAttendanceDay->group_id)
            ->firstOrFail();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.student_attendance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.marking.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_attendance.marking.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $groupDayRecord->studentAttendanceDay?->attendance_date?->format('Y-m-d') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ $groupDayRecord->group?->name ?: __('workflow.common.no_group') }}</span>
            <span class="badge-soft">{{ $groupDayRecord->group?->course?->name ?: __('workflow.common.no_course') }}</span>
        </div>
    </section>

    <div>
        <a href="{{ route('student-attendance.show', $groupDayRecord->studentAttendanceDay) }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.student_attendance.marking.back') }}</a>
    </div>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_attendance.stats.active_enrollments') }}</div>
            <div class="metric-value mt-6">{{ number_format($activeEnrollmentCount) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_attendance.stats.marked_today') }}</div>
            <div class="metric-value mt-6">{{ number_format($markedCount) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_attendance.form.day_status') }}</div>
            <div class="metric-value mt-6">{{ __('workflow.common.day_status.'.$day_status) }}</div>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_24rem]">
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar__title">{{ __('workflow.student_attendance.form.title') }}</div>
            <p class="admin-toolbar__subtitle">{{ __('workflow.student_attendance.form.help') }}</p>

            <div class="mt-6 grid gap-4 lg:grid-cols-[10rem_minmax(0,1fr)]">
                <div>
                    <label for="group-attendance-status" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.form.day_status') }}</label>
                    <select id="group-attendance-status" wire:model="day_status" data-searchable="false" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                        <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                    </select>
                </div>

                <div>
                    <label for="group-attendance-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.form.notes') }}</label>
                    <input id="group-attendance-notes" wire:model="notes" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                </div>
            </div>

            @can('attendance.student.take')
                <div class="mt-5 flex justify-end">
                    <button wire:click="saveAttendance" type="button" class="pill-link pill-link--accent">
                        {{ __('workflow.common.actions.save_student_attendance') }}
                    </button>
                </div>
            @endcan
        </section>

        <aside class="space-y-6">
            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('workflow.student_attendance.context.title') }}</div>
                <div class="mt-4 space-y-3 text-sm text-neutral-300">
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.student_attendance.context.teacher') }}</div>
                        <div class="mt-1 text-white">
                            {{ $groupDayRecord->group?->teacher ? $groupDayRecord->group->teacher->first_name.' '.$groupDayRecord->group->teacher->last_name : __('workflow.common.no_teacher_assigned') }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.student_attendance.context.course') }}</div>
                        <div class="mt-1 text-white">{{ $groupDayRecord->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.student_attendance.context.academic_year') }}</div>
                        <div class="mt-1 text-white">{{ $groupDayRecord->group?->academicYear?->name ?: __('workflow.common.no_academic_year') }}</div>
                    </div>
                </div>
            </section>
        </aside>
    </div>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($activeEnrollmentCount)]) }}</div>
            </div>
        </div>

        @if ($enrollments->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_attendance.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.table.headers.enrolled') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.table.headers.current_points') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.table.headers.attendance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($enrollments as $enrollment)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="student-inline">
                                        <x-student-avatar :student="$enrollment->student" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->enrolled_at?->format('Y-m-d') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $enrollment->final_points_cached }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <select
                                        wire:model="selected_statuses.{{ $enrollment->id }}"
                                        @disabled(! auth()->user()->can('attendance.student.take'))
                                        data-searchable="false"
                                        class="w-full rounded-xl px-4 py-3 text-sm"
                                    >
                                        <option value="">{{ __('workflow.student_attendance.table.not_marked') }}</option>
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status->id }}">{{ $status->name }}</option>
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
