<?php

use App\Models\Curriculum;
use App\Models\CurriculumLesson;
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
    public ?int $editingLessonId = null;
    public string $lessonName = '';
    public string $pageCount = '0';
    public int $importance = 1;

    public function mount(Curriculum $curriculum): void
    {
        abort_unless(app(CurriculumAccessService::class)->canManage(Auth::user()), 403);
        $this->curriculumRecord = $curriculum;
    }

    public function with(): array
    {
        $curriculum = Curriculum::query()->with(['course', 'gradeLevel', 'subjects.definition', 'subjects.resources', 'subjects.lessons'])->findOrFail($this->curriculumRecord->id);
        return [
            'curriculum' => $curriculum,
            'definitions' => CurriculumSubjectDefinition::query()->where('is_active', true)->whereDoesntHave('curriculumSubjects', fn ($query) => $query->where('curriculum_id', $curriculum->id))->with(['resources' => fn ($query) => $query->where('is_active', true)->orderBy('book_name')])->orderBy('name')->get(),
            'selectedDefinition' => $this->subjectDefinitionId ? CurriculumSubjectDefinition::query()->with(['resources' => fn ($query) => $query->where('is_active', true)])->find($this->subjectDefinitionId) : null,
        ];
    }

    public function openSubject(): void { $this->subjectDefinitionId = ''; $this->resourceIds = []; $this->resetValidation(); $this->showSubjectModal = true; }

    public function saveSubject(): void
    {
        $data = $this->validate(['subjectDefinitionId' => ['required', 'exists:curriculum_subject_definitions,id'], 'resourceIds' => ['array'], 'resourceIds.*' => ['integer', 'exists:curriculum_resources,id']]);
        $subject = CurriculumSubject::query()->create(['curriculum_id' => $this->curriculumRecord->id, 'subject_definition_id' => $data['subjectDefinitionId'], 'sort_order' => (int) CurriculumSubject::query()->where('curriculum_id', $this->curriculumRecord->id)->max('sort_order') + 10]);
        $validResources = CurriculumSubjectDefinition::query()->findOrFail($data['subjectDefinitionId'])->resources()->whereKey($data['resourceIds'])->pluck('id');
        $subject->resources()->sync($validResources);
        $this->showSubjectModal = false;
        session()->flash('status', __('curricula.messages.subject_added'));
    }

    public function deleteSubject(int $id): void
    {
        CurriculumSubject::query()->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($id)->delete();
    }

    public function openLesson(int $subjectId, ?int $lessonId = null): void
    {
        CurriculumSubject::query()->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($subjectId);
        $lesson = $lessonId ? CurriculumLesson::query()->where('curriculum_subject_id', $subjectId)->findOrFail($lessonId) : null;
        $this->lessonSubjectId = $subjectId; $this->editingLessonId = $lessonId;
        $this->lessonName = $lesson?->name ?? ''; $this->pageCount = (string) ($lesson?->page_count ?? 0); $this->importance = $lesson?->importance ?? 1;
        $this->resetValidation(); $this->showLessonModal = true;
    }

    public function saveLesson(): void
    {
        $data = $this->validate(['lessonSubjectId' => ['required', 'exists:curriculum_subjects,id'], 'lessonName' => ['required', 'string', 'max:255'], 'pageCount' => ['required', 'integer', 'min:0'], 'importance' => ['required', 'integer', 'between:1,3']]);
        $subject = CurriculumSubject::query()->where('curriculum_id', $this->curriculumRecord->id)->findOrFail($data['lessonSubjectId']);
        CurriculumLesson::query()->updateOrCreate(['id' => $this->editingLessonId], ['curriculum_subject_id' => $subject->id, 'name' => $data['lessonName'], 'page_count' => $data['pageCount'], 'importance' => $data['importance'], 'sort_order' => $this->editingLessonId ? CurriculumLesson::query()->findOrFail($this->editingLessonId)->sort_order : ((int) $subject->lessons()->max('sort_order') + 10)]);
        $this->showLessonModal = false;
        session()->flash('status', __('curricula.messages.lesson_saved'));
    }

    public function deleteLesson(int $id): void
    {
        CurriculumLesson::query()->whereHas('subject', fn ($query) => $query->where('curriculum_id', $this->curriculumRecord->id))->findOrFail($id)->delete();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8"><div class="flex flex-wrap items-start justify-between gap-4"><div><a href="{{ route('curricula.index') }}" wire:navigate class="text-sm text-neutral-300">← {{ __('crud.common.actions.back') }}</a><h1 class="font-display mt-4 text-4xl text-white">{{ $curriculum->name }}</h1><div class="mt-3 flex flex-wrap gap-2"><span class="badge-soft">{{ $curriculum->course->name }}</span><span class="badge-soft">{{ $curriculum->gradeLevel?->name ?: __('curricula.options.all_grades') }}</span><span class="badge-soft">{{ $curriculum->subjects->sum(fn ($subject) => $subject->lessons->count()) }} {{ __('curricula.fields.lessons') }}</span></div></div><button wire:click="openSubject" class="pill-link pill-link--accent">{{ __('curricula.actions.add_subject') }}</button></div></section>
    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    <section class="grid gap-4">
        @forelse($curriculum->subjects as $subject)
            <details class="surface-panel p-5" open>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4"><div><span class="text-lg font-semibold text-white">{{ $subject->definition->name }}</span><span class="ms-3 text-xs text-neutral-400">{{ $subject->lessons->count() }} {{ __('curricula.fields.lessons') }}</span>@if($subject->resources->isNotEmpty())<div class="mt-1 text-xs text-neutral-400">{{ $subject->resources->pluck('book_name')->implode(' · ') }}</div>@endif</div><div class="flex gap-2" onclick="event.preventDefault()"><button wire:click="openLesson({{ $subject->id }})" class="pill-link pill-link--accent pill-link--compact">{{ __('curricula.actions.add_lesson') }}</button><button wire:click="deleteSubject({{ $subject->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200">{{ __('curricula.actions.delete') }}</button></div></summary>
                <div class="mt-4 overflow-x-auto"><table class="w-full table-fixed text-sm"><thead><tr><th class="p-3">#</th><th class="p-3">{{ __('curricula.fields.lesson') }}</th><th class="p-3">{{ __('curricula.fields.page_count') }}</th><th class="p-3">{{ __('curricula.fields.importance') }}</th><th class="p-3"></th></tr></thead><tbody>@forelse($subject->lessons as $lesson)<tr><td class="p-3">{{ $loop->iteration }}</td><td class="p-3 text-white">{{ $lesson->name }}</td><td class="p-3">{{ $lesson->page_count }}</td><td class="p-3"><span class="inline-flex items-end gap-1" title="{{ $lesson->importance }} / 3">@foreach(range(1,3) as $bar)<i class="w-1.5 rounded-sm {{ $bar <= $lesson->importance ? 'bg-emerald-300' : 'bg-white/15' }}" style="height: {{ 5 + ($bar * 4) }}px"></i>@endforeach</span></td><td class="p-3 text-end"><button wire:click="openLesson({{ $subject->id }}, {{ $lesson->id }})" class="pill-link pill-link--compact">{{ __('curricula.actions.edit') }}</button><button wire:click="deleteLesson({{ $lesson->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200">{{ __('curricula.actions.delete') }}</button></td></tr>@empty<tr><td colspan="5" class="admin-empty-state">{{ __('curricula.table.no_lessons') }}</td></tr>@endforelse</tbody></table></div>
            </details>
        @empty<div class="surface-panel admin-empty-state">{{ __('curricula.fields.subjects') }} — {{ __('crud.common.not_available') }}</div>@endforelse
    </section>

    <x-admin.modal :show="$showSubjectModal" :title="__('curricula.form.subject_title')" close-method="$set('showSubjectModal', false)" max-width="3xl"><form wire:submit="saveSubject" class="space-y-4"><label class="block text-sm">{{ __('curricula.fields.subject') }}<select wire:model.live="subjectDefinitionId" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.common.select') }}</option>@foreach($definitions as $definition)<option value="{{ $definition->id }}">{{ $definition->name }}</option>@endforeach</select></label>@if($selectedDefinition?->resources->isNotEmpty())<div><div class="mb-2 text-sm">{{ __('curricula.fields.resources') }}</div><div class="grid gap-2 sm:grid-cols-2">@foreach($selectedDefinition->resources as $resource)<label class="flex items-center gap-2 rounded-xl border border-white/10 p-3 text-sm"><input type="checkbox" wire:model="resourceIds" value="{{ $resource->id }}"><span>{{ $resource->book_name }}</span></label>@endforeach</div></div>@endif<button class="pill-link pill-link--accent">{{ __('curricula.actions.add_subject') }}</button></form></x-admin.modal>
    <x-admin.modal :show="$showLessonModal" :title="__('curricula.form.lesson_title')" close-method="$set('showLessonModal', false)" max-width="3xl"><form wire:submit="saveLesson" class="grid gap-4 md:grid-cols-2"><label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="lessonName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.page_count') }}<input wire:model="pageCount" type="number" min="0" class="mt-1 w-full rounded-xl px-4 py-3"></label><div class="md:col-span-2"><div class="mb-2 text-sm">{{ __('curricula.fields.importance') }}</div><div class="inline-flex overflow-hidden rounded-xl border border-white/10">@foreach(range(1,3) as $level)<button type="button" wire:click="$set('importance', {{ $level }})" class="flex items-end gap-1 px-5 py-3 {{ $importance === $level ? 'bg-emerald-400/20 text-emerald-100' : 'bg-white/5' }}">@foreach(range(1,3) as $bar)<i class="w-1.5 rounded-sm {{ $bar <= $level ? 'bg-current' : 'bg-white/15' }}" style="height: {{ 5 + ($bar * 4) }}px"></i>@endforeach</button>@endforeach</div></div><div class="md:col-span-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
</div>
