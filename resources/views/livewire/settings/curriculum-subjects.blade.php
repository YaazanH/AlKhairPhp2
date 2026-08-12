<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\CurriculumResource;
use App\Models\CurriculumSubjectDefinition;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public bool $showSubjectModal = false;
    public bool $showResourceModal = false;
    public ?int $editingSubjectId = null;
    public ?int $editingResourceId = null;
    public ?int $resourceSubjectId = null;
    public string $subjectName = '';
    public string $bookName = '';
    public string $author = '';
    public string $publisher = '';
    public string $publishedOn = '';

    public function mount(): void { $this->authorizePermission('curricula.manage'); }

    public function with(): array
    {
        return ['subjects' => CurriculumSubjectDefinition::query()->with(['resources' => fn ($query) => $query->orderBy('book_name')])->withCount('curriculumSubjects')->orderBy('name')->get()];
    }

    public function editSubject(?int $id = null): void
    {
        $this->resetValidation();
        $this->editingSubjectId = $id;
        $this->subjectName = $id ? CurriculumSubjectDefinition::query()->findOrFail($id)->name : '';
        $this->showSubjectModal = true;
    }

    public function saveSubject(): void
    {
        $data = $this->validate(['subjectName' => ['required', 'string', 'max:255', Rule::unique('curriculum_subject_definitions', 'name')->ignore($this->editingSubjectId)]]);
        CurriculumSubjectDefinition::query()->updateOrCreate(['id' => $this->editingSubjectId], ['name' => $data['subjectName'], 'is_active' => true]);
        $this->showSubjectModal = false;
        session()->flash('status', __('curricula.messages.subject_saved'));
    }

    public function deleteSubject(int $id): void
    {
        $subject = CurriculumSubjectDefinition::query()->withCount('curriculumSubjects')->findOrFail($id);
        if ($subject->curriculum_subjects_count) { $this->addError('delete', __('curricula.errors.subject_used')); return; }
        $subject->delete();
    }

    public function editResource(int $subjectId, ?int $id = null): void
    {
        $this->resetValidation();
        $this->resourceSubjectId = $subjectId;
        $this->editingResourceId = $id;
        $resource = $id ? CurriculumResource::query()->where('subject_definition_id', $subjectId)->findOrFail($id) : null;
        $this->bookName = $resource?->book_name ?? '';
        $this->author = $resource?->author ?? '';
        $this->publisher = $resource?->publisher ?? '';
        $this->publishedOn = $resource?->published_on?->toDateString() ?? '';
        $this->showResourceModal = true;
    }

    public function saveResource(): void
    {
        $data = $this->validate([
            'resourceSubjectId' => ['required', 'exists:curriculum_subject_definitions,id'], 'bookName' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'], 'publisher' => ['nullable', 'string', 'max:255'], 'publishedOn' => ['nullable', 'date'],
        ]);
        CurriculumResource::query()->updateOrCreate(['id' => $this->editingResourceId], [
            'subject_definition_id' => $data['resourceSubjectId'], 'book_name' => $data['bookName'],
            'author' => $data['author'] ?: null, 'publisher' => $data['publisher'] ?: null,
            'published_on' => $data['publishedOn'] ?: null, 'is_active' => true,
        ]);
        $this->showResourceModal = false;
        session()->flash('status', __('curricula.messages.resource_saved'));
    }

    public function deleteResource(int $id): void { CurriculumResource::query()->findOrFail($id)->delete(); }
}; ?>

<div class="page-stack">
    <x-settings.admin-nav section="dashboard" current="settings.curriculum-subjects" />
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><div class="eyebrow">{{ __('curricula.settings.meta') }}</div><h1 class="font-display mt-3 text-4xl text-white">{{ __('curricula.settings.title') }}</h1><p class="mt-3 text-neutral-300">{{ __('curricula.settings.subtitle') }}</p></div>
            <button wire:click="editSubject" class="pill-link pill-link--accent">{{ __('curricula.actions.add_subject') }}</button>
        </div>
    </section>
    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    @error('delete')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror
    <section class="grid gap-4">
        @forelse($subjects as $subject)
            <details class="surface-panel p-5" open>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                    <div><span class="font-semibold text-white">{{ $subject->name }}</span><span class="ms-3 text-xs text-neutral-400">{{ $subject->resources->count() }} {{ __('curricula.fields.resources') }}</span></div>
                    <div class="flex gap-2" onclick="event.preventDefault()"><button wire:click="editResource({{ $subject->id }})" class="pill-link pill-link--compact">{{ __('curricula.actions.add_resource') }}</button><button wire:click="editSubject({{ $subject->id }})" class="pill-link pill-link--compact">{{ __('curricula.actions.edit') }}</button><button wire:click="deleteSubject({{ $subject->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200">{{ __('curricula.actions.delete') }}</button></div>
                </summary>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="p-3">{{ __('curricula.fields.book_name') }}</th><th class="p-3">{{ __('curricula.fields.author') }}</th><th class="p-3">{{ __('curricula.fields.publisher') }}</th><th class="p-3">{{ __('curricula.fields.published_on') }}</th><th class="p-3"></th></tr></thead><tbody>@forelse($subject->resources as $resource)<tr><td class="p-3 text-white">{{ $resource->book_name }}</td><td class="p-3">{{ $resource->author ?: '—' }}</td><td class="p-3">{{ $resource->publisher ?: '—' }}</td><td class="p-3">{{ $resource->published_on?->format('Y-m-d') ?: '—' }}</td><td class="p-3 text-end"><button wire:click="editResource({{ $subject->id }}, {{ $resource->id }})" class="pill-link pill-link--compact">{{ __('curricula.actions.edit') }}</button><button wire:click="deleteResource({{ $resource->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200">{{ __('curricula.actions.delete') }}</button></td></tr>@empty<tr><td colspan="5" class="admin-empty-state">{{ __('crud.common.not_available') }}</td></tr>@endforelse</tbody></table></div>
            </details>
        @empty<div class="admin-empty-state surface-panel">{{ __('crud.common.not_available') }}</div>@endforelse
    </section>

    <x-admin.modal :show="$showSubjectModal" :title="__('curricula.form.subject_title')" close-method="$set('showSubjectModal', false)" max-width="2xl"><form wire:submit="saveSubject" class="space-y-4"><label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="subjectName" class="mt-1 w-full rounded-xl px-4 py-3"></label>@error('subjectName')<div class="text-sm text-red-400">{{ $message }}</div>@enderror<div class="flex gap-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
    <x-admin.modal :show="$showResourceModal" :title="__('curricula.actions.add_resource')" close-method="$set('showResourceModal', false)" max-width="3xl"><form wire:submit="saveResource" class="grid gap-4 md:grid-cols-2"><label class="block text-sm">{{ __('curricula.fields.book_name') }}<input wire:model="bookName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.author') }}<input wire:model="author" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.publisher') }}<input wire:model="publisher" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.published_on') }}<input wire:model="publishedOn" type="date" class="mt-1 w-full rounded-xl px-4 py-3"></label><div class="md:col-span-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
</div>
