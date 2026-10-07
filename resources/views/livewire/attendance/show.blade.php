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
use Illuminate\Support\Carbon;
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
        $scope = $this->tab === 'students' ? 'student' : 'teacher';
        $this->authorizePermission('attendance.'.$scope.'.view');
        $this->authorizePermission('attendance.'.$scope.'.take');
        abort_unless($this->courseId && Course::query()->whereNull('finished_at')->where('is_active', true)->whereKey($this->courseId)->exists(), 409);
        $groupsQuery = $this->scopeGroupsQuery(Group::query()->where('course_id', $this->courseId)->where('is_active', true));
        abort_unless((clone $groupsQuery)->exists(), 403);
        $model = $this->tab === 'students' ? StudentAttendanceDay::class : TeacherAttendanceDay::class;
        // An inaccessible existing record must never be modified through the empty state.
        abort_if($model::query()->where('course_id', $this->courseId)->whereDate('attendance_date', $this->date)->exists(), 409);
        $other = ($this->tab === 'students' ? TeacherAttendanceDay::class : StudentAttendanceDay::class)::query()
            ->where('course_id', $this->courseId)->whereDate('attendance_date', $this->date)->first();
        $status = AttendanceStatus::query()->where('is_active', true)->whereIn('scope', [$scope, 'both'])
            ->orderByDesc('is_default')->orderByDesc('is_present')->value('id');
        abort_unless($status, 422);
        if ($this->tab === 'students') {
            $groups = $groupsQuery->whereHas('schedules', fn ($query) => $query->where('is_active', true)->where('day_of_week', Carbon::parse($this->date)->dayOfWeek))->get();
            app(StudentAttendanceDayService::class)->createOrSyncDay($this->date, $groups, auth()->user(), null, $other?->status ?? 'open', $status, $this->courseId);
        } else {
            $service = app(TeacherAttendanceDayService::class);
            $service->createOrSyncDay($this->date, $service->scheduledTeachers($this->date, $this->courseId, auth()->user()), auth()->user(), null, $other?->status ?? 'open', $status, $this->courseId);
        }
    }

    public function with(): array
    {
        return ['selectedDay' => $this->selectedDay()];
    }
}; ?>

<div @class(['page-stack' => ! $selectedDay])>
    @if ($selectedDay)
        @if ($tab === 'students')
            <livewire:student-attendance.show :student-attendance-day="$selectedDay" :unified="true" :key="'students-'.$selectedDay->id" />
        @else
            <livewire:teachers.attendance-show :teacher-attendance-day="$selectedDay" :unified="true" :key="'teachers-'.$selectedDay->id" />
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
