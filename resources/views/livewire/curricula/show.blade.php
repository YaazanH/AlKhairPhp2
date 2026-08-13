<?php

use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumLessonTopic;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectDefinition;
use App\Services\CurriculumAccessService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public Curriculum $curriculumRecord;
    public bool $showSubjectModal = false;
    public bool $showLessonModal = false;
    public string $subjectDefinitionId = '';
    public array $resourceIds = [];
    public ?int $lessonSubjectId = null;
    public ?int $lessonResourceId = null;
    public ?int $editingLessonId = null;
    public string $lessonName = '';
    public string $pageCount = '0';
    public int $importance = 1;
    public array $topicNames = [];
    public array $newLessonDrafts = [];
    public bool $showCurriculumModal = false;
    public string $curriculumName = '';
    public string $curriculumGradeId = '';

    public function mount(Curriculum $curriculum): void
    {
        abort_unless(app(CurriculumAccessService::class)->canManage(Auth::user()), 403);
        $this->curriculumRecord = $curriculum;
        $this->curriculumName = $curriculum->name;
        $this->curriculumGradeId = (string) ($curriculum->grade_level_id ?? '');
    }

    public function with(): array
    {
        $curriculum = Curriculum::query()->with(['course', 'gradeLevel', 'subjects.definition', 'subjects.resources', 'subjects.lessons.resource', 'subjects.lessons.topics'])->findOrFail($this->curriculumRecord->id);
        return [
            'curriculum' => $curriculum,
            'definitions' => CurriculumSubjectDefinition::query()->where('is_active', true)->whereDoesntHave('curriculumSubjects', fn ($query) => $query->where('curriculum_id', $curriculum->id))->with(['resources' => fn ($query) => $query->where('is_active', true)->orderBy('book_name')])->orderBy('name')->get(),
            'selectedDefinition' => $this->subjectDefinitionId ? CurriculumSubjectDefinition::query()->with(['resources' => fn ($query) => $query->where('is_active', true)])->find($this->subjectDefinitionId) : null,
            'lessonSubject' => $this->lessonSubjectId ? CurriculumSubject::query()->with('resources')->find($this->lessonSubjectId) : null,
            'grades' => \App\Models\GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ];
    }

    public function openSubject(): void { $this->subjectDefinitionId = ''; $this->resourceIds = []; $this->resetValidation(); $this->showSubjectModal = true; }

    public function updatedSubjectDefinitionId(): void { $this->resourceIds = []; $this->resetValidation('resourceIds'); }

    public function saveSubject(): void
    {
        $data = $this->validate(['subjectDefinitionId' => ['required', 'exists:curriculum_subject_definitions,id'], 'resourceIds' => ['array'], 'resourceIds.*' => ['integer', 'exists:curriculum_resources,id']]);
        $definition = CurriculumSubjectDefinition::query()->with(['resources' => fn ($query) => $query->where('is_active', true)])->findOrFail($data['subjectDefinitionId']);
        $validResources = $definition->resources->whereIn('id', $data['resourceIds'])->pluck('id');
        if ($definition->resources->isNotEmpty() && $validResources->isEmpty()) {
            $this->addError('resourceIds', __('curricula.errors.resource_required'));
            return;
        }
        $subject = CurriculumSubject::query()->create(['curriculum_id' => $this->curriculumRecord->id, 'subject_definition_id' => $data['subjectDefinitionId'], 'sort_order' => (int) CurriculumSubject::query()->where('curriculum_id', $this->curriculumRecord->id)->max('sort_order') + 10]);
        $subject->resources()->sync($validResources);
        $this->showSubjectModal = false;
        session()->flash('status', __('curricula.messages.subject_added'));
    }

    public function deleteSubject(int $id): void
    {
        CurriculumSubject::query()->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($id)->delete();
    }

    public function openLesson(int $subjectId, ?int $lessonId = null, ?int $resourceId = null): void
    {
        CurriculumSubject::query()->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($subjectId);
        $lesson = $lessonId ? CurriculumLesson::query()->where('curriculum_subject_id', $subjectId)->findOrFail($lessonId) : null;
        $this->lessonSubjectId = $subjectId; $this->editingLessonId = $lessonId; $this->lessonResourceId = $lesson?->curriculum_resource_id ?? $resourceId;
        $this->lessonName = $lesson?->name ?? ''; $this->pageCount = (string) ($lesson?->page_count ?? 0); $this->importance = $lesson?->importance ?? 1;
        $this->resetValidation(); $this->showLessonModal = true;
    }

    public function saveLesson(): void
    {
        $data = $this->validate(['lessonSubjectId' => ['required', 'exists:curriculum_subjects,id'], 'lessonName' => ['required', 'string', 'max:255'], 'pageCount' => ['required', 'integer', 'min:0'], 'importance' => ['required', 'integer', 'between:1,3']]);
        $subject = CurriculumSubject::query()->with('resources')->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($data['lessonSubjectId']);
        $resourceId = null;
        if ($subject->resources->count() > 1) {
            $resourceId = $subject->resources->where('id', $this->lessonResourceId)->value('id');
            if (! $resourceId) { $this->addError('lessonResourceId', __('curricula.errors.lesson_resource_required')); return; }
        }
        CurriculumLesson::query()->updateOrCreate(['id' => $this->editingLessonId], ['curriculum_subject_id' => $subject->id, 'curriculum_resource_id' => $resourceId, 'name' => $data['lessonName'], 'page_count' => $data['pageCount'], 'importance' => $data['importance'], 'sort_order' => $this->editingLessonId ? CurriculumLesson::query()->findOrFail($this->editingLessonId)->sort_order : ((int) $subject->lessons()->max('sort_order') + 10)]);
        $this->showLessonModal = false;
        session()->flash('status', __('curricula.messages.lesson_saved'));
    }

    public function deleteLesson(int $id): void
    {
        CurriculumLesson::query()->whereHas('subject', fn ($query) => $query->where('curriculum_id', $this->curriculumRecord->id))->findOrFail($id)->delete();
    }

    public function addTopic(int $lessonId): void
    {
        $lesson = CurriculumLesson::query()->whereHas('subject', fn ($query) => $query->where('curriculum_id', $this->curriculumRecord->id))->findOrFail($lessonId);
        if (blank($this->topicNames[$lessonId] ?? null)) return;
        $data = $this->validate(["topicNames.{$lessonId}" => ['required', 'string', 'max:255']]);
        CurriculumLessonTopic::query()->create(['curriculum_lesson_id' => $lesson->id, 'name' => $data['topicNames'][$lessonId], 'sort_order' => ((int) $lesson->topics()->max('sort_order')) + 10]);
        $this->topicNames[$lessonId] = '';
        $this->resetValidation("topicNames.{$lessonId}");
    }

    public function saveInlineLesson(int $subjectId, int $resourceId = 0): void
    {
        $subject = CurriculumSubject::query()->with('resources')->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($subjectId);
        $draft = $this->newLessonDrafts[$subjectId][$resourceId] ?? [];
        if (blank($draft['name'] ?? null)) return;

        $path = "newLessonDrafts.{$subjectId}.{$resourceId}";
        $data = $this->validate([
            "{$path}.name" => ['required', 'string', 'max:255'],
            "{$path}.page_count" => ['nullable', 'integer', 'min:0'],
            "{$path}.importance" => ['nullable', 'integer', 'between:1,3'],
        ]);
        $lesson = $data['newLessonDrafts'][$subjectId][$resourceId];
        $validResourceId = $resourceId > 0 ? $subject->resources->where('id', $resourceId)->value('id') : null;
        if ($resourceId > 0 && ! $validResourceId) abort(422);

        CurriculumLesson::query()->create([
            'curriculum_subject_id' => $subject->id,
            'curriculum_resource_id' => $validResourceId,
            'name' => $lesson['name'],
            'page_count' => $lesson['page_count'] ?? 0,
            'importance' => $lesson['importance'] ?? 1,
            'sort_order' => ((int) $subject->lessons()->max('sort_order')) + 10,
        ]);
        unset($this->newLessonDrafts[$subjectId][$resourceId]);
        $this->resetValidation($path);
    }

    public function deleteTopic(int $topicId): void
    {
        CurriculumLessonTopic::query()->whereHas('lesson.subject', fn ($query) => $query->where('curriculum_id', $this->curriculumRecord->id))->findOrFail($topicId)->delete();
    }

    public function saveCurriculum(): void
    {
        $data = $this->validate(['curriculumName' => ['required', 'string', 'max:255'], 'curriculumGradeId' => ['nullable', 'exists:grade_levels,id']]);
        $this->curriculumRecord->update(['name' => $data['curriculumName'], 'grade_level_id' => $data['curriculumGradeId'] ?: null]);
        $this->showCurriculumModal = false;
    }

    public function deleteCurriculum()
    {
        if ($this->curriculumRecord->groups()->exists()) { $this->addError('delete', __('curricula.errors.curriculum_used')); return null; }
        $this->curriculumRecord->delete();
        return $this->redirectRoute('curricula.index', navigate: true);
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8"><div class="flex flex-wrap items-start justify-between gap-4"><div><a href="{{ route('curricula.index') }}" wire:navigate class="text-sm text-neutral-300">← {{ __('crud.common.actions.back') }}</a><h1 class="font-display mt-4 text-4xl text-white">{{ $curriculum->name }}</h1><div class="mt-3 flex flex-wrap gap-2"><span class="badge-soft">{{ $curriculum->gradeLevel?->name ?: __('curricula.options.all_grades') }}</span><span class="badge-soft">{{ $curriculum->subjects->sum(fn ($subject) => $subject->lessons->count()) }} {{ __('curricula.fields.lessons') }}</span></div></div><div class="flex flex-wrap gap-2"><button wire:click="openSubject" class="pill-link pill-link--accent">{{ __('curricula.actions.add_subject') }}</button><button wire:click="$set('showCurriculumModal', true)" class="pill-link">{{ __('curricula.actions.edit') }}</button><button wire:click="deleteCurriculum" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--danger">{{ __('curricula.actions.delete') }}</button></div></div></section>
    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    @error('delete')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror
    <x-admin.modal :show="$showCurriculumModal" :title="__('curricula.form.curriculum_title')" close-method="$set('showCurriculumModal', false)" max-width="2xl"><form wire:submit="saveCurriculum" class="space-y-4"><label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="curriculumName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.grade') }}<select wire:model="curriculumGradeId" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('curricula.options.all_grades') }}</option>@foreach($grades as $grade)<option value="{{ $grade->id }}">{{ $grade->name }}</option>@endforeach</select></label><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></form></x-admin.modal>
    <section class="grid gap-4">
        @forelse($curriculum->subjects as $subject)
            <details class="surface-panel p-5" open>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                    <div><span class="text-lg font-semibold text-white">{{ $subject->definition->name }}</span><span class="ms-3 text-xs text-neutral-400">{{ $subject->lessons->count() }} {{ __('curricula.fields.lessons') }}</span>@if($subject->resources->isNotEmpty())<div class="mt-1 text-xs text-neutral-400">{{ $subject->resources->pluck('book_name')->implode(' · ') }}</div>@endif</div>
                    <div class="flex gap-2" onclick="event.preventDefault()"><button wire:click="deleteSubject({{ $subject->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200">{{ __('curricula.actions.delete') }}</button></div>
                </summary>
                @php($resourceGroups = $subject->resources->count() > 1 ? collect($subject->resources->all())->when($subject->lessons->whereNull('curriculum_resource_id')->isNotEmpty(), fn ($resources) => $resources->prepend(null)) : collect([null]))
                <div class="mt-4 grid gap-4">
                    @foreach($resourceGroups as $resource)
                        @php($lessons = $resource ? $subject->lessons->where('curriculum_resource_id', $resource->id) : $subject->lessons)
                        <div class="rounded-2xl border border-white/10 p-3">
                            @if($resource)<div class="mb-3 font-semibold text-emerald-100">{{ $resource->book_name }}</div>@elseif($subject->resources->count() > 1)<div class="mb-3 font-semibold text-neutral-300">{{ __('curricula.fields.general_lessons') }}</div>@endif
                            <div class="grid gap-3">
                                @forelse($lessons as $lesson)
                                    <article class="rounded-xl bg-white/5 p-3">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div><div class="font-medium text-white">{{ $lesson->name }}</div><div class="mt-1 flex items-center gap-3 text-xs text-neutral-400"><span>{{ $lesson->page_count }} {{ __('curricula.fields.pages_short') }}</span><span class="inline-flex items-end gap-1" dir="ltr" title="{{ $lesson->importance }} / 3">@foreach(range(1,3) as $bar)<i class="w-1.5 rounded-sm {{ $bar <= $lesson->importance ? 'bg-emerald-300' : 'bg-white/15' }}" style="height: {{ 5 + ($bar * 4) }}px"></i>@endforeach</span></div></div>
                                            <div class="flex gap-2"><button wire:click="openLesson({{ $subject->id }}, {{ $lesson->id }})" class="pill-link pill-link--compact">{{ __('curricula.actions.edit') }}</button><button wire:click="deleteLesson({{ $lesson->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200">{{ __('curricula.actions.delete') }}</button></div>
                                        </div>
                                        <div class="mt-3 ms-3 grid gap-2 border-s border-white/10 ps-3">
                                            @foreach($lesson->topics as $topic)<div class="flex items-center justify-between gap-3 rounded-lg bg-black/10 px-3 py-2 text-sm"><span>{{ $topic->name }}</span><button wire:click="deleteTopic({{ $topic->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="text-xs text-red-200">{{ __('curricula.actions.delete') }}</button></div>@endforeach
                                            <div x-data @focusout="if (!$el.contains($event.relatedTarget)) $nextTick(() => $wire.addTopic({{ $lesson->id }}))"><input wire:model="topicNames.{{ $lesson->id }}" placeholder="{{ __('curricula.fields.topic_name') }}" class="w-full rounded-lg px-3 py-2 text-sm"></div>
                                            @error('topicNames.'.$lesson->id)<div class="text-xs text-red-400">{{ $message }}</div>@enderror
                                        </div>
                                    </article>
                                @empty<div class="admin-empty-state">{{ __('curricula.table.no_lessons') }}</div>@endforelse
                                @php($draftResourceId = $resource?->id ?? 0)
                                <div x-data @focusout="if (!$el.contains($event.relatedTarget)) $nextTick(() => $wire.saveInlineLesson({{ $subject->id }}, {{ $draftResourceId }}))" class="grid gap-2 rounded-xl border border-dashed border-emerald-300/25 bg-emerald-500/5 p-3 md:grid-cols-[minmax(0,1fr)_7rem_auto] md:items-end">
                                    <label class="text-xs text-neutral-400">{{ __('curricula.fields.name') }}<input wire:model="newLessonDrafts.{{ $subject->id }}.{{ $draftResourceId }}.name" class="mt-1 w-full rounded-lg px-3 py-2 text-sm" placeholder="{{ __('curricula.actions.add_lesson') }}"></label>
                                    <label class="text-xs text-neutral-400">{{ __('curricula.fields.page_count') }}<input wire:model="newLessonDrafts.{{ $subject->id }}.{{ $draftResourceId }}.page_count" type="number" min="0" class="mt-1 w-full rounded-lg px-3 py-2 text-sm" placeholder="0"></label>
                                    <div><div class="mb-1 text-xs text-neutral-400">{{ __('curricula.fields.importance') }}</div><div class="inline-flex overflow-hidden rounded-lg border border-white/10" dir="ltr">@foreach(range(1,3) as $level)<button type="button" wire:click="$set('newLessonDrafts.{{ $subject->id }}.{{ $draftResourceId }}.importance', {{ $level }})" class="flex items-end gap-1 px-3 py-2">@foreach(range(1,3) as $bar)<i class="w-1 rounded-sm {{ $bar <= $level ? 'bg-emerald-300' : 'bg-white/15' }}" style="height: {{ 4 + ($bar * 3) }}px"></i>@endforeach</button>@endforeach</div></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @empty<div class="surface-panel admin-empty-state">{{ __('curricula.fields.subjects') }} — {{ __('crud.common.not_available') }}</div>@endforelse
    </section>

    <x-admin.modal :show="$showSubjectModal" :title="__('curricula.form.subject_title')" close-method="$set('showSubjectModal', false)" max-width="3xl"><form wire:submit="saveSubject" class="space-y-4"><label class="block text-sm">{{ __('curricula.fields.subject') }}<select wire:model.live="subjectDefinitionId" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.common.select') }}</option>@foreach($definitions as $definition)<option value="{{ $definition->id }}">{{ $definition->name }}</option>@endforeach</select></label>@if($selectedDefinition?->resources->isNotEmpty())<div><div class="mb-2 text-sm">{{ __('curricula.fields.resources') }}</div><div class="grid gap-2 sm:grid-cols-2">@foreach($selectedDefinition->resources as $resource)<label class="flex items-center gap-2 rounded-xl border border-white/10 p-3 text-sm"><input type="checkbox" wire:model="resourceIds" value="{{ $resource->id }}"><span>{{ $resource->book_name }}</span></label>@endforeach</div>@error('resourceIds')<div class="mt-2 text-sm text-red-400">{{ $message }}</div>@enderror</div>@endif<button class="pill-link pill-link--accent">{{ __('curricula.actions.add_subject') }}</button></form></x-admin.modal>
    <x-admin.modal :show="$showLessonModal" :title="__('curricula.form.lesson_title')" close-method="$set('showLessonModal', false)" max-width="3xl"><form wire:submit="saveLesson" class="grid gap-4 md:grid-cols-2"><label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="lessonName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.page_count') }}<input wire:model="pageCount" type="number" min="0" class="mt-1 w-full rounded-xl px-4 py-3"></label>@if($lessonSubject?->resources->count() > 1)<label class="block text-sm md:col-span-2">{{ __('curricula.fields.resource') }}<select wire:model="lessonResourceId" class="mt-1 w-full rounded-xl px-4 py-3">@foreach($lessonSubject->resources as $resource)<option value="{{ $resource->id }}">{{ $resource->book_name }}</option>@endforeach</select>@error('lessonResourceId')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</label>@endif<div class="md:col-span-2"><div class="mb-2 text-sm">{{ __('curricula.fields.importance') }}</div><div class="inline-flex overflow-hidden rounded-xl border border-white/10" dir="ltr">@foreach(range(1,3) as $level)<button type="button" wire:click="$set('importance', {{ $level }})" class="flex items-end gap-1 px-5 py-3 {{ $importance === $level ? 'bg-emerald-400/20 text-emerald-100' : 'bg-white/5' }}">@foreach(range(1,3) as $bar)<i class="w-1.5 rounded-sm {{ $bar <= $level ? 'bg-current' : 'bg-white/15' }}" style="height: {{ 5 + ($bar * 4) }}px"></i>@endforeach</button>@endforeach</div></div><div class="md:col-span-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
</div>
