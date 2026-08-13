<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\GroupSchedule;
use App\Models\MemorizationSession;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\Teacher;
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
    public string $starts_on = '';
    public string $ends_on = '';
    public string $monthly_fee = '';
    public bool $is_active = true;
    public string $dashboard_card_template_id = '';
    public ?int $editingScheduleId = null;
    public string $day_of_week = '6';
    public string $starts_at = '';
    public string $ends_at = '';
    public string $room_name = '';
    public bool $schedule_is_active = true;
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
            'schedules' => GroupSchedule::query()->where('group_id', $group->id)->orderBy('day_of_week')->orderBy('starts_at')->get(),
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(),
            'teachers' => $this->availableTeachersQuery()->orderBy('first_name')->orderBy('last_name')->get(),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('name')->get(),
            'curricula' => Curriculum::query()->where('is_active', true)->where('course_id', $this->course_id ?: $group->course_id)->orderBy('name')->get(),
            'dashboardCardTemplates' => PrintTemplate::query()->where('is_active', true)->orderBy('name')->get(),
            'days' => collect(range(0, 6))->mapWithKeys(fn ($day) => [$day => __('schedules.group.days.'.$day)]),
        ];
    }

    public function openEdit(): void
    {
        $this->authorizePermission('groups.update');
        $group = $this->currentGroup->fresh();
        foreach (['name','course_id','academic_year_id','teacher_id','assistant_teacher_id','grade_level_id','curriculum_id','capacity','starts_on','ends_on','monthly_fee'] as $field) {
            $value = $group->{$field};
            $this->{$field} = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
        }
        $this->is_active = $group->is_active;
        $map = (array) AppSetting::groupValues('general')->get('student_dashboard_card_templates', []);
        $this->dashboard_card_template_id = (string) ($map[(string) $group->id] ?? '');
        $this->showEditModal = true;
    }

    public function updatedCourseId(): void
    {
        if ($this->curriculum_id && ! Curriculum::query()->whereKey($this->curriculum_id)->where('course_id', $this->course_id)->exists()) {
            $this->curriculum_id = '';
        }
    }

    public function saveGroup(): void
    {
        $this->authorizePermission('groups.update');
        $data = $this->validate([
            'name' => ['required','string','max:255'], 'course_id' => ['required','integer','exists:courses,id'],
            'academic_year_id' => ['required','integer','exists:academic_years,id'], 'teacher_id' => ['nullable','integer','exists:teachers,id'],
            'assistant_teacher_id' => ['nullable','integer','different:teacher_id','exists:teachers,id'], 'grade_level_id' => ['nullable','integer','exists:grade_levels,id'],
            'curriculum_id' => ['nullable','integer', Rule::exists('curricula', 'id')->where(fn ($query) => $query->where('course_id', $this->course_id)->where('is_active', true))],
            'capacity' => ['nullable','integer','min:0'], 'starts_on' => ['nullable','date'], 'ends_on' => ['nullable','date','after_or_equal:starts_on'],
            'monthly_fee' => ['nullable','numeric','min:0'], 'is_active' => ['boolean'],
            'dashboard_card_template_id' => ['nullable','integer', Rule::exists('print_templates', 'id')->where(fn ($query) => $query->where('is_active', true))],
        ]);
        $templateId = $data['dashboard_card_template_id'] ?: null;
        unset($data['dashboard_card_template_id']);
        foreach (['teacher_id','assistant_teacher_id','grade_level_id','curriculum_id','capacity','starts_on','ends_on','monthly_fee'] as $field) $data[$field] = filled($data[$field]) ? $data[$field] : null;
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
        DB::transaction(function (): void {
            $this->currentGroup->update(['is_active' => false]);
            Enrollment::query()->where('group_id', $this->currentGroup->id)->where('status', 'active')->update(['status' => 'cancelled', 'left_at' => now()->toDateString()]);
        });
        session()->flash('status', __('crud.groups.messages.deactivated'));
    }

    public function closeEdit(): void { $this->showEditModal = false; $this->resetValidation(); }
    public function closeSchedules(): void { $this->showScheduleModal = false; $this->resetSchedule(); $this->resetValidation(); }
    public function closeAddStudent(): void { $this->showAddStudentModal = false; $this->roster_student_id = ''; $this->resetValidation(); }
    public function showEditModal(): void { $this->closeEdit(); }
    public function showScheduleModal(): void { $this->closeSchedules(); }
    public function showAddStudentModal(): void { $this->closeAddStudent(); }

    public function deleteGroup()
    {
        $this->authorizePermission('groups.delete');
        $group = $this->currentGroup->loadCount(['enrollments','schedules']);
        if ($group->enrollments_count || $group->schedules_count) { $this->addError('delete', __('crud.groups.errors.delete_linked')); return; }
        $group->delete();
        return $this->redirectRoute('groups.index', navigate: true);
    }

    public function addStudent(bool $addAnother = false): void
    {
        $this->authorizePermission('enrollments.create');
        $data = $this->validate(['roster_student_id' => ['required','integer','exists:students,id'], 'roster_enrolled_at' => ['required','date']]);
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
        $data = $this->validate(['day_of_week' => ['required','integer','between:0,6'], 'starts_at' => ['required','date_format:H:i'], 'ends_at' => ['required','date_format:H:i','after:starts_at'], 'room_name' => ['nullable','string','max:255'], 'schedule_is_active' => ['boolean']]);
        GroupSchedule::query()->updateOrCreate(['id' => $this->editingScheduleId, 'group_id' => $this->currentGroup->id], ['day_of_week' => $data['day_of_week'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'room_name' => $data['room_name'] ?: null, 'is_active' => $data['schedule_is_active']]);
        $this->resetSchedule();
    }

    public function editSchedule(int $id): void
    {
        $this->authorizePermission('group-schedules.manage');
        $schedule = GroupSchedule::query()->where('group_id', $this->currentGroup->id)->findOrFail($id);
        $this->editingScheduleId = $schedule->id; $this->day_of_week = (string) $schedule->day_of_week; $this->starts_at = $schedule->starts_at->format('H:i'); $this->ends_at = $schedule->ends_at->format('H:i'); $this->room_name = $schedule->room_name ?? ''; $this->schedule_is_active = $schedule->is_active;
    }

    public function deleteSchedule(int $id): void { $this->authorizePermission('group-schedules.manage'); GroupSchedule::query()->where('group_id', $this->currentGroup->id)->findOrFail($id)->delete(); $this->resetSchedule(); }
    public function resetSchedule(): void { $this->editingScheduleId = null; $this->day_of_week = '6'; $this->starts_at = $this->ends_at = $this->room_name = ''; $this->schedule_is_active = true; }

    public function copyProgress(): void
    {
        $date = $this->validate(['progressDate' => ['required','date']])['progressDate'];
        $attendance = GroupAttendanceDay::query()->where('group_id', $this->currentGroup->id)->whereDate('attendance_date', $date)->with('records.status','records.enrollment.student')->get()->flatMap->records->filter(fn ($record) => $record->status?->is_present)->map(fn ($record) => $record->enrollment?->student?->full_name)->filter()->unique()->values();
        $memorized = MemorizationSession::query()
            ->whereDate('recorded_on', $date)
            ->where('entry_type', 'new')
            ->whereHas('enrollment', fn ($q) => $q->where('group_id', $this->currentGroup->id))
            ->with(['student', 'pages'])
            ->get()
            ->groupBy('student_id')
            ->map(function ($sessions): string {
                $studentName = $sessions->first()?->student?->full_name;
                $pages = $sessions->flatMap(function (MemorizationSession $session) {
                    if ($session->pages->isNotEmpty()) return $session->pages->pluck('page_no');
                    if (filled($session->from_page) && filled($session->to_page)) return range(min($session->from_page, $session->to_page), max($session->from_page, $session->to_page));
                    return [];
                })->map(fn ($page) => (int) $page)->filter()->unique()->sort()->values()->all();
                $pageLabel = $this->formatPageRanges($pages);

                return trim(($studentName ?? '').($pageLabel !== '' ? ' '.$pageLabel : ''));
            })->filter()->values();
        $lines = [$this->currentGroup->name, $date, '', 'الحضور:', ...($attendance->all() ?: ['-']), '', 'التسميع:', ...($memorized->all() ?: ['-']), '', 'تقبل الله منا ومنكم'];
        $this->dispatch('admin-copy-text', text: implode(PHP_EOL, $lines));
    }
    protected function availableTeachersQuery()
    {
        return $this->scopeTeachersQuery(
            Teacher::query()
                ->where('status', 'active')
                ->where('is_helping', true)
                ->whereDoesntHave('assignedGroups', fn ($query) => $query->whereKeyNot($this->currentGroup->id))
                ->whereDoesntHave('assistedGroups', fn ($query) => $query->whereKeyNot($this->currentGroup->id))
        );
    }

    protected function teacherIsAvailable(int $teacherId): bool
    {
        return $this->availableTeachersQuery()->whereKey($teacherId)->exists();
    }

    protected function formatPageRanges(array $pages): string
    {
        if ($pages === []) return '';
        $ranges = [];
        $start = $end = array_shift($pages);
        foreach ($pages as $page) {
            if ($page === $end + 1) { $end = $page; continue; }
            $ranges[] = $start === $end ? (string) $start : $start.'-'.$end;
            $start = $end = $page;
        }
        $ranges[] = $start === $end ? (string) $start : $start.'-'.$end;

        return implode(', ', $ranges);
    }
}; ?>

