<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GroupDailySummaryService;
use App\Support\RoleRegistry;
use App\Support\ScheduleTimeSlots;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions, AuthorizesTeacherAssignments, WithPagination;

    public Group $currentGroup;
    public bool $showEditModal = false;
    public bool $showScheduleModal = false;
    public bool $showAddStudentModal = false;
    public string $name = '';
    public string $course_id = '';
    public string $academic_year_id = '';
    public string $teacher_id = '';
    public string $assistant_teacher_id = '';
    public string $grade_level_id = '';
    public string $curriculum_id = '';
    public string $capacity = '';
    public string $dashboard_card_template_id = '';
    public ?int $editingScheduleId = null;
    public string $day_of_week = '';
    public string $time_slot = '';
    public string $roster_student_id = '';
    public string $roster_enrolled_at = '';
    public string $progressDate = '';

    public function mount(Group $group): void
    {
        $this->authorizePermission('groups.view');
        $this->currentGroup = Group::query()->findOrFail($group->id);
        $this->authorizeScopedGroupAccess($this->currentGroup);
        $this->progressDate = now()->toDateString();
        $this->roster_enrolled_at = now()->toDateString();
    }

    public function with(): array
    {
        $group = Group::query()->with(['course', 'academicYear', 'teacher', 'assistantTeacher', 'gradeLevel', 'curriculum'])->withCount(['enrollments as active_students_count' => fn ($q) => $q->where('status', 'active')])->findOrFail($this->currentGroup->id);
        $roster = $this->scopeEnrollmentsQuery(Enrollment::query())->where('group_id', $group->id)->where('status', 'active')->with(['student.parentProfile', 'student.gradeLevel', 'student.quranCurrentJuz', 'student.user'])->orderByDesc('enrolled_at')->paginate(10, ['*'], 'rosterPage');
        $availableStudents = $this->scopeStudentsQuery(Student::query())->where('status', 'active')->whereDoesntHave('enrollments', fn ($q) => $q->where('group_id', $group->id)->where('status', 'active'))->orderBy('first_name')->orderBy('last_name')->get();

        return [
            'groupRecord' => $group,
            'roster' => $roster,
            'availableStudents' => $availableStudents,
            'schedules' => GroupSchedule::query()->where('group_id', $group->id)->orderBy('day_of_week')->orderBy('time_slot')->get(),
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(),
            'teachers' => $this->availableTeachersQuery()->orderBy('first_name')->orderBy('last_name')->get(),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('name')->get(),
            'curricula' => Curriculum::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('course_id')
                    ->orWhere('course_id', $this->course_id ?: $group->course_id))
                ->with('gradeLevel:id,name,sort_order')
                ->orderByRaw('CASE WHEN grade_level_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy(
                    GradeLevel::query()
                        ->select('sort_order')
                        ->whereColumn('grade_levels.id', 'curricula.grade_level_id')
                        ->limit(1)
                )
                ->orderBy('name')
                ->get(),
            'dashboardCardTemplates' => PrintTemplate::query()->where('is_active', true)->orderBy('name')->get(),
            'days' => collect(range(0, 6))->mapWithKeys(fn ($day) => [$day => __('schedules.group.days.'.$day)]),
            'timeSlots' => ScheduleTimeSlots::options(),
        ];
    }

    public function openEdit(): void
    {
        $this->authorizePermission('groups.update');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $group = $this->currentGroup->fresh();
        foreach (['name','course_id','academic_year_id','teacher_id','assistant_teacher_id','grade_level_id','curriculum_id','capacity'] as $field) {
            $value = $group->{$field};
            $this->{$field} = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
        }
        $map = (array) AppSetting::groupValues('general')->get('student_dashboard_card_templates', []);
        $this->dashboard_card_template_id = (string) ($map[(string) $group->id] ?? '');
        $this->showEditModal = true;
    }

    public function updatedCourseId(): void
    {
        $this->academic_year_id = (string) (Course::query()->whereKey($this->course_id)->value('academic_year_id')
            ?? $this->currentGroup->academic_year_id
            ?? '');

        if ($this->curriculum_id && ! Curriculum::query()
            ->whereKey($this->curriculum_id)
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('course_id')
                ->orWhere('course_id', $this->course_id))
            ->exists()) {
            $this->curriculum_id = '';
        }
    }

    public function saveGroup(): void
    {
        $this->authorizePermission('groups.update');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $this->academic_year_id = (string) (Course::query()->whereKey($this->course_id)->value('academic_year_id')
            ?? $this->currentGroup->academic_year_id
            ?? '');
        $data = $this->validate([
            'name' => ['required','string','max:255'], 'course_id' => ['required','integer','exists:courses,id'],
            'academic_year_id' => ['required','integer','exists:academic_years,id'], 'teacher_id' => ['nullable','integer','exists:teachers,id'],
            'assistant_teacher_id' => ['nullable','integer','different:teacher_id','exists:teachers,id'], 'grade_level_id' => ['nullable','integer','exists:grade_levels,id'],
            'curriculum_id' => ['nullable','integer', Rule::exists('curricula', 'id')->where(fn ($query) => $query
                ->where('is_active', true)
                ->where(fn ($curriculumQuery) => $curriculumQuery
                    ->whereNull('course_id')
                    ->orWhere('course_id', $this->course_id)))],
            'capacity' => ['nullable','integer','min:0'],
            'dashboard_card_template_id' => ['nullable','integer', Rule::exists('print_templates', 'id')->where(fn ($query) => $query->where('is_active', true))],
        ]);
        $templateId = $data['dashboard_card_template_id'] ?: null;
        unset($data['dashboard_card_template_id']);
        foreach (['teacher_id','assistant_teacher_id','grade_level_id','curriculum_id','capacity'] as $field) $data[$field] = filled($data[$field]) ? $data[$field] : null;
        if ($data['teacher_id']) $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail($data['teacher_id']));
        if ($data['assistant_teacher_id']) $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail($data['assistant_teacher_id']));
        if ($data['teacher_id'] && ! $this->teacherIsAvailable((int) $data['teacher_id'])) { $this->addError('teacher_id', __('crud.groups.errors.teacher_unavailable')); return; }
        if ($data['assistant_teacher_id'] && ! $this->teacherIsAvailable((int) $data['assistant_teacher_id'])) { $this->addError('assistant_teacher_id', __('crud.groups.errors.assistant_teacher_unavailable')); return; }
        $this->currentGroup->update($data);
        $map = (array) AppSetting::groupValues('general')->get('student_dashboard_card_templates', []);
        if ($templateId) $map[(string) $this->currentGroup->id] = (int) $templateId; else unset($map[(string) $this->currentGroup->id]);
        AppSetting::storeValue('general', 'student_dashboard_card_templates', $map, 'array');
        $this->showEditModal = false;
        session()->flash('status', __('crud.groups.messages.updated'));
    }

    public function deactivate(): void
    {
        $this->authorizePermission('groups.update');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        DB::transaction(function (): void {
            $this->currentGroup->update(['is_active' => false]);
            Enrollment::query()->where('group_id', $this->currentGroup->id)->where('status', 'active')->update(['status' => 'cancelled', 'left_at' => now()->toDateString()]);
        });
        session()->flash('status', __('crud.groups.messages.deactivated'));
    }

    public function closeEdit(): void { $this->showEditModal = false; $this->resetValidation(); }
    public function closeSchedules(): void { $this->showScheduleModal = false; $this->resetSchedule(); $this->resetValidation(); }
    public function closeAddStudent(): void { $this->showAddStudentModal = false; $this->roster_student_id = ''; $this->resetValidation(); }
    public function showScheduleModal(): void { $this->closeSchedules(); }
    public function showAddStudentModal(): void { $this->closeAddStudent(); }

    public function duplicateGroup()
    {
        $this->authorizePermission('groups.create');
        $this->authorizePermission('groups.update');

        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $source = $this->currentGroup->fresh(['schedules']);

        $newGroupId = DB::transaction(function () use ($source): int {
            $copy = $source->replicate(['name', 'course_finished_at', 'course_finished_was_active']);
            $copy->name = $this->uniqueCopyName($source);
            $copy->course_finished_at = null;
            $copy->course_finished_was_active = null;
            $copy->is_active = true;
            $copy->save();

            // A new group initially inherits the course schedule. Replace it with the
            // source group's exact schedule so the copied setup remains faithful.
            $copy->schedules()->delete();
            foreach ($source->schedules as $schedule) {
                $copy->schedules()->create([
                    'day_of_week' => $schedule->day_of_week,
                    'time_slot' => $schedule->time_slot,
                    'starts_at' => $schedule->starts_at,
                    'ends_at' => $schedule->ends_at,
                    'room_name' => $schedule->room_name,
                    'is_active' => $schedule->is_active,
                ]);
            }

            $templateMap = (array) AppSetting::groupValues('general')->get('student_dashboard_card_templates', []);
            if (isset($templateMap[(string) $source->id])) {
                $templateMap[(string) $copy->id] = $templateMap[(string) $source->id];
                AppSetting::storeValue('general', 'student_dashboard_card_templates', $templateMap, 'array');
            }

            return $copy->id;
        });

        session()->flash('status', __('crud.groups.messages.copied'));

        return $this->redirectRoute('groups.index', ['edit' => $newGroupId], navigate: true);
    }

    public function deleteGroup()
    {
        $this->authorizePermission('groups.delete');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $group = $this->currentGroup->loadCount('enrollments');
        if ($group->enrollments_count) { $this->addError('delete', __('crud.groups.errors.delete_linked')); return; }
        $group->delete();
        return $this->redirectRoute('groups.index', navigate: true);
    }

    public function addStudent(bool $addAnother = false): void
    {
        $this->authorizePermission('enrollments.create');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $data = $this->validate([
            'roster_student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'roster_enrolled_at' => ['required', 'date'],
        ]);
        $student = Student::query()->findOrFail($data['roster_student_id']);
        $this->authorizeScopedStudentAccess($student);
        $enrollment = Enrollment::withTrashed()->firstOrNew(['group_id' => $this->currentGroup->id, 'student_id' => $data['roster_student_id']]);
        if ($enrollment->trashed()) $enrollment->restore();
        $enrollment->fill(['enrolled_at' => $data['roster_enrolled_at'], 'status' => 'active', 'left_at' => null])->save();
        $this->resetPage('rosterPage');
        $this->roster_student_id = '';
        $this->roster_enrolled_at = now()->toDateString();
        $this->showAddStudentModal = $addAnother;
    }

    public function saveSchedule(): void
    {
        $this->authorizePermission('group-schedules.manage');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $data = $this->validate([
            'day_of_week' => ['required','integer','between:0,6'],
            'time_slot' => ['required', Rule::in(ScheduleTimeSlots::keys()), Rule::unique('group_schedules', 'time_slot')->where(fn ($query) => $query->where('group_id', $this->currentGroup->id)->where('day_of_week', $this->day_of_week))->ignore($this->editingScheduleId)],
        ]);
        [$startsAt, $endsAt] = ScheduleTimeSlots::times($data['time_slot']);
        GroupSchedule::query()->updateOrCreate(['id' => $this->editingScheduleId, 'group_id' => $this->currentGroup->id], ['day_of_week' => $data['day_of_week'], 'time_slot' => $data['time_slot'], 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'room_name' => null, 'is_active' => true]);
        $this->resetSchedule();
    }

    public function updatedDayOfWeek(): void
    {
        $this->addScheduleWhenComplete();
    }

    public function updatedTimeSlot(): void
    {
        $this->addScheduleWhenComplete();
    }

    protected function addScheduleWhenComplete(): void
    {
        if ($this->editingScheduleId !== null || $this->day_of_week === '' || $this->time_slot === '') {
            return;
        }

        $this->saveSchedule();
    }

    public function saveAndCloseSchedules(): void
    {
        if ($this->editingScheduleId !== null) {
            $this->saveSchedule();

            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }

        if (! GroupSchedule::query()->where('group_id', $this->currentGroup->id)->exists()) {
            $this->addError('scheduleRows', __('schedules.errors.required'));

            return;
        }

        $this->closeSchedules();
    }

    public function editSchedule(int $id): void
    {
        $this->authorizePermission('group-schedules.manage');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $schedule = GroupSchedule::query()->where('group_id', $this->currentGroup->id)->findOrFail($id);
        $this->editingScheduleId = $schedule->id;
        $this->day_of_week = (string) $schedule->day_of_week;
        $this->time_slot = $schedule->time_slot ?: ScheduleTimeSlots::closest($schedule->starts_at?->format('H:i'));
    }

    public function deleteSchedule(int $id): void
    {
        $this->authorizePermission('group-schedules.manage');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        if (GroupSchedule::query()->where('group_id', $this->currentGroup->id)->count() <= 1) {
            $this->addError('scheduleRows', __('schedules.errors.required'));

            return;
        }

        GroupSchedule::query()->where('group_id', $this->currentGroup->id)->findOrFail($id)->delete();
        $this->resetSchedule();
    }
    public function resetSchedule(): void { $this->editingScheduleId = null; $this->day_of_week = ''; $this->time_slot = ''; }

    public function copyProgress(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(RoleRegistry::unrestrictedRoles()), 403);

        $date = $this->validate(['progressDate' => ['required', 'date']])['progressDate'];

        $this->dispatch('admin-copy-text', text: app(GroupDailySummaryService::class)->currentCopyTextForUser(
            $this->currentGroup,
            $date,
            auth()->user(),
        ));
    }

    protected function uniqueCopyName(Group $source): string
    {
        $candidate = __('crud.groups.copy.name', ['name' => $source->name]);
        $counter = 2;

        while (Group::withTrashed()
            ->where('course_id', $source->course_id)
            ->where('name', $candidate)
            ->exists()) {
            $candidate = __('crud.groups.copy.name_numbered', [
                'name' => $source->name,
                'number' => $counter,
            ]);
            $counter++;
        }

        return $candidate;
    }

    protected function availableTeachersQuery()
    {
        $assignedTeacherIds = collect([
            $this->currentGroup->teacher_id,
            $this->currentGroup->assistant_teacher_id,
        ])->filter()->map(fn ($id) => (int) $id)->all();

        return $this->scopeTeachersQuery(
            Teacher::query()
                ->where(fn ($query) => $query
                    ->whereIn('id', $assignedTeacherIds)
                    ->orWhere(fn ($availableQuery) => $availableQuery
                        ->where('status', 'active')
                        ->where('is_helping', true)
                        ->whereDoesntHave('assignedGroups', fn ($groupQuery) => $groupQuery
                            ->whereNull('course_finished_at')
                            ->whereKeyNot($this->currentGroup->id))
                        ->whereDoesntHave('assistedGroups', fn ($groupQuery) => $groupQuery
                            ->whereNull('course_finished_at')
                            ->whereKeyNot($this->currentGroup->id))))
        );
    }

    protected function teacherIsAvailable(int $teacherId): bool
    {
        return $this->availableTeachersQuery()->whereKey($teacherId)->exists();
    }

    protected function ensureGroupIsEditable(): bool
    {
        $group = $this->currentGroup->fresh(['course', 'academicYear']);

        if ($group->is_active
            && ! $group->course_finished_at
            && ($group->course?->is_active ?? true)
            && ($group->academicYear?->is_active ?? true)) {
            return true;
        }

        $this->addError('group', __('crud.groups.errors.course_archived'));

        return false;
    }

}; ?>

