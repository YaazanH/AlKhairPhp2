<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\StudentAttendanceDay;
use App\Models\TeacherAttendanceDay;
use App\Services\PointLedgerService;
use App\Services\StudentAttendanceDayService;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    #[Locked]
    public string $date;

    #[Locked]
    public ?int $courseId = null;

    #[Url]
    public string $tab = '';

    public function mount(string $type, int $day): void
    {
        abort_unless(in_array($type, ['students', 'teachers'], true), 404);
        $this->authorizePermission('attendance.'.($type === 'students' ? 'student' : 'teacher').'.view');
        $source = $type === 'students'
            ? $this->scopeStudentAttendanceDaysQuery(StudentAttendanceDay::query())->findOrFail($day)
            : $this->scopeTeacherAttendanceDaysQuery(TeacherAttendanceDay::query())->findOrFail($day);
        $this->date = $source->attendance_date->toDateString();
        $this->courseId = $source->course_id;
        $this->switchTab($this->tab ?: $type);
    }

    public function switchTab(string $tab): void
    {
        abort_unless(in_array($tab, ['students', 'teachers'], true), 404);
        $this->authorizePermission('attendance.'.($tab === 'students' ? 'student' : 'teacher').'.view');
        $this->tab = $tab;
    }

    protected function selectedDay()
    {
        abort_unless(in_array($this->tab, ['students', 'teachers'], true), 404);
        $this->authorizePermission('attendance.'.($this->tab === 'students' ? 'student' : 'teacher').'.view');
        $query = $this->tab === 'students'
            ? $this->scopeStudentAttendanceDaysQuery(StudentAttendanceDay::query())
            : $this->scopeTeacherAttendanceDaysQuery(TeacherAttendanceDay::query());

        return $query->whereDate('attendance_date', $this->date)->where('course_id', $this->courseId)->first();
    }

    public function startAttendance(): void
    {
        $canCreateStudents = $this->canPermission('attendance.student.view') && $this->canPermission('attendance.student.take');
        $canCreateTeachers = $this->canPermission('attendance.teacher.view') && $this->canPermission('attendance.teacher.take');
        abort_unless($canCreateStudents || $canCreateTeachers, 403);
        abort_unless($this->courseId && Course::query()->whereNull('finished_at')->where('is_active', true)->whereKey($this->courseId)->exists(), 409);
        $groupsQuery = $this->scopeGroupsQuery(Group::query()->where('course_id', $this->courseId)->where('is_active', true));
        abort_unless((clone $groupsQuery)->exists(), 403);

        [$studentDay, $teacherDay] = $this->pairedDays();

        // An inaccessible existing record must never be modified through the empty state.
        abort_if($canCreateStudents && ! $studentDay && StudentAttendanceDay::query()->where('course_id', $this->courseId)->whereDate('attendance_date', $this->date)->exists(), 409);
        abort_if($canCreateTeachers && ! $teacherDay && TeacherAttendanceDay::query()->where('course_id', $this->courseId)->whereDate('attendance_date', $this->date)->exists(), 409);

        $studentStatusId = $canCreateStudents ? $this->defaultStatusId('student') : null;
        $teacherStatusId = $canCreateTeachers ? $this->defaultStatusId('teacher') : null;
        abort_if($canCreateStudents && ! $studentStatusId, 422);
        abort_if($canCreateTeachers && ! $teacherStatusId, 422);

        DB::transaction(function () use ($canCreateStudents, $canCreateTeachers, $groupsQuery, $studentDay, $teacherDay, $studentStatusId, $teacherStatusId): void {
            $sharedStatus = $studentDay?->status ?? $teacherDay?->status ?? 'open';

            if ($canCreateStudents && ! $studentDay) {
                $groups = $groupsQuery->whereHas('schedules', fn ($query) => $query->where('is_active', true)->where('day_of_week', Carbon::parse($this->date)->dayOfWeek))->get();
                app(StudentAttendanceDayService::class)->createOrSyncDay(
                    $this->date, $groups, auth()->user(), null, $sharedStatus, $studentStatusId, $this->courseId
                );
            }

            if ($canCreateTeachers && ! $teacherDay) {
                $service = app(TeacherAttendanceDayService::class);
                $service->createOrSyncDay(
                    $this->date,
                    $service->scheduledTeachers($this->date, $this->courseId, auth()->user()),
                    auth()->user(),
                    null,
                    $sharedStatus,
                    $teacherStatusId,
                    $this->courseId,
                );
            }
        });
    }

    public function toggleDayStatus(): void
    {
        $this->authorizePermission('attendance.student.toggle-day-status');
        $this->authorizePermission('attendance.teacher.take');
        [$studentDay, $teacherDay] = $this->pairedDays();
        abort_unless($studentDay || $teacherDay, 404);

        $status = ($studentDay?->status ?? $teacherDay?->status) === 'closed' ? 'open' : 'closed';

        DB::transaction(function () use ($studentDay, $teacherDay, $status): void {
            if ($studentDay) {
                app(StudentAttendanceDayService::class)->setDayStatus($studentDay, $status);
            }

            if ($teacherDay) {
                $teacherDay->update(['status' => $status]);
            }
        });

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
        $this->authorizePermission('attendance.teacher.take');
        [$studentDay, $teacherDay] = $this->pairedDays();
        abort_unless($studentDay || $teacherDay, 404);
        abort_if($studentDay?->course_finished_at, 409, __('workflow.student_attendance.messages.archived_day_locked'));
        abort_if(
            $teacherDay && $teacherDay->records()->whereNotNull('course_finished_at')->exists(),
            409,
            __('workflow.teacher_attendance.errors.archived_day_locked'),
        );

        $enrollmentIds = collect();

        DB::transaction(function () use ($studentDay, $teacherDay, &$enrollmentIds): void {
            if ($studentDay) {
                $studentDay->load('groupAttendanceDays.records');
                foreach ($studentDay->groupAttendanceDays as $groupDay) {
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
                $studentDay->delete();
            }

            if ($teacherDay) {
                $teacherDay->records()->delete();
                $teacherDay->delete();
            }
        });

        Enrollment::query()->with('student')->whereKey($enrollmentIds->filter()->unique()->values())->get()
            ->each(fn (Enrollment $enrollment) => app(PointLedgerService::class)->syncEnrollmentCaches($enrollment));

        session()->flash('status', __('workflow.student_attendance.days.messages.deleted'));
        $this->redirect(route('attendance.index'), navigate: true);
    }

    protected function pairedDays(): array
    {
        $studentDay = $this->canPermission('attendance.student.view')
            ? $this->scopeStudentAttendanceDaysQuery(StudentAttendanceDay::query())
                ->whereDate('attendance_date', $this->date)->where('course_id', $this->courseId)->first()
            : null;
        $teacherDay = $this->canPermission('attendance.teacher.view')
            ? $this->scopeTeacherAttendanceDaysQuery(TeacherAttendanceDay::query())
                ->whereDate('attendance_date', $this->date)->where('course_id', $this->courseId)->first()
            : null;

        return [$studentDay, $teacherDay];
    }

    protected function defaultStatusId(string $scope): ?int
    {
        return AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', [$scope, 'both'])
            ->orderByDesc('is_default')
            ->orderByDesc('is_present')
            ->orderBy('name')
            ->value('id');
    }

    public function with(): array
    {
        return ['selectedDay' => $this->selectedDay()];
    }
}; ?>