@php
    $teacherName = $groupRecord->teacher ? trim($groupRecord->teacher->first_name.' '.$groupRecord->teacher->last_name) : __('crud.common.not_available');
    $assistantName = $groupRecord->assistantTeacher ? trim($groupRecord->assistantTeacher->first_name.' '.$groupRecord->assistantTeacher->last_name) : __('crud.common.not_available');
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8"><div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between"><div><a href="{{ route('groups.index') }}" wire:navigate class="text-sm text-neutral-300">← {{ __('crud.common.actions.back') }}</a><h1 class="font-display mt-4 text-4xl text-white md:text-5xl">{{ $groupRecord->name }}</h1><div class="mt-3 space-y-1 text-sm text-neutral-300"><div>{{ __('crud.groups.table.headers.teacher') }}: {{ $teacherName }}</div><div>{{ __('crud.groups.form.fields.assistant_teacher') }}: {{ $assistantName }}</div><div>{{ __('crud.groups.table.headers.course') }}: {{ $groupRecord->course?->name ?: __('crud.common.not_available') }}</div></div></div><div class="surface-panel flex flex-wrap gap-2 p-4">@can('groups.update')<button wire:click="openEdit" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button><button wire:click="$set('showScheduleModal', true)" class="pill-link pill-link--compact">{{ __('crud.groups.actions.schedule') }}</button><button wire:click="deactivate" wire:confirm="{{ __('crud.common.confirm_deactivate.message') }}" class="pill-link pill-link--compact">{{ __('crud.common.actions.deactivate') }}</button>@endcan @can('groups.delete')<button wire:click="deleteGroup" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/30 text-red-200">{{ __('crud.common.actions.delete') }}</button>@endcan</div></div></section>
    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif @error('delete')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror
    <section class="admin-kpi-grid"><article class="stat-card"><div class="kpi-label">{{ __('crud.groups.form.fields.capacity') }}</div><div class="metric-value mt-3">{{ $groupRecord->capacity ?: '—' }}</div></article><article class="stat-card"><div class="kpi-label">{{ __('crud.groups.table.headers.students') }}</div><div class="metric-value mt-3">{{ number_format($groupRecord->active_students_count) }}</div></article><article class="stat-card"><div class="kpi-label">{{ __('crud.groups.form.fields.grade_level') }}</div><div class="metric-value mt-3 text-2xl">{{ $groupRecord->gradeLevel?->name ?: '—' }}</div></article></section>
    <section class="surface-panel overflow-hidden"><div class="admin-toolbar p-5"><div><div class="admin-toolbar__title">{{ __('crud.groups.roster.title') }}</div></div><div class="admin-toolbar__actions"><a target="_blank" href="{{ route('groups.roster.pdf', $groupRecord) }}" class="pill-link pill-link--compact">PDF</a>@can('enrollments.create')<button wire:click="$set('showAddStudentModal', true)" class="pill-link pill-link--accent pill-link--compact">{{ __('crud.groups.roster.add_student') }}</button>@endcan</div></div><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="px-4 py-3">#</th><th class="px-4 py-3">{{ __('crud.students.table.headers.name') }}</th><th class="px-4 py-3">{{ __('crud.students.table.headers.student_number') }}</th><th class="px-4 py-3">{{ __('crud.students.table.headers.grade') }}</th><th class="px-4 py-3">{{ __('crud.groups.roster.fields.enrolled_at') }}</th></tr></thead><tbody class="divide-y divide-white/5">@forelse($roster as $enrollment)<tr><td class="px-4 py-3">{{ $roster->firstItem()+$loop->index }}</td><td class="px-4 py-3 text-white">{{ $enrollment->student?->full_name }}</td><td class="px-4 py-3">{{ $enrollment->student?->student_number }}</td><td class="px-4 py-3">{{ $enrollment->student?->gradeLevel?->name ?: '—' }}</td><td class="px-4 py-3">{{ $enrollment->enrolled_at?->format('Y-m-d') }}</td></tr>@empty<tr><td colspan="5" class="admin-empty-state">{{ __('crud.groups.roster.empty') }}</td></tr>@endforelse</tbody></table></div>@if($roster->hasPages())<div class="border-t border-white/10 p-4">{{ $roster->links() }}</div>@endif</section>
    <section class="surface-panel p-5"><div class="flex flex-wrap items-end gap-3"><div><label class="mb-1 block text-sm">{{ __('crud.common.fields.date') }}</label><input wire:model="progressDate" type="date" class="rounded-xl px-4 py-2"></div><button wire:click="copyProgress" class="pill-link pill-link--accent">{{ app()->isLocale('ar') ? 'نسخ' : 'Copy' }} ({{ $progressDate }})</button></div></section>

    <x-admin.modal :show="$showAddStudentModal" :title="__('crud.groups.roster.add_student')" close-method="showAddStudentModal" max-width="2xl"><div class="space-y-4"><div><label class="mb-1 block text-sm">{{ __('crud.students.table.headers.name') }}</label><select wire:model="roster_student_id" class="w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.common.select') }}</option>@foreach($availableStudents as $student)<option value="{{ $student->id }}">{{ $student->full_name }}</option>@endforeach</select>@error('roster_student_id')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div><div><label class="mb-1 block text-sm">{{ __('crud.groups.roster.fields.enrolled_at') }}</label><input wire:model="roster_enrolled_at" type="date" class="w-full rounded-xl px-4 py-3"></div><div class="flex gap-2"><button wire:click="addStudent(false)" class="pill-link pill-link--accent">{{ __('crud.groups.roster.add_student') }}</button><button wire:click="addStudent(true)" class="pill-link">{{ __('crud.common.actions.add_and_new') }}</button></div></div></x-admin.modal>

    <x-admin.modal :show="$showScheduleModal" :title="__('crud.groups.actions.schedule')" close-method="showScheduleModal" max-width="6xl"><form wire:submit="saveSchedule" class="grid gap-3 md:grid-cols-4"><select wire:model="day_of_week" class="rounded-xl px-3 py-2">@foreach($days as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><label class="text-sm">{{ __('schedules.group.form.fields.from') }}<input wire:model="starts_at" type="time" class="mt-1 w-full rounded-xl px-3 py-2"></label><label class="text-sm">{{ __('schedules.group.form.fields.to') }}<input wire:model="ends_at" type="time" class="mt-1 w-full rounded-xl px-3 py-2"></label><button class="pill-link pill-link--accent">{{ $editingScheduleId ? __('crud.common.actions.update') : __('crud.common.actions.create') }}</button></form><div class="mt-6 overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="p-3">{{ __('schedules.group.form.fields.day') }}</th><th class="p-3">{{ __('schedules.group.form.fields.starts_at') }}</th><th class="p-3">{{ __('schedules.group.form.fields.ends_at') }}</th><th class="p-3"></th></tr></thead><tbody>@foreach($schedules as $schedule)<tr><td class="p-3">{{ $days[$schedule->day_of_week] }}</td><td class="p-3">{{ $schedule->starts_at->format('H:i') }}</td><td class="p-3">{{ $schedule->ends_at->format('H:i') }}</td><td class="p-3 text-end"><button wire:click="editSchedule({{ $schedule->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button><button wire:click="deleteSchedule({{ $schedule->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact">{{ __('crud.common.actions.delete') }}</button></td></tr>@endforeach</tbody></table></div></x-admin.modal>

    <x-admin.modal :show="$showEditModal" :title="__('crud.groups.form.edit_title')" close-method="showEditModal" max-width="5xl">
        <form wire:submit="saveGroup" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">{{ __('crud.groups.form.fields.name') }}<input wire:model="name" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.course') }}<select wire:model.live="course_id" class="mt-1 w-full rounded-xl px-4 py-3">@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.academic_year') }}<select wire:model="academic_year_id" class="mt-1 w-full rounded-xl px-4 py-3">@foreach($academicYears as $year)<option value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.grade_level') }}<select wire:model="grade_level_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">—</option>@foreach($gradeLevels as $grade)<option value="{{ $grade->id }}">{{ $grade->name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.teacher') }}<select wire:model="teacher_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">—</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.assistant_teacher') }}<select wire:model="assistant_teacher_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">—</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('curricula.fields.curriculum') }}<select wire:model="curriculum_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('curricula.options.no_curriculum') }}</option>@foreach($curricula as $curriculum)<option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>@endforeach</select></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.capacity') }}<input wire:model="capacity" type="number" min="0" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.monthly_fee') }}<input wire:model="monthly_fee" type="number" step="0.01" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.starts_on') }}<input wire:model="starts_on" type="date" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                <label class="block text-sm">{{ __('crud.groups.form.fields.ends_on') }}<input wire:model="ends_on" type="date" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                <label class="block text-sm">{{ __('crud.groups.dashboard_card.fields.template') }}<select wire:model="dashboard_card_template_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.groups.dashboard_card.placeholders.none') }}</option>@foreach($dashboardCardTemplates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select></label>
            </div>
            <div class="flex gap-2"><button class="pill-link pill-link--accent">{{ __('crud.common.actions.update') }}</button><button type="button" wire:click="$set('showEditModal', false)" class="pill-link">{{ __('crud.common.actions.close') }}</button></div>
        </form>
    </x-admin.modal>
</div>