@php
    $teacherName = $groupRecord->teacher ? trim($groupRecord->teacher->first_name.' '.$groupRecord->teacher->last_name) : __('crud.common.not_available');
    $assistantName = $groupRecord->assistantTeacher ? trim($groupRecord->assistantTeacher->first_name.' '.$groupRecord->assistantTeacher->last_name) : __('crud.common.not_available');
    $viewerTeacherId = auth()->user()?->teacherProfile?->id;
    $isAssignedTeacher = $viewerTeacherId && in_array($viewerTeacherId, [$groupRecord->teacher_id, $groupRecord->assistant_teacher_id], true);
    $groupIsEditable = $groupRecord->is_active
        && ! $groupRecord->course_finished_at
        && ($groupRecord->course?->is_active ?? true)
        && ($groupRecord->academicYear?->is_active ?? true);
    $canManageGroup = $groupIsEditable && (bool) auth()->user()?->can('groups.update');
    $canCopyGroup = $groupIsEditable
        && (bool) auth()->user()?->can('groups.create')
        && (bool) auth()->user()?->can('groups.update');
    $canCopyGroupSummary = auth()->user()?->hasAnyRole(RoleRegistry::unrestrictedRoles()) ?? false;
    $showGroupActionStack = $canManageGroup || $canCopyGroup || $canCopyGroupSummary;
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="group-show-hero-layout flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div>
                @unless($isAssignedTeacher)<x-back-link :href="route('groups.index')" navigate />@endunless
                <h1 class="font-display mt-4 text-4xl text-white md:text-5xl">{{ $groupRecord->name }}</h1>
            </div>

            <div class="group-show-hero-widgets flex flex-col gap-3 lg:flex-row lg:items-start">
                <div class="group-show-details surface-panel p-3">
                    <dl class="group-show-details__grid">
                        <div class="group-show-detail">
                            <dt>{{ __('crud.groups.table.headers.teacher') }}</dt>
                            <dd>{{ $teacherName }}</dd>
                        </div>
                        <div class="group-show-detail">
                            <dt>{{ __('crud.groups.form.fields.assistant_teacher') }}</dt>
                            <dd>{{ $assistantName }}</dd>
                        </div>
                        <div class="group-show-detail">
                            <dt>{{ __('crud.groups.form.fields.grade_level') }}</dt>
                            <dd>{{ $groupRecord->gradeLevel?->name ?: __('crud.common.not_available') }}</dd>
                        </div>
                        <div class="group-show-detail">
                            <dt>{{ __('crud.groups.table.headers.students') }}</dt>
                            <dd>{{ number_format($groupRecord->active_students_count) }}</dd>
                        </div>
                    </dl>
                </div>

                @if($showGroupActionStack)
                    <div class="group-show-action-stack flex max-w-full flex-col gap-3">
                        @if($canManageGroup || $canCopyGroup)
                            <div class="group-show-actions surface-panel flex max-w-full items-center gap-2 p-3">
                                @if($canManageGroup)
                                    <x-edit-action-button wire:click="openEdit" :label="__('crud.common.actions.edit')" data-group-hero-edit-action />
                                @endif
                                @if($canManageGroup)
                                    <button type="button" wire:click="$set('showScheduleModal', true)" class="admin-icon-button" title="{{ __('crud.groups.actions.schedule') }}" aria-label="{{ __('crud.groups.actions.schedule') }}" data-group-hero-schedule-action>
                                        <x-admin-action-icon name="schedule" />
                                    </button>
                                @endif
                                @if($canCopyGroup)
                                    <button type="button" wire:click="duplicateGroup" class="admin-icon-button admin-icon-button--danger" title="{{ __('crud.groups.copy.action') }}" aria-label="{{ __('crud.groups.copy.action') }}" data-group-hero-copy-action>
                                        <x-admin-action-icon name="copy" />
                                    </button>
                                @endif
                            </div>
                        @endif
                        @if($canCopyGroupSummary)
                            <div class="group-show-summary surface-panel flex max-w-full items-center gap-2 p-3" data-group-copy-summary>
                                <label for="group-copy-summary-date" class="group-show-summary__date">
                                    <span class="sr-only">{{ __('crud.groups.quick_summary.fields.date') }}</span>
                                    <input id="group-copy-summary-date" wire:model="progressDate" type="date" class="w-full rounded-xl px-3 py-2 text-sm">
                                </label>
                                <button type="button" wire:click="copyProgress" class="admin-icon-button admin-icon-button--accent" title="{{ __('crud.groups.quick_summary.copy_group_action') }}" aria-label="{{ __('crud.groups.quick_summary.copy_group_action') }}" data-group-copy-summary-confirm-action>
                                    <x-admin-action-icon name="copy" />
                                </button>
                            </div>
                            @error('progressDate')<div class="text-sm text-red-400">{{ $message }}</div>@enderror
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>
    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    @error('group')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror
    <section class="surface-table" data-group-roster-table>
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('crud.groups.roster.title') }}</div>
            <div class="admin-toolbar__controls">
                <div class="admin-toolbar__actions">
                    @if ($groupIsEditable)
                        <a target="_blank" rel="noopener" href="{{ route('groups.roster.pdf', $groupRecord) }}" class="admin-icon-button" title="{{ __('crud.groups.roster.download_pdf_action') }}" aria-label="{{ __('crud.groups.roster.download_pdf_action') }}" data-group-roster-pdf-action><x-pdf-export-icon /></a>
                    @endif
                    @if (! $groupRecord->course_finished_at && ($groupRecord->course?->is_active ?? true))
                        @can('enrollments.create')
                            <x-add-action-button wire:click="$set('showAddStudentModal', true)" :label="__('crud.groups.roster.add_student')" />
                        @endcan
                    @endif
                </div>
            </div>
        </div>

        @if($roster->isEmpty())
            <div class="admin-empty-state">{{ __('crud.groups.roster.empty') }}</div>
        @else
            <div class="overflow-x-auto" data-table-scroll-region>
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-center lg:px-6">#</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.name') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.student_number') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.grade') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.current_juz') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.fields.enrolled_at') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.parent_name') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.groups.roster.table.headers.father_mobile') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach($roster as $enrollment)
                            <tr>
                                <td class="px-5 py-4 text-center lg:px-6">{{ $roster->firstItem()+$loop->index }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="student-inline">
                                        @if($enrollment->student)<x-student-avatar :student="$enrollment->student" size="sm" />@endif
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $enrollment->student?->full_name ?: '—' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 font-mono text-white lg:px-6">{{ $enrollment->student?->student_number ?: '—' }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->student?->gradeLevel?->name ?: '—' }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->student?->quranCurrentJuz?->juz_number ?: '—' }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6" dir="ltr">{{ $enrollment->enrolled_at?->format('d-m-Y') ?: '—' }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->student?->parentProfile?->father_name ?: '—' }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6" dir="ltr">{{ $enrollment->student?->parentProfile?->father_phone ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if($roster->hasPages())<div class="border-t border-white/10 p-4">{{ $roster->links() }}</div>@endif
    </section>

    <x-admin.modal :show="$showAddStudentModal" :title="__('crud.groups.roster.add_student')" close-method="showAddStudentModal" max-width="2xl"><div class="space-y-4"><div><label class="mb-1 block text-sm">{{ __('crud.students.table.headers.name') }}</label><select wire:model="roster_student_id" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" class="w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.common.select') }}</option>@foreach($availableStudents as $student)<option value="{{ $student->id }}">{{ $student->full_name }}</option>@endforeach</select>@error('roster_student_id')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div><div><label class="mb-1 block text-sm">{{ __('crud.groups.roster.fields.enrolled_at') }}</label><input wire:model="roster_enrolled_at" type="date" class="w-full rounded-xl px-4 py-3"></div><div class="flex gap-2"><button wire:click="addStudent(false)" class="pill-link pill-link--accent">{{ __('crud.groups.roster.add_student') }}</button><button wire:click="addStudent(true)" class="pill-link">{{ __('crud.common.actions.add_and_new') }}</button></div></div></x-admin.modal>

    <x-admin.modal :show="$showScheduleModal" :title="__('crud.groups.actions.schedule')" max-width="3xl">
        <x-slot:header-actions>
            <button type="button" wire:click="saveAndCloseSchedules" class="admin-modal__close" title="{{ __('crud.common.actions.save') }}" aria-label="{{ __('crud.common.actions.save') }}" data-group-schedule-save>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3.75h11.25L19.5 7v13.25H5V3.75Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 3.75v5.5h8v-5.5M8.25 20.25v-6.5h8v6.5" />
                </svg>
            </button>
        </x-slot:header-actions>
        <section class="surface-table settings-record-table overflow-visible" data-searchable-select-table-surface>
            <div class="overflow-visible">
                <table class="w-full text-sm">
                    <thead><tr><th class="px-4 py-3">{{ __('schedules.group.form.fields.day') }}</th><th class="px-4 py-3">{{ __('schedules.group.form.fields.timing') }}</th><th class="admin-actions-column w-32 px-4 py-3 text-center">{{ __('schedules.group.table.headers.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach($schedules as $schedule)
                            <tr wire:key="group-show-schedule-{{ $schedule->id }}-{{ $editingScheduleId === $schedule->id ? 'edit' : 'view' }}" data-group-schedule-row>
                                @if($editingScheduleId === $schedule->id)
                                    <td class="px-4 py-3"><select wire:model="day_of_week" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('schedules.group.form.placeholders.day') }}" class="h-11 w-full rounded-xl px-3 text-sm"><option value="">{{ __('schedules.group.form.placeholders.day') }}</option>@foreach($days as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('day_of_week')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                    <td class="px-4 py-3"><select wire:model="time_slot" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('schedules.group.form.placeholders.timing') }}" class="h-11 w-full rounded-xl px-3 text-sm"><option value="">{{ __('schedules.group.form.placeholders.timing') }}</option>@foreach($timeSlots as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('time_slot')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                    <td class="px-4 py-3"><div class="flex justify-end gap-2">
                                        <button type="button" wire:click="saveSchedule" class="admin-icon-button admin-icon-button--accent" title="{{ __('crud.common.actions.update') }}" aria-label="{{ __('crud.common.actions.update') }}" data-group-schedule-update><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg></button>
                                        <button type="button" wire:click="deleteSchedule({{ $schedule->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="admin-icon-button admin-icon-button--danger" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-group-schedule-delete><x-icons.trash class="size-5" /></button>
                                    </div></td>
                                @else
                                    <td class="px-4 py-3">{{ $days[$schedule->day_of_week] }}</td>
                                    <td class="px-4 py-3">{{ $timeSlots[$schedule->time_slot ?: \App\Support\ScheduleTimeSlots::closest($schedule->starts_at?->format('H:i'))] }}</td>
                                    <td class="px-4 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editSchedule({{ $schedule->id }})" class="admin-icon-button" title="{{ __('crud.common.actions.edit') }}" aria-label="{{ __('crud.common.actions.edit') }}" data-group-schedule-edit><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m4 20 4.2-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/></svg></button></div></td>
                                @endif
                            </tr>
                        @endforeach
                        @if($editingScheduleId === null)<tr class="schedule-add-row">
                            <td class="px-4 py-3"><select wire:model.live="day_of_week" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('schedules.group.form.placeholders.day') }}" class="h-11 w-full rounded-xl px-3 text-sm"><option value="">{{ __('schedules.group.form.placeholders.day') }}</option>@foreach($days as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('day_of_week')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                            <td class="px-4 py-3"><select wire:model.live="time_slot" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('schedules.group.form.placeholders.timing') }}" class="h-11 w-full rounded-xl px-3 text-sm"><option value="">{{ __('schedules.group.form.placeholders.timing') }}</option>@foreach($timeSlots as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('time_slot')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                            <td class="px-4 py-3"></td>
                        </tr>@endif
                    </tbody>
                </table>
            </div>
        </section>
        @error('scheduleRows')<div class="mt-3 text-sm text-red-400">{{ $message }}</div>@enderror
    </x-admin.modal>

    <x-admin.modal :show="$showEditModal" :title="__('crud.groups.form.edit_title')" close-method="closeEdit" max-width="5xl">
        <form wire:submit="saveGroup" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2" data-group-form-row="identity">
                <label class="block text-sm">{{ __('crud.groups.form.fields.name') }}<input wire:model="name" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.course') }}<select wire:model.live="course_id" class="mt-1 w-full rounded-xl px-4 py-3">@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></label>
            </div>
            <div class="grid gap-4 md:grid-cols-2" data-group-form-row="teachers">
                <label class="block text-sm">{{ __('crud.groups.form.fields.teacher') }}<select wire:model="teacher_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">—</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.assistant_teacher') }}<select wire:model="assistant_teacher_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">—</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select></label>
            </div>
            <div class="grid gap-4 md:grid-cols-2" data-group-form-row="learning">
                <label class="block text-sm">{{ __('crud.groups.form.fields.grade_level') }}<select wire:model="grade_level_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">—</option>@foreach($gradeLevels as $grade)<option value="{{ $grade->id }}">{{ $grade->name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('curricula.fields.curriculum') }}<select wire:model="curriculum_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('curricula.options.no_curriculum') }}</option>@foreach($curricula as $curriculum)<option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>@endforeach</select></label>
            </div>
            <div class="grid gap-4 md:grid-cols-2" data-group-form-row="capacity-template">
                <label class="block text-sm">{{ __('crud.groups.form.fields.capacity') }}<input wire:model="capacity" type="number" min="0" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                <label class="block text-sm">{{ __('crud.groups.dashboard_card.fields.template') }}<select wire:model="dashboard_card_template_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.groups.dashboard_card.placeholders.none') }}</option>@foreach($dashboardCardTemplates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select></label>
            </div>
            @error('delete')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror
            <div class="admin-action-cluster admin-action-cluster--end">
                <button type="button" wire:click="saveGroup" wire:loading.attr="disabled" wire:target="saveGroup" class="admin-icon-button admin-icon-button--accent admin-modal-action-button" title="{{ __('crud.common.actions.update') }}" aria-label="{{ __('crud.common.actions.update') }}" data-group-edit-save-action>
                    <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                </button>
                @can('groups.delete')
                    <x-delete-action-button wire:click="deleteGroup" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('crud.common.actions.delete')" class="admin-modal-action-button" data-group-edit-delete-action />
                @endcan
            </div>
        </form>
    </x-admin.modal>
</div>