<div @class(['page-stack' => ! $selectedDay])>
    @if ($selectedDay)
        @if ($tab === 'students')
            <livewire:student-attendance.show :student-attendance-day="$selectedDay" :unified="true" :key="'students-'.$selectedDay->id.'-'.$selectedDay->status" />
        @else
            <livewire:teachers.attendance-show :teacher-attendance-day="$selectedDay" :unified="true" :key="'teachers-'.$selectedDay->id.'-'.$selectedDay->status" />
        @endif
    @else
        <div class="attendance-day-header">
            <section class="page-hero attendance-day-hero p-6 lg:p-8">
                <x-back-link :href="route('attendance.index')" navigate />
                <h1 class="font-display mt-4 text-4xl text-white">{{ __('attendance.day') }}</h1>
                <div class="attendance-day-metrics mt-5">
                    <div class="rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center">
                        <div class="text-xs text-neutral-300">{{ __('workflow.student_attendance.form.attendance_date') }}</div>
                        <bdi dir="ltr" class="mt-1 block text-lg font-semibold text-emerald-100">{{ \App\Support\DateDisplay::html(Carbon::parse($date)->format('d-m-Y')) }}</bdi>
                    </div>
                    <div class="attendance-day-present-metric rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center" data-attendance-present-count>
                        <div class="text-xs text-neutral-300">{{ __('attendance.present_'.$tab) }}</div>
                        <span class="mt-1 block text-lg font-semibold text-emerald-100">0</span>
                    </div>
                </div>
            </section>
            <div class="attendance-day-navigation-row"><x-attendance-type-switch :selected="$tab" /></div>
        </div>
        <section class="surface-table p-6">
            <p class="mb-4">{{ __('attendance.missing') }}</p>
            @if ($courseId)
                @can('attendance.'.($tab === 'students' ? 'student' : 'teacher').'.take')
                    <button type="button" wire:click="startAttendance" wire:loading.attr="disabled" class="pill-link pill-link--accent">{{ __('attendance.start') }}</button>
                @endcan
            @endif
        </section>
    @endif
</div>
