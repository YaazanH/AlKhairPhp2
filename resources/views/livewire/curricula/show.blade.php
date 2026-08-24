<?php

use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumLessonTopic;
use App\Models\CurriculumResource;
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
    public string $chapterNumber = '';
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
        $curriculum = Curriculum::query()->with(['course', 'gradeLevel', 'standaloneResources', 'subjects.definition', 'subjects.resources', 'subjects.lessons.resource', 'subjects.lessons.topics'])->findOrFail($this->curriculumRecord->id);
        return [
            'curriculum' => $curriculum,
            'definitions' => CurriculumSubjectDefinition::query()->where('is_active', true)->whereDoesntHave('curriculumSubjects', fn ($query) => $query->where('curriculum_id', $curriculum->id))->with(['resources' => fn ($query) => $query->where('is_active', true)->orderBy('book_name')])->orderBy('name')->get(),
            'selectedDefinition' => $this->subjectDefinitionId ? CurriculumSubjectDefinition::query()->with(['resources' => fn ($query) => $query->where('is_active', true)])->find($this->subjectDefinitionId) : null,
            'lessonSubject' => $this->lessonSubjectId ? CurriculumSubject::query()->with('resources')->find($this->lessonSubjectId) : null,
            'grades' => \App\Models\GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'standaloneResources' => CurriculumResource::query()->whereNull('subject_definition_id')->where('is_active', true)->orderBy('book_name')->get(),
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
        $this->chapterNumber = (string) ($lesson?->chapter_number ?? ''); $this->lessonName = $lesson?->name ?? ''; $this->importance = $lesson?->importance ?? 1;
        $this->resetValidation(); $this->showLessonModal = true;
    }

    public function saveLesson(): void
    {
        $data = $this->validate(['lessonSubjectId' => ['required', 'exists:curriculum_subjects,id'], 'chapterNumber' => ['nullable', 'string', 'max:40'], 'lessonName' => ['required', 'string', 'max:255'], 'importance' => ['required', 'integer', 'between:1,3']]);
        $subject = CurriculumSubject::query()->with('resources')->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($data['lessonSubjectId']);
        $resourceId = null;
        if ($subject->resources->count() > 1) {
            $resourceId = $subject->resources->where('id', $this->lessonResourceId)->value('id');
            if (! $resourceId) { $this->addError('lessonResourceId', __('curricula.errors.lesson_resource_required')); return; }
        }
        $lessonValues = ['curriculum_subject_id' => $subject->id, 'curriculum_resource_id' => $resourceId, 'chapter_number' => filled($data['chapterNumber'] ?? null) ? $data['chapterNumber'] : null, 'name' => $data['lessonName'], 'importance' => $data['importance'], 'sort_order' => $this->editingLessonId ? CurriculumLesson::query()->findOrFail($this->editingLessonId)->sort_order : ((int) $subject->lessons()->max('sort_order') + 10)];
        if (! $this->editingLessonId) $lessonValues['page_count'] = 0;
        CurriculumLesson::query()->updateOrCreate(['id' => $this->editingLessonId], $lessonValues);
        $this->showLessonModal = false;
        session()->flash('status', __('curricula.messages.lesson_saved'));
    }

    public function deleteLesson(int $id): void
    {
        CurriculumLesson::query()->whereHas('subject', fn ($query) => $query->where('curriculum_id', $this->curriculumRecord->id))->findOrFail($id)->delete();
        if ($this->editingLessonId === $id) {
            $this->editingLessonId = null;
            $this->showLessonModal = false;
        }
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
            "{$path}.chapter_number" => ['nullable', 'string', 'max:40'],
            "{$path}.importance" => ['nullable', 'integer', 'between:1,3'],
        ]);
        $lesson = $data['newLessonDrafts'][$subjectId][$resourceId];
        $validResourceId = $resourceId > 0 ? $subject->resources->where('id', $resourceId)->value('id') : null;
        if ($resourceId > 0 && ! $validResourceId) abort(422);

        CurriculumLesson::query()->create([
            'curriculum_subject_id' => $subject->id,
            'curriculum_resource_id' => $validResourceId,
            'chapter_number' => filled($lesson['chapter_number'] ?? null) ? $lesson['chapter_number'] : null,
            'name' => $lesson['name'],
            'page_count' => 0,
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

    public function toggleStandaloneResource(int $resourceId): void
    {
        $resource = CurriculumResource::query()->whereNull('subject_definition_id')->where('is_active', true)->findOrFail($resourceId);
        $this->curriculumRecord->standaloneResources()->toggle($resource->id);
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
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <h1 class="font-display text-4xl text-white">{{ $curriculum->name }}</h1>
            <div class="flex flex-wrap gap-2 rounded-2xl border border-white/10 bg-white/5 p-2" data-curriculum-header-actions>
                <button wire:click="openSubject" class="pill-link pill-link--accent">{{ __('curricula.actions.add_subject') }}</button>
                <button wire:click="$set('showCurriculumModal', true)" class="pill-link">{{ __('curricula.actions.edit') }}</button>
                <a href="{{ route('curricula.index') }}" wire:navigate class="pill-link border border-white/15">
                    {{ __('curricula.actions.back_to_curricula') }}
                </a>
            </div>
        </div>
    </section>
    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    @error('delete')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror
    @if($standaloneResources->isNotEmpty())<section class="surface-panel p-5"><div class="admin-toolbar__title">{{ __('curricula.fields.standalone_books') }}</div><div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach($standaloneResources as $resource)<label class="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 p-3"><input type="checkbox" wire:click="toggleStandaloneResource({{ $resource->id }})" @checked($curriculum->standaloneResources->contains($resource)) class="rounded"><span>{{ $resource->book_name }}</span></label>@endforeach</div></section>@endif
    <x-admin.modal :show="$showCurriculumModal" :title="__('curricula.form.curriculum_title')" close-method="$set('showCurriculumModal', false)" max-width="2xl">
        <form wire:submit="saveCurriculum" class="space-y-4">
            <label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="curriculumName" class="mt-1 w-full rounded-xl px-4 py-3"></label>
            <label class="block text-sm">{{ __('curricula.fields.grade') }}<select wire:model="curriculumGradeId" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('curricula.options.all_grades') }}</option>@foreach($grades as $grade)<option value="{{ $grade->id }}">{{ $grade->name }}</option>@endforeach</select></label>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button>
                <button type="button" wire:click="deleteCurriculum" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--danger">{{ __('curricula.actions.delete') }}</button>
            </div>
        </form>
    </x-admin.modal>
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
                            @php($draftResourceId = $resource?->id ?? 0)
                            @php($draftImportance = (int) data_get($newLessonDrafts, "{$subject->id}.{$draftResourceId}.importance", 1))
                            <div class="overflow-x-auto rounded-xl border border-white/8">
                                <table class="w-full min-w-[46rem] table-fixed text-sm" data-curriculum-lessons-table>
                                    <thead>
                                        <tr>
                                            <th class="w-[13%] px-3 py-3 text-start">{{ __('curricula.fields.chapter_number') }}</th>
                                            <th class="w-[39%] px-3 py-3 text-start">{{ __('curricula.fields.name') }}</th>
                                            <th class="w-[20%] px-3 py-3 text-start">{{ __('curricula.fields.importance') }}</th>
                                            <th class="w-[8%] px-3 py-3" aria-label="{{ __('curricula.fields.topics') }}"></th>
                                            <th class="w-[20%] px-3 py-3 text-end">{{ __('crud.common.actions.actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/6" x-data="{ openTopics: @js($lessons->mapWithKeys(fn ($lesson) => [$lesson->id => true])->all()) }">
                                        @forelse($lessons as $lesson)
                                            <tr wire:key="curriculum-lesson-{{ $lesson->id }}" data-curriculum-lesson-row>
                                                <td class="px-3 py-3 text-neutral-300">{{ $lesson->chapter_number ?: '—' }}</td>
                                                <td class="px-3 py-3 font-medium text-white">{{ $lesson->name }}</td>
                                                <td class="px-3 py-3">
                                                    <span class="inline-flex h-6 items-end gap-1" dir="ltr" title="{{ $lesson->importance }} / 3" data-importance-bars>
                                                        @foreach(range(1,3) as $bar)<i class="w-1.5 rounded-sm {{ $bar <= $lesson->importance ? 'bg-emerald-300' : 'bg-white/15' }}" style="height: {{ 5 + ($bar * 4) }}px"></i>@endforeach
                                                    </span>
                                                </td>
                                                <td class="px-3 py-3 text-center" data-curriculum-topics-column>
                                                    <button type="button" x-on:click="openTopics[{{ $lesson->id }}] = !openTopics[{{ $lesson->id }}]" class="inline-flex min-h-9 items-center justify-center gap-2 rounded-full px-2 text-neutral-400 transition hover:bg-white/8 hover:text-white" :aria-expanded="openTopics[{{ $lesson->id }}] ? 'true' : 'false'" aria-label="{{ __('curricula.fields.topics') }}" data-curriculum-topics-toggle data-collapsed-direction="{{ app()->getLocale() === 'ar' ? 'left' : 'right' }}">
                                                        <span x-show="!openTopics[{{ $lesson->id }}]" x-cloak class="min-w-4 text-center text-xs font-semibold" data-collapsed-topic-count>{{ $lesson->topics->count() }}</span>
                                                        <svg class="size-4 transition-transform" :class="openTopics[{{ $lesson->id }}] ? 'rotate-90' : '{{ app()->getLocale() === 'ar' ? 'rotate-180' : 'rotate-0' }}'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg>
                                                    </button>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <div class="flex flex-wrap justify-end gap-2"><button type="button" wire:click="openLesson({{ $subject->id }}, {{ $lesson->id }})" class="inline-flex size-10 items-center justify-center rounded-full border border-white/12 text-neutral-200 transition hover:border-emerald-300/35 hover:bg-emerald-400/10 hover:text-white" title="{{ __('curricula.actions.edit') }}" aria-label="{{ __('curricula.actions.edit') }}" data-edit-lesson-icon><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M18.75 2.999a1.875 1.875 0 0 1 2.652 2.652L8.582 18.47 3 21l2.53-5.582L18.75 2.999Z" /></svg></button></div>
                                                </td>
                                            </tr>
                                            @foreach($lesson->topics as $topic)
                                                <tr wire:key="curriculum-topic-{{ $topic->id }}" x-show="openTopics[{{ $lesson->id }}]" x-cloak class="bg-black/[0.075]" data-curriculum-topic-row>
                                                    <td class="px-3 py-2"></td>
                                                    <td class="px-3 py-2">
                                                        <div class="ms-7 flex items-center gap-3 border-s border-emerald-300/20 ps-3 text-sm text-neutral-300"><span class="w-5 shrink-0 text-center text-xs font-semibold text-emerald-200/70" dir="ltr" data-curriculum-topic-number>{{ $loop->iteration }}</span><span>{{ $topic->name }}</span></div>
                                                    </td>
                                                    <td class="px-3 py-2"></td>
                                                    <td class="px-3 py-2"></td>
                                                    <td class="px-3 py-2 text-end"><button type="button" wire:click="deleteTopic({{ $topic->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="inline-flex size-8 items-center justify-center rounded-full text-lg leading-none text-red-200 hover:bg-red-500/10" aria-label="{{ __('crud.common.actions.delete') }}">×</button></td>
                                                </tr>
                                            @endforeach
                                            <tr wire:key="curriculum-topic-add-{{ $lesson->id }}" x-show="openTopics[{{ $lesson->id }}]" x-cloak class="bg-black/[0.075]" data-curriculum-add-topic-row>
                                                <td class="px-3 py-2"></td>
                                                <td class="px-3 py-2"><div class="ms-7 border-s border-emerald-300/20 ps-4" x-data @focusout="if (!$el.contains($event.relatedTarget)) $nextTick(() => $wire.addTopic({{ $lesson->id }}))"><input wire:model="topicNames.{{ $lesson->id }}" wire:keydown.enter.prevent="addTopic({{ $lesson->id }})" placeholder="{{ __('curricula.fields.topic_name') }}" class="w-full rounded-lg px-2.5 py-1.5 text-xs"></div>@error('topicNames.'.$lesson->id)<div class="ms-11 mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                                <td class="px-3 py-2"></td>
                                                <td class="px-3 py-2"></td>
                                                <td class="px-3 py-2"></td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="admin-empty-state">{{ __('curricula.table.no_lessons') }}</td></tr>
                                        @endforelse
                                        <tr class="bg-emerald-500/5" data-curriculum-add-lesson-row>
                                            <td class="px-3 py-3"><input wire:model="newLessonDrafts.{{ $subject->id }}.{{ $draftResourceId }}.chapter_number" class="h-11 w-full rounded-lg px-3 text-sm" aria-label="{{ __('curricula.fields.chapter_number') }}"></td>
                                            <td class="px-3 py-3"><input wire:model="newLessonDrafts.{{ $subject->id }}.{{ $draftResourceId }}.name" class="h-11 w-full rounded-lg px-3 text-sm" placeholder="{{ __('curricula.actions.add_lesson') }}" aria-label="{{ __('curricula.fields.name') }}"></td>
                                            <td class="px-3 py-3">
                                                <div class="inline-flex h-11 overflow-hidden rounded-lg border border-white/10" dir="ltr" data-importance-bars>
                                                    @foreach(range(1,3) as $level)
                                                        <button type="button" wire:click="$set('newLessonDrafts.{{ $subject->id }}.{{ $draftResourceId }}.importance', {{ $level }})" class="flex h-full items-end gap-1 px-3 pb-2 {{ $draftImportance === $level ? 'bg-emerald-400/20 text-emerald-100' : 'bg-white/5' }}" title="{{ $level }} / 3">
                                                            @foreach(range(1,3) as $bar)<i class="w-1 rounded-sm {{ $bar <= $level ? 'bg-current' : 'bg-white/15' }}" style="height: {{ 4 + ($bar * 3) }}px"></i>@endforeach
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td class="px-3 py-3"></td>
                                            <td class="px-3 py-3 text-end"><button type="button" wire:click="saveInlineLesson({{ $subject->id }}, {{ $draftResourceId }})" class="inline-flex size-10 items-center justify-center rounded-full border border-emerald-300/25 bg-emerald-500/15 text-emerald-100 transition hover:border-emerald-300/45 hover:bg-emerald-500/25 hover:text-white" title="{{ __('curricula.actions.add_lesson') }}" aria-label="{{ __('curricula.actions.add_lesson') }}" data-add-lesson-icon><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg></button></td>
                                        </tr>
                                        @error("newLessonDrafts.{$subject->id}.{$draftResourceId}.name")<tr><td colspan="5" class="px-3 py-2 text-sm text-red-400">{{ $message }}</td></tr>@enderror
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @empty<div class="surface-panel admin-empty-state">{{ __('curricula.fields.subjects') }} — {{ __('crud.common.not_available') }}</div>@endforelse
    </section>

    <x-admin.modal :show="$showSubjectModal" :title="__('curricula.form.subject_title')" close-method="$set('showSubjectModal', false)" max-width="3xl"><form wire:submit="saveSubject" class="space-y-4"><label class="block text-sm">{{ __('curricula.fields.subject') }}<select wire:model.live="subjectDefinitionId" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.common.select') }}</option>@foreach($definitions as $definition)<option value="{{ $definition->id }}">{{ $definition->name }}</option>@endforeach</select></label>@if($selectedDefinition?->resources->isNotEmpty())<div><div class="mb-2 text-sm">{{ __('curricula.fields.resources') }}</div><div class="grid gap-2 sm:grid-cols-2">@foreach($selectedDefinition->resources as $resource)<label class="flex items-center gap-2 rounded-xl border border-white/10 p-3 text-sm"><input type="checkbox" wire:model="resourceIds" value="{{ $resource->id }}"><span>{{ $resource->book_name }}</span></label>@endforeach</div>@error('resourceIds')<div class="mt-2 text-sm text-red-400">{{ $message }}</div>@enderror</div>@endif<button class="pill-link pill-link--accent">{{ __('curricula.actions.add_subject') }}</button></form></x-admin.modal>
    <x-admin.modal :show="$showLessonModal" :title="__('curricula.form.lesson_title')" close-method="$set('showLessonModal', false)" max-width="xl" compact>
        <form wire:submit="saveLesson" class="space-y-4" data-compact-lesson-modal>
            <div class="grid gap-4 sm:grid-cols-[7rem_minmax(0,1fr)]">
                <label class="block text-sm">{{ __('curricula.fields.chapter_number') }}<input wire:model="chapterNumber" class="mt-1 h-11 w-full rounded-xl px-3"></label>
                <label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="lessonName" class="mt-1 h-11 w-full rounded-xl px-3"></label>
            </div>
            @if($lessonSubject?->resources->count() > 1)
                <label class="block text-sm">{{ __('curricula.fields.resource') }}<select wire:model="lessonResourceId" class="mt-1 w-full rounded-xl px-3 py-2.5">@foreach($lessonSubject->resources as $resource)<option value="{{ $resource->id }}">{{ $resource->book_name }}</option>@endforeach</select>@error('lessonResourceId')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</label>
            @endif
            <div><div class="mb-2 text-sm">{{ __('curricula.fields.importance') }}</div><div class="inline-flex h-11 overflow-hidden rounded-xl border border-white/10" dir="ltr" data-importance-bars>@foreach(range(1,3) as $level)<button type="button" wire:click="$set('importance', {{ $level }})" class="flex h-full items-end gap-1 px-4 pb-2 {{ $importance === $level ? 'bg-emerald-400/20 text-emerald-100' : 'bg-white/5' }}">@foreach(range(1,3) as $bar)<i class="w-1.5 rounded-sm {{ $bar <= $level ? 'bg-current' : 'bg-white/15' }}" style="height: {{ 5 + ($bar * 4) }}px"></i>@endforeach</button>@endforeach</div></div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button>
                @if($editingLessonId)
                    <button type="button" wire:click="deleteLesson({{ $editingLessonId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="inline-flex size-10 items-center justify-center rounded-full border border-red-300/25 text-red-200 transition hover:border-red-300/45 hover:bg-red-500/12 hover:text-red-100" title="{{ __('curricula.actions.delete') }}" aria-label="{{ __('curricula.actions.delete') }}" data-delete-lesson-in-edit><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5m-10.5 4.5v6m4.5-6v6m-8.25-10.5.75 13.5h10.5l.75-13.5M9 6.75V4.5h6v2.25" /></svg></button>
                @endif
            </div>
        </form>
    </x-admin.modal>
</div>
