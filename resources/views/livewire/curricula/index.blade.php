<?php

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupCurriculumLessonProgress;
use App\Models\GroupCustomCurriculumLesson;
use App\Services\CurriculumAccessService;
use App\Services\CurriculumProgressService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $courseId = '';
    public string $selectedGroupId = '';
    public ?int $detailsGroupId = null;
    public bool $showCurriculumModal = false;
    public ?int $editingCurriculumId = null;
    public string $curriculumName = '';
    public string $curriculumGradeId = '';
    public bool $showProgressModal = false;
    public ?int $progressLessonId = null;
    public string $progressDate = '';
    public string $progressStatus = 'taught';
    public bool $showCustomModal = false;
    public ?int $editingCustomId = null;
    public string $customSubjectName = '';
    public string $customLessonName = '';
    public string $customPageCount = '0';
    public int $customImportance = 1;
    public string $customDate = '';
    public string $customStatus = 'taught';

    public function mount(): void
    {
        $access = app(CurriculumAccessService::class);
        abort_unless($access->canView(Auth::user()), 403);
        $defaultCourse = Course::query()->where('is_default', true)->where('is_active', true)->first() ?: Course::query()->where('is_active', true)->first();
        $this->courseId = (string) ($defaultCourse?->id ?? '');
        if (! $access->canManage(Auth::user())) {
            $this->selectedGroupId = (string) ($access->groupsQuery(Auth::user())->where('is_active', true)->whereNotNull('curriculum_id')->value('id') ?? '');
        }
        $this->progressDate = $this->customDate = now()->toDateString();
    }

    public function with(): array
    {
        $access = app(CurriculumAccessService::class);
        $progressService = app(CurriculumProgressService::class);
        $isManager = $access->canManage(Auth::user());
        $groupsQuery = $access->groupsQuery(Auth::user())->where('is_active', true)->whereNotNull('curriculum_id');

        if ($isManager) {
            $groups = $groupsQuery->when($this->courseId, fn ($query) => $query->where('course_id', $this->courseId))->with(['curriculum.subjects.lessons', 'curriculumProgresses', 'customCurriculumLessons'])->orderBy('name')->get();
            $groupProgress = $groups->map(fn (Group $group) => ['group' => $group, ...$progressService->summary($group)]);
            $curricula = Curriculum::query()->when($this->courseId, fn ($query) => $query->where('course_id', $this->courseId))->with(['gradeLevel', 'subjects.definition', 'subjects.lessons'])->withCount('groups')->orderBy('name')->get();
            $selectedGroup = $this->detailsGroupId ? $groups->firstWhere('id', $this->detailsGroupId) : null;
        } else {
            $availableGroups = $groupsQuery->with('course')->orderBy('name')->get();
            if ($this->selectedGroupId === '' && $availableGroups->isNotEmpty()) $this->selectedGroupId = (string) $availableGroups->first()->id;
            $selectedGroup = $availableGroups->firstWhere('id', (int) $this->selectedGroupId);
            if ($selectedGroup) $selectedGroup->load(['curriculum.subjects.definition', 'curriculum.subjects.resources', 'curriculum.subjects.lessons', 'curriculumProgresses.teacher', 'customCurriculumLessons.teacher']);
            $groups = $availableGroups; $groupProgress = collect(); $curricula = collect();
        }

        $selectedSummary = $selectedGroup ? $progressService->summary($selectedGroup) : ['total' => 0, 'completed' => 0, 'percentage' => 0];
        $subjectRows = $selectedGroup ? $progressService->subjects($selectedGroup) : collect();

        return [
            'isManager' => $isManager,
            'courses' => Course::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'grades' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'curricula' => $curricula, 'groups' => $groups, 'groupProgress' => $groupProgress,
            'selectedGroup' => $selectedGroup, 'selectedSummary' => $selectedSummary, 'subjectRows' => $subjectRows,
            'latestLessons' => $selectedGroup ? $this->latestLessons($selectedGroup) : collect(),
        ];
    }

    public function openCurriculum(?int $id = null): void
    {
        abort_unless(app(CurriculumAccessService::class)->canManage(Auth::user()), 403);
        $curriculum = $id ? Curriculum::query()->findOrFail($id) : null;
        $this->editingCurriculumId = $id; $this->curriculumName = $curriculum?->name ?? '';
        $this->curriculumGradeId = (string) ($curriculum?->grade_level_id ?? '');
        if ($curriculum) $this->courseId = (string) $curriculum->course_id;
        $this->resetValidation(); $this->showCurriculumModal = true;
    }

    public function saveCurriculum()
    {
        abort_unless(app(CurriculumAccessService::class)->canManage(Auth::user()), 403);
        $data = $this->validate([
            'courseId' => ['required', 'exists:courses,id'], 'curriculumName' => ['required', 'string', 'max:255'],
            'curriculumGradeId' => ['nullable', 'exists:grade_levels,id'],
        ]);
        $curriculum = Curriculum::query()->updateOrCreate(['id' => $this->editingCurriculumId], ['course_id' => $data['courseId'], 'grade_level_id' => $data['curriculumGradeId'] ?: null, 'name' => $data['curriculumName'], 'is_active' => true]);
        $wasNew = ! $this->editingCurriculumId;
        $this->showCurriculumModal = false;
        if ($wasNew) return $this->redirectRoute('curricula.show', $curriculum, navigate: true);
        session()->flash('status', __('curricula.messages.curriculum_saved'));
    }

    public function deleteCurriculum(int $id): void
    {
        abort_unless(app(CurriculumAccessService::class)->canManage(Auth::user()), 403);
        $curriculum = Curriculum::query()->withCount('groups')->findOrFail($id);
        if ($curriculum->groups_count) { $this->addError('delete', __('curricula.errors.curriculum_used')); return; }
        $curriculum->delete();
    }

    public function showGroupDetails(int $id): void
    {
        abort_unless(app(CurriculumAccessService::class)->canManage(Auth::user()), 403);
        $this->detailsGroupId = $id;
    }

    public function openProgress(int $lessonId): void
    {
        $group = $this->teacherGroup();
        $lesson = CurriculumLesson::query()->whereHas('subject', fn ($query) => $query->where('curriculum_id', $group->curriculum_id))->findOrFail($lessonId);
        $record = GroupCurriculumLessonProgress::query()->where('group_id', $group->id)->where('curriculum_lesson_id', $lesson->id)->first();
        $this->progressLessonId = $lesson->id; $this->progressDate = $record?->taught_on?->toDateString() ?? now()->toDateString(); $this->progressStatus = $record?->status ?? 'taught';
        $this->showProgressModal = true;
    }

    public function saveProgress(): void
    {
        $group = $this->teacherGroup();
        $data = $this->validate(['progressLessonId' => ['required', 'exists:curriculum_lessons,id'], 'progressDate' => ['required', 'date'], 'progressStatus' => ['required', Rule::in(['partial', 'taught'])]]);
        CurriculumLesson::query()->whereKey($data['progressLessonId'])->whereHas('subject', fn ($query) => $query->where('curriculum_id', $group->curriculum_id))->firstOrFail();
        GroupCurriculumLessonProgress::query()->updateOrCreate(['group_id' => $group->id, 'curriculum_lesson_id' => $data['progressLessonId']], ['teacher_id' => Auth::user()->teacherProfile?->id, 'status' => $data['progressStatus'], 'taught_on' => $data['progressDate']]);
        $this->showProgressModal = false; session()->flash('status', __('curricula.messages.progress_saved'));
    }

    public function openCustom(?int $id = null): void
    {
        $group = $this->teacherGroup();
        $lesson = $id ? GroupCustomCurriculumLesson::query()->where('group_id', $group->id)->findOrFail($id) : null;
        $this->editingCustomId = $id;
        $this->customSubjectName = $lesson?->subject_name ?? ''; $this->customLessonName = $lesson?->name ?? '';
        $this->customPageCount = (string) ($lesson?->page_count ?? 0); $this->customImportance = $lesson?->importance ?? 1;
        $this->customDate = $lesson?->taught_on?->toDateString() ?? now()->toDateString(); $this->customStatus = $lesson?->status ?? 'taught'; $this->showCustomModal = true;
    }

    public function saveCustom(): void
    {
        $group = $this->teacherGroup();
        $data = $this->validate([
            'customSubjectName' => ['required', 'string', 'max:255'], 'customLessonName' => ['required', 'string', 'max:255'],
            'customPageCount' => ['required', 'integer', 'min:0'], 'customImportance' => ['required', 'integer', 'between:1,3'],
            'customDate' => ['required', 'date'], 'customStatus' => ['required', Rule::in(['partial', 'taught'])],
        ]);
        GroupCustomCurriculumLesson::query()->updateOrCreate(['id' => $this->editingCustomId, 'group_id' => $group->id], ['teacher_id' => Auth::user()->teacherProfile?->id, 'subject_name' => $data['customSubjectName'], 'name' => $data['customLessonName'], 'page_count' => $data['customPageCount'], 'importance' => $data['customImportance'], 'taught_on' => $data['customDate'], 'status' => $data['customStatus']]);
        $this->showCustomModal = false; session()->flash('status', __('curricula.messages.custom_saved'));
    }

    protected function teacherGroup(): Group
    {
        abort_unless(app(CurriculumAccessService::class)->isGroupSupervisor(Auth::user()), 403);
        return app(CurriculumAccessService::class)->groupsQuery(Auth::user())->whereKey($this->selectedGroupId)->whereNotNull('curriculum_id')->firstOrFail();
    }

    protected function latestLessons(Group $group)
    {
        $standard = GroupCurriculumLessonProgress::query()->where('group_id', $group->id)->with(['lesson', 'teacher'])->get()->map(fn ($row) => ['name' => $row->lesson?->name, 'date' => $row->taught_on, 'teacher' => $row->teacher]);
        $custom = GroupCustomCurriculumLesson::query()->where('group_id', $group->id)->with('teacher')->get()->map(fn ($row) => ['name' => $row->name, 'date' => $row->taught_on, 'teacher' => $row->teacher]);
        return $standard->concat($custom)->sortByDesc(fn ($row) => $row['date']?->format('Y-m-d'))->take(5)->values();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8"><div class="flex flex-wrap items-start justify-between gap-5"><div><div class="eyebrow">{{ __('curricula.title') }}</div><h1 class="font-display mt-3 text-4xl text-white">{{ __('curricula.title') }}</h1><p class="mt-3 max-w-3xl text-neutral-300">{{ $isManager ? __('curricula.subtitle') : __('curricula.teacher_subtitle') }}</p></div>@if($isManager)<div class="flex flex-wrap items-center gap-2"><select wire:model.live="courseId" class="rounded-xl px-4 py-2.5">@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select><a href="{{ route('settings.curriculum-subjects') }}" wire:navigate class="pill-link">{{ __('curricula.actions.manage_subjects') }}</a></div>@else<select wire:model.live="selectedGroupId" class="rounded-xl px-4 py-2.5">@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }} · {{ $group->course?->name }}</option>@endforeach</select>@endif</div></section>
    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif @error('delete')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror

    @if($isManager)
        <section class="surface-panel p-5"><h2 class="font-display text-2xl text-white">{{ __('curricula.progress.title') }}</h2>@if($groupProgress->isEmpty())<div class="admin-empty-state mt-4">{{ __('curricula.progress.empty') }}</div>@else<div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">@foreach($groupProgress as $row)<button wire:click="showGroupDetails({{ $row['group']->id }})" class="group text-center"><div class="mx-auto grid size-28 place-items-center rounded-full" style="background: conic-gradient(#9fbea9 {{ $row['percentage'] }}%, #c99b9b 0)"><div class="grid size-20 place-items-center rounded-full bg-neutral-950 text-lg font-semibold text-white">{{ number_format($row['percentage'], 0) }}%</div></div><div class="mt-3 truncate text-sm font-semibold text-white group-hover:text-emerald-200">{{ $row['group']->name }}</div></button>@endforeach</div>@endif</section>
        <section class="surface-table"><div class="admin-toolbar p-5"><div><div class="admin-toolbar__title">{{ __('curricula.table.curricula') }}</div></div><button wire:click="openCurriculum" class="pill-link pill-link--accent">{{ __('curricula.actions.add_curriculum') }}</button></div><div class="overflow-x-auto"><table class="w-full table-fixed text-sm"><thead><tr><th class="p-4">{{ __('curricula.fields.name') }}</th><th class="p-4">{{ __('curricula.fields.grade') }}</th><th class="p-4">{{ __('curricula.fields.subjects') }}</th><th class="p-4">{{ __('curricula.fields.lessons') }}</th><th class="p-4"></th></tr></thead><tbody>@forelse($curricula as $curriculum)<tr><td class="p-4 font-semibold text-white">{{ $curriculum->name }}</td><td class="p-4">{{ $curriculum->gradeLevel?->name ?: '—' }}</td><td class="p-4">{{ $curriculum->subjects->pluck('definition.name')->filter()->implode('، ') ?: '—' }}</td><td class="p-4">{{ $curriculum->subjects->sum(fn ($subject) => $subject->lessons->count()) }}</td><td class="p-4"><div class="flex flex-nowrap justify-end gap-2"><a href="{{ route('curricula.show', $curriculum) }}" wire:navigate class="pill-link pill-link--compact">{{ __('curricula.actions.open') }}</a><button wire:click="openCurriculum({{ $curriculum->id }})" class="pill-link pill-link--compact">{{ __('curricula.actions.edit') }}</button><button wire:click="deleteCurriculum({{ $curriculum->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200">{{ __('curricula.actions.delete') }}</button></div></td></tr>@empty<tr><td colspan="5" class="admin-empty-state">{{ __('curricula.table.empty') }}</td></tr>@endforelse</tbody></table></div></section>
    @else
        @if($selectedGroup)
            <section class="grid gap-6 xl:grid-cols-2"><article class="surface-panel grid place-items-center p-6"><h2 class="font-display text-2xl text-white">{{ __('curricula.progress.title') }}</h2><div class="mt-5 grid size-48 place-items-center rounded-full" style="background: conic-gradient(#9fbea9 {{ $selectedSummary['percentage'] }}%, #c99b9b 0)"><div class="grid size-36 place-items-center rounded-full bg-neutral-950 text-3xl font-semibold text-white">{{ number_format($selectedSummary['percentage'], 0) }}%</div></div></article><article class="surface-panel overflow-hidden"><div class="p-5"><h2 class="font-display text-2xl text-white">{{ __('curricula.table.latest') }}</h2></div><table class="w-full text-sm"><thead><tr><th class="p-3">{{ __('curricula.fields.lesson') }}</th><th class="p-3">{{ __('curricula.fields.date') }}</th><th class="p-3">{{ __('curricula.fields.teacher') }}</th></tr></thead><tbody>@forelse($latestLessons as $lesson)<tr><td class="p-3 text-white">{{ $lesson['name'] }}</td><td class="p-3">{{ $lesson['date']?->format('Y-m-d') }}</td><td class="p-3">{{ $lesson['teacher'] ? trim($lesson['teacher']->first_name.' '.$lesson['teacher']->last_name) : '—' }}</td></tr>@empty<tr><td colspan="3" class="admin-empty-state">{{ __('crud.common.not_available') }}</td></tr>@endforelse</tbody></table></article></section>
            <section class="grid gap-4">
                @foreach($subjectRows as $subject)
                    <details class="surface-panel p-5" open>
                        <summary class="flex cursor-pointer list-none items-center justify-between"><span class="font-semibold text-white">{{ $subject['name'] }}</span><span class="status-chip status-chip--emerald">{{ number_format($subject['percentage'], 0) }}%</span></summary>
                        <div class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="p-3">{{ __('curricula.fields.lesson') }}</th><th class="p-3">{{ __('curricula.fields.page_count') }}</th><th class="p-3">{{ __('curricula.fields.importance') }}</th><th class="p-3">{{ __('curricula.fields.status') }}</th><th class="p-3">{{ __('curricula.fields.date') }}</th><th class="p-3"></th></tr></thead><tbody>
                            @foreach($subject['lessons'] as $lesson)
                                <tr><td class="p-3 text-white">{{ $lesson['name'] }}</td><td class="p-3">{{ $lesson['page_count'] }}</td><td class="p-3"><span class="inline-flex items-end gap-1">@foreach(range(1,3) as $bar)<i class="w-1.5 rounded-sm {{ $bar <= $lesson['importance'] ? 'bg-emerald-300' : 'bg-white/15' }}" style="height: {{ 5 + ($bar * 4) }}px"></i>@endforeach</span></td><td class="p-3">{{ __('curricula.status.'.$lesson['status']) }}</td><td class="p-3">{{ $lesson['taught_on']?->format('Y-m-d') ?: '—' }}</td><td class="p-3 text-end">@if($lesson['status'] !== 'taught')<button wire:click="{{ $lesson['custom'] ? 'openCustom('.$lesson['id'].')' : 'openProgress('.$lesson['id'].')' }}" class="grid size-8 place-items-center rounded-full bg-emerald-400/15 text-xl text-emerald-100">+</button>@endif</td></tr>
                            @endforeach
                        </tbody></table></div>
                    </details>
                @endforeach
                <div class="flex justify-end"><button wire:click="openCustom" class="pill-link pill-link--accent">{{ __('curricula.actions.add_custom_lesson') }}</button></div>
            </section>
        @else<div class="surface-panel admin-empty-state">{{ __('curricula.errors.no_group') }}</div>@endif
    @endif

    <x-admin.modal :show="$showCurriculumModal" :title="__('curricula.form.curriculum_title')" close-method="$set('showCurriculumModal', false)" max-width="3xl"><form wire:submit="saveCurriculum" class="grid gap-4 md:grid-cols-2"><label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="curriculumName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.course') }}<select wire:model="courseId" class="mt-1 w-full rounded-xl px-4 py-3">@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></label><label class="block text-sm md:col-span-2">{{ __('curricula.fields.grade') }}<select wire:model="curriculumGradeId" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('curricula.options.all_grades') }}</option>@foreach($grades as $grade)<option value="{{ $grade->id }}">{{ $grade->name }}</option>@endforeach</select></label><div class="md:col-span-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
    <x-admin.modal :show="$detailsGroupId !== null" :title="__('curricula.progress.group_details', ['group' => $selectedGroup?->name])" close-method="$set('detailsGroupId', null)" max-width="6xl"><div class="space-y-3">@foreach($subjectRows as $subject)<details class="rounded-2xl border border-white/10 p-4"><summary class="flex cursor-pointer justify-between"><span class="font-semibold text-white">{{ $subject['name'] }}</span><span>{{ number_format($subject['percentage'], 0) }}%</span></summary><table class="mt-3 w-full text-sm"><thead><tr><th class="p-2">{{ __('curricula.fields.lesson') }}</th><th class="p-2">{{ __('curricula.fields.status') }}</th><th class="p-2">{{ __('curricula.fields.date') }}</th></tr></thead><tbody>@foreach($subject['lessons'] as $lesson)<tr><td class="p-2 text-white">{{ $lesson['name'] }}</td><td class="p-2">{{ __('curricula.status.'.$lesson['status']) }}</td><td class="p-2">{{ $lesson['taught_on']?->format('Y-m-d') ?: '—' }}</td></tr>@endforeach</tbody></table></details>@endforeach</div></x-admin.modal>
    <x-admin.modal :show="$showProgressModal" :title="__('curricula.form.progress_title')" close-method="$set('showProgressModal', false)" max-width="2xl"><form wire:submit="saveProgress" class="space-y-4"><input wire:model="progressDate" type="date" class="w-full rounded-xl px-4 py-3"><div class="grid grid-cols-2 overflow-hidden rounded-xl border border-white/10"><button type="button" wire:click="$set('progressStatus', 'taught')" class="p-3 {{ $progressStatus === 'taught' ? 'bg-emerald-400/20 text-emerald-100' : 'bg-white/5' }}">{{ __('curricula.status.taught') }}</button><button type="button" wire:click="$set('progressStatus', 'partial')" class="p-3 {{ $progressStatus === 'partial' ? 'bg-amber-400/20 text-amber-100' : 'bg-white/5' }}">{{ __('curricula.status.partial') }}</button></div><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></form></x-admin.modal>
    <x-admin.modal :show="$showCustomModal" :title="__('curricula.form.custom_title')" close-method="$set('showCustomModal', false)" max-width="3xl"><form wire:submit="saveCustom" class="grid gap-4 md:grid-cols-2"><label class="block text-sm">{{ __('curricula.fields.subject') }}<input wire:model="customSubjectName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.lesson') }}<input wire:model="customLessonName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.page_count') }}<input wire:model="customPageCount" type="number" min="0" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.date') }}<input wire:model="customDate" type="date" class="mt-1 w-full rounded-xl px-4 py-3"></label><div><div class="mb-2 text-sm">{{ __('curricula.fields.importance') }}</div><div class="inline-flex rounded-xl border border-white/10">@foreach(range(1,3) as $level)<button type="button" wire:click="$set('customImportance', {{ $level }})" class="px-4 py-3 {{ $customImportance === $level ? 'bg-emerald-400/20' : '' }}">{{ $level }}</button>@endforeach</div></div><div><div class="mb-2 text-sm">{{ __('curricula.fields.status') }}</div><div class="grid grid-cols-2 overflow-hidden rounded-xl border border-white/10"><button type="button" wire:click="$set('customStatus', 'taught')" class="p-3 {{ $customStatus === 'taught' ? 'bg-emerald-400/20' : '' }}">{{ __('curricula.status.taught') }}</button><button type="button" wire:click="$set('customStatus', 'partial')" class="p-3 {{ $customStatus === 'partial' ? 'bg-amber-400/20' : '' }}">{{ __('curricula.status.partial') }}</button></div></div><div class="md:col-span-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
</div>
