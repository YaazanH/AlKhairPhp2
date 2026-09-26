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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public GroupAttendanceDay $currentGroupDay;

    public string $day_status = 'open';

    public string $notes = '';

    public array $selected_statuses = [];

    public bool $showAddStudentModal = false;

    public ?int $rosterStudentId = null;

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
            'canAddStudent' => auth()->user()?->can('enrollments.create') && $this->canEnrollInGroupDay($groupDay),
            'availableStudents' => $this->showAddStudentModal && auth()->user()?->can('enrollments.create')
                ? $this->availableStudentsQuery()->orderBy('first_name')->orderBy('last_name')->get()
                : collect(),
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

    public function openAddStudentModal(): void
    {
        $this->authorizeRosterEnrollment();
        $this->reset('rosterStudentId');
        $this->resetValidation('rosterStudentId');
        $this->showAddStudentModal = true;
    }

    public function closeAddStudentModal(): void
    {
        $this->showAddStudentModal = false;
        $this->reset('rosterStudentId');
        $this->resetValidation('rosterStudentId');
    }

    public function addStudent(bool $addAnother = false): void
    {
        $this->authorizeRosterEnrollment();
        $this->validate(['rosterStudentId' => ['required', 'integer', 'exists:students,id']]);

        DB::transaction(function (): void {
            $student = Student::query()->lockForUpdate()->findOrFail($this->rosterStudentId);
            $this->authorizeScopedStudentAccess($student);
            if (! $this->availableStudentsQuery()->whereKey($student->id)->lockForUpdate()->first()) {
                throw ValidationException::withMessages([
                    'rosterStudentId' => __('workflow.student_attendance.enrollment.unavailable'),
                ]);
            }

            Enrollment::create([
                'student_id' => $student->id,
                'group_id' => $this->currentGroupDay->group_id,
                'enrolled_at' => now()->toDateString(),
                'status' => 'active',
            ]);
            $this->loadDay();
        });

        $this->reset('rosterStudentId');
        $this->resetValidation('rosterStudentId');
        $this->showAddStudentModal = $addAnother;
        session()->flash('status', __('workflow.student_attendance.enrollment.added'));
    }

    protected function availableStudentsQuery(): Builder
    {
        return $this->scopeStudentsQuery(Student::query())->where('status', 'active')
            ->whereDoesntHave('enrollments', fn (Builder $query) => $query->where('status', 'active')
                ->whereHas('group.course', fn (Builder $course) => $course->where('is_active', true)))
            ->whereNotIn('id', Enrollment::withTrashed()->forCourseOfGroup($this->currentGroupDay->group_id)->select('student_id'));
    }

    protected function canEnrollInGroupDay(GroupAttendanceDay $day): bool
    {
        return $day->studentAttendanceDay?->status === 'open'
            && ! $day->studentAttendanceDay?->course_finished_at
            && $day->group?->is_active
            && ! $day->group?->course_finished_at
            && $day->group?->course?->is_active
            && ! $day->group?->course?->finished_at;
    }

    protected function authorizeRosterEnrollment(): void
    {
        $this->authorizePermission('enrollments.create');
        $this->authorizePermission('attendance.student.view');
        $day = $this->currentGroupDay->fresh(['studentAttendanceDay', 'group.course']);
        $this->authorizeScopedGroupAttendanceDayAccess($day);
        if (! $this->canEnrollInGroupDay($day)) {
            throw ValidationException::withMessages([
                'rosterStudentId' => __('workflow.student_attendance.enrollment.locked'),
            ]);
        }
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
    <section class="page-hero attendance-mark-hero p-6 lg:p-8">
        <div class="group-show-hero-layout flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <x-back-link :href="route('student-attendance.show', $groupDayRecord->studentAttendanceDay)" navigate />
                <div class="eyebrow mt-4">{{ __('ui.nav.student_attendance') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.marking.title') }}</h1>
            </div>
            <div class="group-show-hero-widgets flex flex-col gap-3 lg:flex-row lg:items-start">
                <div class="group-show-details surface-panel p-3">
                    <dl class="group-show-details__grid">
                        <div class="group-show-detail">
                            <dt>{{ __('workflow.student_attendance.context.group') }}</dt>
                            <dd>{{ $groupDayRecord->group?->name ?: __('workflow.common.no_group') }}</dd>
                        </div>
                        <div class="group-show-detail">
                            <dt>{{ __('workflow.student_attendance.context.teacher') }}</dt>
                            <dd>{{ $groupDayRecord->group?->teacher ? $groupDayRecord->group->teacher->first_name.' '.$groupDayRecord->group->teacher->last_name : __('workflow.common.no_teacher_assigned') }}</dd>
                        </div>
                        <div class="group-show-detail attendance-mark-hero__course">
                            <dt>{{ __('workflow.student_attendance.context.course') }}</dt>
                            <dd>{{ $groupDayRecord->group?->course?->name ?: __('workflow.common.no_course') }}</dd>
                        </div>
                        <div class="group-show-detail">
                            <dt>{{ __('workflow.student_attendance.context.date') }}</dt>
                            <dd>{{ $groupDayRecord->studentAttendanceDay?->attendance_date?->format('d-m-Y') }}</dd>
                        </div>
                    </dl>
                </div>
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

    <section class="surface-table">
        <div class="admin-grid-meta items-center">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($activeEnrollmentCount)]) }}</div>
            </div>
            @if ($canAddStudent)
                <button type="button" wire:click="openAddStudentModal" class="admin-icon-button admin-icon-button--accent" title="{{ __('crud.groups.roster.add_student') }}" aria-label="{{ __('crud.groups.roster.add_student') }}" data-attendance-add-student><x-admin-action-icon name="add" /></button>
            @endif
        </div>
        @if (! $showAddStudentModal)
            @error('rosterStudentId') <div class="px-5 py-3 text-sm text-red-400">{{ $message }}</div> @enderror
        @endif

        @if ($enrollments->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_attendance.table.empty') }}</div>
        @else
            <div class="overflow-x-auto overflow-y-visible pb-24">
                <table class="attendance-records-table text-sm" data-attendance-records>
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.table.headers.student') }}</th>
                            <th class="attendance-desktop-only px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.table.headers.enrolled') }}</th>
                            <th class="attendance-desktop-only px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.table.headers.current_points') }}</th>
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
                                <td class="attendance-desktop-only px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->enrolled_at?->format('d-m-Y') }}</td>
                                <td class="attendance-desktop-only px-5 py-4 text-white lg:px-6">{{ $enrollment->final_points_cached }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($isDayClosed)
                                        <span class="text-neutral-200">{{ $statuses->firstWhere('id', (int) ($selected_statuses[$enrollment->id] ?? 0))?->name ?: $statuses->firstWhere('is_default', true)?->name ?: $statuses->first()?->name ?: '-' }}</span>
                                    @else
                                        <select
                                            data-search-input="false" data-dropdown-search="false" data-attendance-status-select
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
    <x-admin.modal :show="$showAddStudentModal" :title="__('crud.groups.roster.add_student')" :description="__('workflow.student_attendance.enrollment.help')" close-method="closeAddStudentModal" max-width="xl">
        <form wire:submit="addStudent(true)" class="space-y-5">
            <div class="admin-form-field">
                <label for="attendance-roster-student">{{ __('workflow.student_attendance.table.headers.student') }}</label>
                <select id="attendance-roster-student" wire:model="rosterStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}">
                    <option value="">{{ __('crud.common.select') }}</option>
                    @foreach ($availableStudents as $student)
                        <option value="{{ $student->id }}">{{ $student->full_name }}</option>
                    @endforeach
                </select>
                @error('rosterStudentId') <div class="mt-2 text-sm text-red-400">{{ $message }}</div> @enderror
                @if ($availableStudents->isEmpty()) <p class="mt-2 text-sm text-neutral-400">{{ __('workflow.student_attendance.enrollment.empty') }}</p> @endif
            </div>
            <div class="admin-action-cluster admin-action-cluster--end">
                <button type="submit" class="admin-icon-button admin-icon-button--accent" title="{{ __('crud.common.actions.add_and_new') }}" aria-label="{{ __('crud.common.actions.add_and_new') }}" wire:loading.attr="disabled" wire:target="addStudent" data-attendance-add-and-new><x-admin-action-icon name="save-new" /></button>
            </div>
        </form>
    </x-admin.modal>
</div>
