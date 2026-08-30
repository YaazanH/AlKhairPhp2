<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\GroupAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\Student;
use App\Services\StudentAttendanceDayService;
use Livewire\Volt\Component;

new class extends Component
{
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
            ->where(function ($query): void {
                $query
                    ->whereNull('student_attendance_day_id')
                    ->orWhereHas('studentAttendanceDay', fn ($dayQuery) => $dayQuery->whereNull('course_finished_at'));
            })
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
            ->orderBy(
                Student::query()
                    ->select('first_name')
                    ->whereColumn('students.id', 'enrollments.student_id')
                    ->limit(1)
            )
            ->orderBy(
                Student::query()
                    ->select('last_name')
                    ->whereColumn('students.id', 'enrollments.student_id')
                    ->limit(1)
            )
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
            'isDayClosed' => $groupDay->studentAttendanceDay?->status === 'closed',
        ];
    }

    public function saveAttendance(): void
    {
        $this->authorizePermission('attendance.student.take');

        if ($this->currentGroupDay->studentAttendanceDay?->fresh()->status === 'closed') {
            $this->addError('selected_statuses', __('workflow.student_attendance.messages.closed_day_locked'));

            return;
        }

        $validated = $this->validate([
            'notes' => ['nullable', 'string'],
            'selected_statuses' => ['array'],
            'selected_statuses.*' => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        $this->currentGroupDay->update([
            'notes' => $validated['notes'] ?: null,
        ]);

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

            $status = AttendanceStatus::query()
                ->whereKey($statusId)
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->first();

            if (! $status || ! $this->currentGroupDay->studentAttendanceDay) {
                continue;
            }

            try {
                app(StudentAttendanceDayService::class)->recordEnrollmentStatus(
                    $this->currentGroupDay->studentAttendanceDay,
                    $enrollment,
                    $status,
                );
            } catch (InvalidArgumentException $exception) {
                $this->addError('selected_statuses.'.$enrollment->id, $exception->getMessage());
            }
        }

        $this->currentGroupDay = $this->currentGroupDay->fresh(['studentAttendanceDay', 'records']);

        if ($this->currentGroupDay->studentAttendanceDay) {
            app(StudentAttendanceDayService::class)->syncAggregateStatus($this->currentGroupDay->studentAttendanceDay);
        }

        session()->flash('status', __('workflow.student_attendance.messages.saved'));
    }

    public function saveDaySummary(): void
    {
        $this->authorizePermission('attendance.student.take');

        $validated = $this->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $this->currentGroupDay->update([
            'notes' => $validated['notes'] ?: null,
        ]);

        $this->currentGroupDay = $this->currentGroupDay->fresh(['studentAttendanceDay', 'records']);

        if ($this->currentGroupDay->studentAttendanceDay) {
            app(StudentAttendanceDayService::class)->syncAggregateStatus($this->currentGroupDay->studentAttendanceDay);
        }
    }

    public function saveEnrollmentStatus(int $enrollmentId): void
    {
        $this->authorizePermission('attendance.student.take');

        if ($this->currentGroupDay->studentAttendanceDay?->fresh()->status === 'closed') {
            $this->addError('selected_statuses.'.$enrollmentId, __('workflow.student_attendance.messages.closed_day_locked'));

            return;
        }

        $this->validate([
            'selected_statuses.'.$enrollmentId => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        $statusId = $this->selected_statuses[$enrollmentId] ?? null;

        if (! $statusId) {
            return;
        }

        $enrollment = Enrollment::query()
            ->with('student')
            ->where('group_id', $this->currentGroupDay->group_id)
            ->where('status', 'active')
            ->findOrFail($enrollmentId);
        $this->authorizeScopedEnrollmentAccess($enrollment);

        $status = AttendanceStatus::query()
            ->whereKey($statusId)
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->firstOrFail();

        try {
            app(StudentAttendanceDayService::class)->recordEnrollmentStatus(
                $this->currentGroupDay->studentAttendanceDay,
                $enrollment,
                $status,
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError('selected_statuses.'.$enrollmentId, $exception->getMessage());

            return;
        }

        $this->currentGroupDay = $this->currentGroupDay->fresh(['studentAttendanceDay', 'records']);
        session()->flash('status', __('workflow.student_attendance.messages.saved'));
    }

    protected function loadDay(): void
    {
        if ($this->currentGroupDay->studentAttendanceDay) {
            app(StudentAttendanceDayService::class)->fillMissingStatuses(
                $this->currentGroupDay->studentAttendanceDay,
                null,
                auth()->user(),
            );
            $this->currentGroupDay = $this->currentGroupDay->fresh(['studentAttendanceDay', 'records']);
        }

        $this->day_status = $this->currentGroupDay->studentAttendanceDay?->status ?? 'open';
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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <x-back-link :href="route('student-attendance.show', $groupDayRecord->studentAttendanceDay)" navigate />
                <div class="eyebrow mt-4">{{ __('ui.nav.student_attendance') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.marking.title') }}</h1>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if ($isDayClosed)
        <div class="soft-callout p-4 text-sm text-amber-100">
            {{ __('workflow.student_attendance.messages.closed_day_locked') }}
        </div>
    @endif

    <section class="surface-panel p-5">
        <div class="grid gap-4 text-sm text-neutral-300 sm:grid-cols-2 lg:grid-cols-4">
            <div><span class="text-neutral-500">{{ __('workflow.student_attendance.context.group') }}:</span> <span class="text-white">{{ $groupDayRecord->group?->name ?: __('workflow.common.no_group') }}</span></div>
            <div><span class="text-neutral-500">{{ __('workflow.student_attendance.context.teacher') }}:</span> <span class="text-white">{{ $groupDayRecord->group?->teacher ? $groupDayRecord->group->teacher->first_name.' '.$groupDayRecord->group->teacher->last_name : __('workflow.common.no_teacher_assigned') }}</span></div>
            <div><span class="text-neutral-500">{{ __('workflow.student_attendance.context.course') }}:</span> <span class="text-white">{{ $groupDayRecord->group?->course?->name ?: __('workflow.common.no_course') }}</span></div>
            <div><span class="text-neutral-500">{{ __('workflow.student_attendance.context.date') }}:</span> <span class="text-white">{{ $groupDayRecord->studentAttendanceDay?->attendance_date?->format('d-m-Y') }}</span></div>
        </div>
    </section>

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
            <div class="overflow-x-auto overflow-y-visible pb-24">
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
                                            <div class="student-inline__name">{{ $enrollment->student?->full_name }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->enrolled_at?->format('d-m-Y') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $enrollment->final_points_cached }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($isDayClosed)
                                        <span class="text-neutral-200">{{ $statuses->firstWhere('id', (int) ($selected_statuses[$enrollment->id] ?? 0))?->name ?: $statuses->firstWhere('is_default', true)?->name ?: $statuses->first()?->name ?: '-' }}</span>
                                    @else
                                        <select
                                            wire:model="selected_statuses.{{ $enrollment->id }}"
                                            wire:change="saveEnrollmentStatus({{ $enrollment->id }})"
                                            @disabled(! auth()->user()->can('attendance.student.take'))
                                            class="w-full rounded-xl px-4 py-3 text-sm"
                                        >
                                            @foreach ($statuses as $status)
                                                <option value="{{ $status->id }}">{{ $status->name }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    @error('selected_statuses.'.$enrollment->id)
                                        <div class="mt-1 text-xs text-red-400">{{ $message }}</div>
                                    @enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
