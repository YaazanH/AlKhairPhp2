<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\StudentAttendanceDay;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public StudentAttendanceDay $currentDay;

    public function mount(StudentAttendanceDay $studentAttendanceDay): void
    {
        $this->authorizePermission('attendance.student.view');

        $this->currentDay = StudentAttendanceDay::query()
            ->with([
                'groupAttendanceDays' => fn ($query) => $this->scopeGroupAttendanceDaysQuery(
                    $query->withCount('records')->with([
                        'group' => fn ($groupQuery) => $groupQuery
                            ->with(['course', 'teacher'])
                            ->withCount([
                                'enrollments as active_enrollments_count' => fn ($enrollmentQuery) => $enrollmentQuery->where('status', 'active'),
                            ]),
                    ])
                )->orderBy('group_id'),
            ])
            ->findOrFail($studentAttendanceDay->id);

        $this->authorizeScopedStudentAttendanceDayAccess($this->currentDay);
    }

    public function with(): array
    {
        $day = $this->currentDay->fresh([
            'groupAttendanceDays' => fn ($query) => $this->scopeGroupAttendanceDaysQuery(
                $query->withCount('records')->with([
                    'group' => fn ($groupQuery) => $groupQuery
                        ->with(['course', 'teacher'])
                        ->withCount([
                            'enrollments as active_enrollments_count' => fn ($enrollmentQuery) => $enrollmentQuery->where('status', 'active'),
                        ]),
                ])
            )->orderBy('group_id'),
        ]);

        return [
            'dayRecord' => $day,
            'stats' => [
                'groups' => $day->groupAttendanceDays->count(),
                'students' => $day->groupAttendanceDays->sum(fn ($groupDay) => (int) ($groupDay->group?->active_enrollments_count ?? 0)),
                'marked' => $day->groupAttendanceDays->sum('records_count'),
            ],
        ];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.student_attendance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.day_details.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_attendance.day_details.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $dayRecord->attendance_date?->format('Y-m-d') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.student_attendance.day_details.stats.groups') }}: {{ number_format($stats['groups']) }}</span>
            <span class="badge-soft">{{ __('workflow.student_attendance.day_details.stats.students') }}: {{ number_format($stats['students']) }}</span>
            <span class="badge-soft">{{ __('workflow.student_attendance.day_details.stats.marked') }}: {{ number_format($stats['marked']) }}</span>
        </div>
    </section>

    <div>
        <a href="{{ route('student-attendance.index') }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.student_attendance.day_details.back') }}</a>
    </div>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.day_details.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($dayRecord->groupAttendanceDays->count())]) }}</div>
            </div>
        </div>

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
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.marked') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.status') }}</th>
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
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($groupDay->records_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $groupDay->status === 'closed' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('workflow.common.day_status.'.$groupDay->status) }}
                                    </span>
                                </td>
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
