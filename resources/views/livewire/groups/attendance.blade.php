<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Services\PointLedgerService;
use App\Services\StudentAttendanceDayService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Group $currentGroup;
    public ?int $attendanceDayId = null;
    public string $attendance_date = '';
    public string $day_status = 'open';
    public string $notes = '';
    public array $selected_statuses = [];

    public function mount(Group $group): void
    {
        $this->authorizePermission('attendance.student.view');

        $this->currentGroup = Group::query()
            ->with(['course', 'academicYear', 'teacher'])
            ->findOrFail($group->id);

        $this->authorizeTeacherGroupAccess($this->currentGroup);

        $this->attendance_date = now()->toDateString();
        $this->loadDay();
    }

    public function with(): array
    {
        $enrollments = Enrollment::query()
            ->with('student')
            ->where('group_id', $this->currentGroup->id)
            ->where('status', 'active')
            ->orderBy('enrolled_at')
            ->get();

        return [
            'groupRecord' => $this->currentGroup->fresh(['course', 'academicYear', 'teacher']),
            'enrollments' => $enrollments,
            'statuses' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderBy('name')
                ->get(),
            'recentDays' => GroupAttendanceDay::query()
                ->where('group_id', $this->currentGroup->id)
                ->withCount('records')
                ->latest('attendance_date')
                ->latest('id')
                ->limit(8)
                ->get(),
            'activeEnrollmentCount' => $enrollments->count(),
            'markedCount' => collect($this->selected_statuses)->filter()->count(),
            'attendanceDayCount' => GroupAttendanceDay::query()
                ->where('group_id', $this->currentGroup->id)
                ->count(),
        ];
    }

    public function updatedAttendanceDate(): void
    {
        $this->loadDay();
    }

    public function selectDay(string $date): void
    {
        $this->attendance_date = $date;
        $this->loadDay();
    }

    public function saveAttendance(): void
    {
        $this->authorizePermission('attendance.student.take');

        $validated = $this->validate([
            'attendance_date' => ['required', 'date'],
            'day_status' => ['required', 'in:open,closed'],
            'notes' => ['nullable', 'string'],
            'selected_statuses' => ['array'],
            'selected_statuses.*' => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        $day = GroupAttendanceDay::query()
            ->with('studentAttendanceDay')
            ->where('group_id', $this->currentGroup->id)
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->first();

        if (! $day || ! $day->student_attendance_day_id) {
            $parentDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
                $validated['attendance_date'],
                collect([$this->currentGroup]),
                auth()->user(),
            );

            $day = $parentDay->groupAttendanceDays()
                ->where('group_id', $this->currentGroup->id)
                ->firstOrFail();
        }

        $day->update([
            'status' => $validated['day_status'],
            'notes' => $validated['notes'] ?: null,
        ]);

        $ledger = app(PointLedgerService::class);

        $enrollments = Enrollment::query()
            ->with('student')
            ->where('group_id', $this->currentGroup->id)
            ->where('status', 'active')
            ->get();

        foreach ($enrollments as $enrollment) {
            $statusId = $validated['selected_statuses'][$enrollment->id] ?? null;

            if (! $statusId) {
                continue;
            }

            $record = StudentAttendanceRecord::query()->updateOrCreate(
                [
                    'group_attendance_day_id' => $day->id,
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

        if ($day->studentAttendanceDay) {
            app(StudentAttendanceDayService::class)->syncAggregateStatus($day->studentAttendanceDay);
        }

        $this->attendanceDayId = $day->id;

        session()->flash('status', __('workflow.student_attendance.messages.saved'));
    }

    protected function loadDay(): void
    {
        $day = GroupAttendanceDay::query()
            ->with('records')
            ->where('group_id', $this->currentGroup->id)
            ->whereDate('attendance_date', $this->attendance_date)
            ->first();

        $this->attendanceDayId = $day?->id;
        $this->day_status = $day?->status ?? 'open';
        $this->notes = $day?->notes ?? '';
        $this->selected_statuses = $day
            ? $day->records->mapWithKeys(fn (StudentAttendanceRecord $record) => [$record->enrollment_id => $record->attendance_status_id])->toArray()
            : [];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('workflow.common.back_to_groups') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_attendance.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $groupRecord->name }}</span>
            <span class="badge-soft badge-soft--emerald">{{ $groupRecord->course?->name ?: __('workflow.common.no_course') }}</span>
            <span class="badge-soft">{{ $groupRecord->academicYear?->name ?: __('workflow.common.no_academic_year') }}</span>
        </div>
    </section>

    <div>
        <a href="{{ route('groups.index') }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.common.back_to_groups') }}</a>
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
            <div class="kpi-label">{{ __('workflow.student_attendance.stats.attendance_days') }}</div>
            <div class="metric-value mt-6">{{ number_format($attendanceDayCount) }}</div>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_24rem]">
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar__title">{{ __('workflow.student_attendance.form.title') }}</div>
            <p class="admin-toolbar__subtitle">{{ __('workflow.student_attendance.form.help') }}</p>

            <div class="mt-6 grid gap-4 lg:grid-cols-[14rem_10rem_minmax(0,1fr)]">
                <div>
                    <label for="group-attendance-date" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.form.attendance_date') }}</label>
                    <input id="group-attendance-date" wire:model.live="attendance_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('attendance_date')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

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
                            {{ $groupRecord->teacher ? $groupRecord->teacher->first_name.' '.$groupRecord->teacher->last_name : __('workflow.common.no_teacher_assigned') }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.student_attendance.context.course') }}</div>
                        <div class="mt-1 text-white">{{ $groupRecord->course?->name ?: __('workflow.common.no_course') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.student_attendance.context.academic_year') }}</div>
                        <div class="mt-1 text-white">{{ $groupRecord->academicYear?->name ?: __('workflow.common.no_academic_year') }}</div>
                    </div>
                </div>
            </section>

            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('workflow.student_attendance.history.title') }}</div>
                @if ($recentDays->isEmpty())
                    <div class="mt-4 text-sm text-neutral-400">{{ __('workflow.student_attendance.history.empty') }}</div>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($recentDays as $day)
                            <button
                                type="button"
                                wire:click="selectDay('{{ $day->attendance_date?->format('Y-m-d') }}')"
                                class="w-full rounded-2xl border px-4 py-3 text-left transition {{ $attendance_date === $day->attendance_date?->format('Y-m-d') ? 'border-emerald-400/40 bg-emerald-500/10 text-white' : 'border-white/10 bg-white/5 text-neutral-300 hover:border-white/20 hover:bg-white/10' }}"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-medium">{{ $day->attendance_date?->format('Y-m-d') }}</div>
                                    <span class="{{ $day->status === 'closed' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('workflow.common.day_status.' . $day->status) }}
                                    </span>
                                </div>
                                <div class="mt-2 text-xs text-neutral-400">{{ __('workflow.student_attendance.history.records', ['count' => number_format($day->records_count)]) }}</div>
                            </button>
                        @endforeach
                    </div>
                @endif
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
                                    <div class="font-semibold text-white">{{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }}</div>
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
