<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\CurriculumResource;
use App\Models\CurriculumSubjectDefinition;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use AuthorizesPermissions;
    use WithFileUploads;

    public bool $showSubjectModal = false;
    public bool $showResourceModal = false;
    public bool $showStandaloneResourcesModal = false;
    public bool $showStandaloneResourceForm = false;
    public ?int $editingSubjectId = null;
    public ?int $editingResourceId = null;
    public ?int $resourceSubjectId = null;
    public string $subjectName = '';
    public string $bookName = '';
    public string $author = '';
    public string $publisher = '';
    public string $editionNumber = '';
    public string $publishedOn = '';
    public $resourcePdf = null;

    public function mount(): void { $this->authorizePermission('curricula.manage'); }

    public function with(): array
    {
        return [
            'subjects' => CurriculumSubjectDefinition::query()->with(['resources' => fn ($query) => $query->orderBy('book_name')])->withCount('curriculumSubjects')->orderBy('name')->get(),
            'standaloneResources' => CurriculumResource::query()->whereNull('subject_definition_id')->orderBy('book_name')->get(),
        ];
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
        $data = $this->validate(['subjectName' => ['required', 'string', 'max:255', Rule::unique('curriculum_subject_definitions', 'name')->withoutTrashed()->ignore($this->editingSubjectId)]]);
        if (! $this->editingSubjectId) {
            $existing = CurriculumSubjectDefinition::withTrashed()->where('name', $data['subjectName'])->first();
            if ($existing?->trashed()) {
                $existing->restore();
                $existing->update(['is_active' => true]);
            } else {
                CurriculumSubjectDefinition::query()->create(['name' => $data['subjectName'], 'is_active' => true]);
            }
        } else {
            CurriculumSubjectDefinition::query()->findOrFail($this->editingSubjectId)->update(['name' => $data['subjectName'], 'is_active' => true]);
        }
        $this->showSubjectModal = false;
        session()->flash('status', __('curricula.messages.subject_saved'));
    }

    public function deleteSubject(int $id): void
    {
        $subject = CurriculumSubjectDefinition::query()->withCount('curriculumSubjects')->findOrFail($id);
        if ($subject->curriculum_subjects_count) { $this->addError('delete', __('curricula.errors.subject_used')); return; }
        $subject->delete();
    }

    public function editResource(int $subjectId = 0, ?int $id = null): void
    {
        $this->resetValidation();
        $this->resourceSubjectId = $subjectId ?: null;
        $this->editingResourceId = $id;
        $resource = $id ? CurriculumResource::query()->where('subject_definition_id', $subjectId ?: null)->findOrFail($id) : null;
        $this->bookName = $resource?->book_name ?? '';
        $this->author = $resource?->author ?? '';
        $this->publisher = $resource?->publisher ?? '';
        $this->editionNumber = $resource?->edition_number ?? 'الطبعة ';
        $this->publishedOn = $resource?->published_on?->format('Y') ?? '';
        $this->resourcePdf = null;
        $this->showResourceModal = true;
    }

    public function openStandaloneResources(): void
    {
        $this->showStandaloneResourcesModal = true;
        $this->showStandaloneResourceForm = false;
        $this->resetValidation();
    }

    public function closeStandaloneResources(): void
    {
        $this->showStandaloneResourcesModal = false;
        $this->showStandaloneResourceForm = false;
        $this->resetResourceForm();
    }

    public function openStandaloneResourceForm(): void
    {
        $this->resetResourceForm();
        $this->showStandaloneResourceForm = true;
    }

    public function editStandaloneResource(int $id): void
    {
        $this->editResource(0, $id);
        $this->showResourceModal = false;
        $this->showStandaloneResourceForm = true;
    }

    public function closeStandaloneResourceForm(): void
    {
        $this->showStandaloneResourceForm = false;
        $this->resetResourceForm();
    }

    protected function resetResourceForm(): void
    {
        $this->editingResourceId = null;
        $this->resourceSubjectId = null;
        $this->bookName = '';
        $this->author = '';
        $this->publisher = '';
        $this->editionNumber = 'الطبعة ';
        $this->publishedOn = '';
        $this->resourcePdf = null;
        $this->resetValidation();
    }

    public function saveResource(): void
    {
        $data = $this->validate([
            'resourceSubjectId' => ['nullable', 'exists:curriculum_subject_definitions,id'], 'bookName' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'], 'publisher' => ['nullable', 'string', 'max:255'], 'editionNumber' => ['nullable', 'string', 'max:100'], 'publishedOn' => ['nullable', 'integer', 'digits:4', 'between:1000,9999'], 'resourcePdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
        ]);
        $editionNumber = trim((string) ($data['editionNumber'] ?? ''));
        if ($editionNumber !== '' && ! str_starts_with($editionNumber, 'الطبعة')) {
            $editionNumber = 'الطبعة '.$editionNumber;
        }
        $resource = CurriculumResource::query()->updateOrCreate(['id' => $this->editingResourceId], [
            'subject_definition_id' => $data['resourceSubjectId'] ?? null, 'book_name' => $data['bookName'],
            'author' => $data['author'] ?: null, 'publisher' => $data['publisher'] ?: null, 'edition_number' => $editionNumber ?: null,
            'published_on' => $data['publishedOn'] ? $data['publishedOn'].'-01-01' : null, 'is_active' => true,
        ]);
        if ($this->resourcePdf) {
            if ($resource->pdf_path) Storage::disk('local')->delete($resource->pdf_path);
            $resource->update(['pdf_path' => $this->resourcePdf->store('curriculum/books', 'local')]);
        }
        if ($this->showStandaloneResourceForm && $this->resourceSubjectId === null) {
            $this->showStandaloneResourceForm = false;
            $this->resetResourceForm();
        } else {
            $this->showResourceModal = false;
        }
        session()->flash('status', __('curricula.messages.resource_saved'));
    }

    public function deleteResource(int $id): void { $resource = CurriculumResource::query()->findOrFail($id); if ($resource->pdf_path) Storage::disk('local')->delete($resource->pdf_path); $resource->delete(); }

    public function displayEdition(?string $edition): string
    {
        $edition = trim((string) $edition);

        return $edition === '' ? '—' : (str_starts_with($edition, 'الطبعة') ? $edition : 'الطبعة '.$edition);
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('ui.common.settings') }}</h1>
    </section>
    <x-settings.admin-nav section="dashboard" current="settings.curriculum-subjects" />
    <section class="surface-panel p-5 lg:p-6"><div class="admin-toolbar"><div class="admin-toolbar__title">{{ __('curricula.settings.title') }}</div><div class="admin-toolbar__actions"><button wire:click="openStandaloneResources" class="pill-link">{{ __('curricula.fields.standalone_books') }}</button><button wire:click="editSubject" class="pill-link pill-link--accent text-xl" aria-label="{{ __('curricula.actions.add_subject') }}">+</button></div></div></section>

    @if(session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    @error('delete')<div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>@enderror
    <section class="grid gap-4">
        @forelse($subjects as $subject)
            <details class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" open>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 border-b border-neutral-200 bg-neutral-50 px-5 py-4 dark:border-neutral-700 dark:bg-neutral-900/60">
                    <div><span class="font-semibold text-white">{{ $subject->name }}</span><span class="ms-3 text-xs text-neutral-400">{{ $subject->resources->count() }} {{ __('curricula.fields.resources') }}</span></div>
                    <div class="flex gap-2" onclick="event.preventDefault()"><button wire:click="editResource({{ $subject->id }})" class="pill-link pill-link--compact text-lg" aria-label="{{ __('curricula.actions.add_resource') }}">+</button><button wire:click="editSubject({{ $subject->id }})" class="pill-link pill-link--compact" aria-label="{{ __('curricula.actions.edit') }}">✎</button><button wire:click="deleteSubject({{ $subject->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200" aria-label="{{ __('curricula.actions.delete') }}"><x-icons.trash /></button></div>
                </summary>
                <div class="overflow-hidden"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700"><thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="p-3">{{ __('curricula.fields.book_name') }}</th><th class="p-3">{{ __('curricula.fields.author') }}</th><th class="p-3">{{ __('curricula.fields.publisher') }}</th><th class="p-3">{{ __('curricula.fields.edition_number') }}</th><th class="p-3">{{ __('curricula.fields.published_on') }}</th><th class="p-3"></th></tr></thead><tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@forelse($subject->resources as $resource)<tr><td class="p-3 text-white">{{ $resource->book_name }}</td><td class="p-3">{{ $resource->author ?: '—' }}</td><td class="p-3">{{ $resource->publisher ?: '—' }}</td><td class="p-3">{{ $this->displayEdition($resource->edition_number) }}</td><td class="p-3">{{ $resource->published_on?->format('Y') ?: '—' }}</td><td class="p-3 text-end"><button wire:click="editResource({{ $subject->id }}, {{ $resource->id }})" class="pill-link pill-link--compact" aria-label="{{ __('curricula.actions.edit') }}">✎</button><button wire:click="deleteResource({{ $resource->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200" aria-label="{{ __('curricula.actions.delete') }}"><x-icons.trash /></button></td></tr>@empty<tr><td colspan="6" class="admin-empty-state">{{ __('crud.common.not_available') }}</td></tr>@endforelse</tbody></table></div></div>
            </details>
        @empty<div class="admin-empty-state surface-panel">{{ __('crud.common.not_available') }}</div>@endforelse
    </section>

    <x-admin.modal :show="$showSubjectModal" :title="__('curricula.form.subject_title')" close-method="$set('showSubjectModal', false)" max-width="2xl"><form wire:submit="saveSubject" class="space-y-4"><label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="subjectName" class="mt-1 w-full rounded-xl px-4 py-3"></label>@error('subjectName')<div class="text-sm text-red-400">{{ $message }}</div>@enderror<div class="flex gap-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
    <x-admin.modal :show="$showStandaloneResourcesModal" :title="__('curricula.fields.standalone_books')" close-method="closeStandaloneResources" max-width="5xl">
        <div class="space-y-4">
            <div class="flex justify-end"><button type="button" wire:click="{{ $showStandaloneResourceForm ? 'closeStandaloneResourceForm' : 'openStandaloneResourceForm' }}" class="pill-link pill-link--accent">{{ $showStandaloneResourceForm ? __('crud.common.actions.cancel') : __('curricula.actions.add_standalone_book') }}</button></div>
            @if ($showStandaloneResourceForm)
                <form wire:submit="saveResource" x-data="{ uploadingPdf: false }" x-on:livewire-upload-start="uploadingPdf = true" x-on:livewire-upload-finish="uploadingPdf = false" x-on:livewire-upload-error="uploadingPdf = false" x-on:livewire-upload-cancel="uploadingPdf = false" x-on:submit="if (uploadingPdf) $event.preventDefault()" class="grid gap-4 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 md:grid-cols-2">
                    <label class="block text-sm">{{ __('curricula.fields.book_name') }}<input wire:model="bookName" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                    <label class="block text-sm">{{ __('curricula.fields.author') }}<input wire:model="author" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                    <label class="block text-sm">{{ __('curricula.fields.publisher') }}<input wire:model="publisher" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                    <div class="grid grid-cols-2 gap-3"><label class="block text-sm">{{ __('curricula.fields.edition_number') }}<input wire:model="editionNumber" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.year') }}<input wire:model="publishedOn" type="number" min="1000" max="9999" inputmode="numeric" class="mt-1 w-full rounded-xl px-4 py-3"></label></div>
                    <label class="block text-sm md:col-span-2">{{ __('curricula.fields.pdf_file') }}<input wire:model="resourcePdf" type="file" accept="application/pdf" class="mt-1 w-full rounded-xl px-4 py-3"><span wire:loading wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-amber-300"><span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>{{ __('curricula.fields.pdf_uploading') }}</span>@if($resourcePdf)<span wire:loading.remove wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-emerald-300">✓ {{ __('curricula.fields.pdf_ready') }}</span>@endif</label>
                    @error('resourcePdf')<div class="text-sm text-red-400 md:col-span-2">{{ $message }}</div>@enderror
                    <div class="md:col-span-2"><button x-bind:disabled="uploadingPdf" wire:loading.attr="disabled" wire:target="resourcePdf" class="pill-link pill-link--accent disabled:cursor-wait disabled:opacity-50">{{ __('curricula.actions.save') }}</button></div>
                </form>
            @endif
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700"><thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="p-3">{{ __('curricula.fields.book_name') }}</th><th class="p-3">{{ __('curricula.fields.edition_number') }}</th><th class="p-3">{{ __('curricula.fields.published_on') }}</th><th class="p-3">{{ __('curricula.fields.pdf_file_short') }}</th><th class="p-3"></th></tr></thead><tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@forelse($standaloneResources as $resource)<tr><td class="p-3 text-white">{{ $resource->book_name }}</td><td class="p-3">{{ $this->displayEdition($resource->edition_number) }}</td><td class="p-3">{{ $resource->published_on?->format('Y') ?: '—' }}</td><td class="p-3">{{ $resource->pdf_path ? __('curricula.fields.pdf_uploaded') : __('curricula.fields.no_pdf') }}</td><td class="p-3 text-end"><button wire:click="editStandaloneResource({{ $resource->id }})" class="pill-link pill-link--compact" aria-label="{{ __('curricula.actions.edit') }}">✎</button><button wire:click="deleteResource({{ $resource->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact text-red-200" aria-label="{{ __('curricula.actions.delete') }}"><x-icons.trash /></button></td></tr>@empty<tr><td colspan="5" class="admin-empty-state">{{ __('crud.common.not_available') }}</td></tr>@endforelse</tbody></table></div></div>
        </div>
    </x-admin.modal>
    <x-admin.modal :show="$showResourceModal" :title="__('curricula.actions.add_resource')" close-method="$set('showResourceModal', false)" max-width="3xl"><form wire:submit="saveResource" x-data="{ uploadingPdf: false }" x-on:livewire-upload-start="uploadingPdf = true" x-on:livewire-upload-finish="uploadingPdf = false" x-on:livewire-upload-error="uploadingPdf = false" x-on:livewire-upload-cancel="uploadingPdf = false" x-on:submit="if (uploadingPdf) $event.preventDefault()" class="grid gap-4 md:grid-cols-2"><label class="block text-sm">{{ __('curricula.fields.book_name') }}<input wire:model="bookName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.author') }}<input wire:model="author" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.publisher') }}<input wire:model="publisher" class="mt-1 w-full rounded-xl px-4 py-3"></label><div class="grid grid-cols-2 gap-3"><label class="block text-sm">{{ __('curricula.fields.edition_number') }}<input wire:model="editionNumber" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.year') }}<input wire:model="publishedOn" type="number" min="1000" max="9999" inputmode="numeric" class="mt-1 w-full rounded-xl px-4 py-3"></label></div><label class="block text-sm md:col-span-2">{{ __('curricula.fields.pdf_file') }}<input wire:model="resourcePdf" type="file" accept="application/pdf" class="mt-1 w-full rounded-xl px-4 py-3"><span wire:loading wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-amber-300"><span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>{{ __('curricula.fields.pdf_uploading') }}</span>@if($resourcePdf)<span wire:loading.remove wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-emerald-300">✓ {{ __('curricula.fields.pdf_ready') }}</span>@endif</label>@error('resourcePdf')<div class="text-sm text-red-400 md:col-span-2">{{ $message }}</div>@enderror<div class="md:col-span-2"><button x-bind:disabled="uploadingPdf" wire:loading.attr="disabled" wire:target="resourcePdf" class="pill-link pill-link--accent disabled:cursor-wait disabled:opacity-50">{{ __('curricula.actions.save') }}</button></div></form></x-admin.modal>
</div>
