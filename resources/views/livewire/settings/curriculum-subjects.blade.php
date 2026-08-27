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
        $this->showSubjectModal = false;
        $this->editingSubjectId = null;
        $this->subjectName = '';
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

    public function deleteResource(int $id): void
    {
        $resource = CurriculumResource::query()->findOrFail($id);
        $isStandalone = $resource->subject_definition_id === null;
        if ($resource->pdf_path) Storage::disk('local')->delete($resource->pdf_path);
        $resource->delete();
        $this->showResourceModal = false;
        if ($isStandalone) $this->showStandaloneResourceForm = false;
        $this->resetResourceForm();
    }

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
            <div class="curriculum-subject-table overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div class="font-semibold text-white">{{ $subject->name }}</div>
                    <div class="flex gap-2"><button type="button" wire:click="editResource({{ $subject->id }})" class="pill-link pill-link--compact text-lg" aria-label="{{ __('curricula.actions.add_resource') }}">+</button><button type="button" wire:click="editSubject({{ $subject->id }})" class="pill-link pill-link--compact" aria-label="{{ __('curricula.actions.edit') }}"><span class="curriculum-subject-pencil" aria-hidden="true">✎</span></button></div>
                </div>
                <div class="overflow-x-auto">
                    <table data-curriculum-subject-resource-grid class="curriculum-subject-resource-grid min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <colgroup>
                            <col data-curriculum-resource-column="index" class="curriculum-subject-resource-grid__index">
                            <col data-curriculum-resource-column="name" class="curriculum-subject-resource-grid__name">
                            <col data-curriculum-resource-column="author" class="curriculum-subject-resource-grid__author">
                            <col data-curriculum-resource-column="publisher" class="curriculum-subject-resource-grid__publisher">
                            <col data-curriculum-resource-column="edition" class="curriculum-subject-resource-grid__edition">
                            <col data-curriculum-resource-column="year" class="curriculum-subject-resource-grid__year">
                            <col data-curriculum-resource-column="book" class="curriculum-subject-resource-grid__book">
                            <col data-curriculum-resource-column="actions" class="curriculum-subject-resource-grid__actions">
                        </colgroup>
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                            <tr>
                                <th data-curriculum-resource-index class="curriculum-resource-index px-3 py-3 text-center font-medium">#</th>
                                <th class="curriculum-subject-resource-name px-5 py-3 text-left font-medium">{{ __('curricula.fields.book_name') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('curricula.fields.author') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('curricula.fields.publisher') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('curricula.fields.edition_number') }}</th>
                                <th class="curriculum-subject-resource-year px-2 py-3 text-center font-medium">{{ __('curricula.fields.published_on') }}</th>
                                <th class="px-3 py-3 text-center font-medium">{{ __('curricula.fields.book') }}</th>
                                <th class="px-5 py-3 text-right font-medium">{{ __('crud.common.actions.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @forelse($subject->resources as $resource)
                                <tr>
                                    <td class="curriculum-resource-index px-3 py-3 text-center">{{ $loop->iteration }}</td>
                                    <td class="curriculum-subject-resource-name px-5 py-3 text-white" title="{{ $resource->book_name }}">{{ $resource->book_name }}</td>
                                    <td class="px-5 py-3">{{ $resource->author ?: '—' }}</td>
                                    <td class="px-5 py-3">{{ $resource->publisher ?: '—' }}</td>
                                    <td class="px-5 py-3">{{ $this->displayEdition($resource->edition_number) }}</td>
                                    <td class="curriculum-subject-resource-year px-2 py-3 text-center">{{ $resource->published_on?->format('Y') ?: '—' }}</td>
                                    <td class="px-3 py-3 text-center">
                                        @if($resource->pdf_path)
                                            <span class="curriculum-resource-book-status curriculum-resource-book-status--available" title="{{ __('curricula.fields.pdf_uploaded') }}" aria-label="{{ __('curricula.fields.pdf_uploaded') }}">✓</span>
                                        @else
                                            <span class="curriculum-resource-book-status" aria-label="{{ __('curricula.fields.no_pdf') }}">--</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3"><div class="flex flex-nowrap justify-end gap-2"><button type="button" wire:click="editResource({{ $subject->id }}, {{ $resource->id }})" class="curriculum-resource-edit-button" title="{{ __('curricula.actions.edit') }}" aria-label="{{ __('curricula.actions.edit') }}" data-curriculum-resource-edit-icon><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 20 4.2-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/></svg></button></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="admin-empty-state">{{ __('crud.common.not_available') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @empty<div class="admin-empty-state surface-panel">{{ __('crud.common.not_available') }}</div>@endforelse
    </section>

    <x-admin.modal :show="$showSubjectModal" :title="__('curricula.form.subject_title')" close-method="$set('showSubjectModal', false)" max-width="2xl"><form wire:submit="saveSubject" class="space-y-4"><label class="block text-sm">{{ __('curricula.fields.name') }}<input wire:model="subjectName" class="mt-1 w-full rounded-xl px-4 py-3"></label>@error('subjectName')<div class="text-sm text-red-400">{{ $message }}</div>@enderror @error('delete')<div class="text-sm text-red-400">{{ $message }}</div>@enderror<div class="flex items-center gap-2"><button class="pill-link pill-link--accent">{{ __('curricula.actions.save') }}</button>@if($editingSubjectId)<button type="button" wire:click="deleteSubject({{ $editingSubjectId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--danger">{{ __('crud.common.actions.delete') }}</button>@endif</div></form></x-admin.modal>
    <x-admin.modal :show="$showStandaloneResourcesModal" :title="__('curricula.fields.standalone_books')" close-method="closeStandaloneResources" max-width="5xl">
        <x-slot:header-actions>
            <button type="button" wire:click="{{ $showStandaloneResourceForm ? 'closeStandaloneResourceForm' : 'openStandaloneResourceForm' }}" class="pill-link pill-link--compact pill-link--accent">{{ $showStandaloneResourceForm ? __('crud.common.actions.cancel') : __('curricula.actions.add_standalone_book') }}</button>
        </x-slot:header-actions>
        <div class="space-y-4">
            @if ($showStandaloneResourceForm)
                <form wire:submit="saveResource" x-data="{ uploadingPdf: false }" x-on:livewire-upload-start="uploadingPdf = true" x-on:livewire-upload-finish="uploadingPdf = false" x-on:livewire-upload-error="uploadingPdf = false" x-on:livewire-upload-cancel="uploadingPdf = false" x-on:submit="if (uploadingPdf) $event.preventDefault()" class="grid gap-4 rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4 md:grid-cols-2">
                    <label class="block text-sm">{{ __('curricula.fields.book_name') }}<input wire:model="bookName" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                    <label class="block text-sm">{{ __('curricula.fields.author') }}<input wire:model="author" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                    <label class="block text-sm">{{ __('curricula.fields.publisher') }}<input wire:model="publisher" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                    <div class="grid grid-cols-2 gap-3"><label class="block text-sm">{{ __('curricula.fields.edition_number') }}<input wire:model="editionNumber" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.year') }}<input wire:model="publishedOn" type="number" min="1000" max="9999" inputmode="numeric" class="mt-1 w-full rounded-xl px-4 py-3"></label></div>
                    <label class="block text-sm md:col-span-2">{{ __('curricula.fields.pdf_file') }}<input wire:model="resourcePdf" type="file" accept="application/pdf" class="mt-1 w-full rounded-xl px-4 py-3"><span wire:loading wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-amber-300"><span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>{{ __('curricula.fields.pdf_uploading') }}</span>@if($resourcePdf)<span wire:loading.remove wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-emerald-300">✓ {{ __('curricula.fields.pdf_ready') }}</span>@endif</label>
                    @error('resourcePdf')<div data-pdf-upload-error-for="resourcePdf" class="text-sm text-red-400 md:col-span-2">{{ $message }}</div>@enderror
                    <div class="flex items-center gap-2 md:col-span-2"><button x-bind:disabled="uploadingPdf" wire:loading.attr="disabled" wire:target="resourcePdf" class="pill-link pill-link--accent disabled:cursor-wait disabled:opacity-50">{{ __('curricula.actions.save') }}</button>@if($editingResourceId)<button type="button" wire:click="deleteResource({{ $editingResourceId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--danger">{{ __('crud.common.actions.delete') }}</button>@endif</div>
                </form>
            @endif
            <div class="curriculum-resource-table overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700"><thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('curricula.fields.book_name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('curricula.fields.edition_number') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('curricula.fields.published_on') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('curricula.fields.pdf_file_short') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('crud.common.actions.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@forelse($standaloneResources as $resource)<tr><td class="px-5 py-3 text-white">{{ $resource->book_name }}</td><td class="px-5 py-3">{{ $this->displayEdition($resource->edition_number) }}</td><td class="px-5 py-3">{{ $resource->published_on?->format('Y') ?: '—' }}</td><td class="px-5 py-3">{{ $resource->pdf_path ? __('curricula.fields.pdf_uploaded') : __('curricula.fields.no_pdf') }}</td><td class="px-3 py-3"><div class="flex flex-nowrap justify-end gap-2"><button type="button" wire:click="editStandaloneResource({{ $resource->id }})" class="curriculum-resource-edit-button" title="{{ __('curricula.actions.edit') }}" aria-label="{{ __('curricula.actions.edit') }}" data-curriculum-resource-edit-icon><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 20 4.2-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/></svg></button></div></td></tr>@empty<tr><td colspan="5" class="admin-empty-state">{{ __('crud.common.not_available') }}</td></tr>@endforelse</tbody></table></div></div>
        </div>
    </x-admin.modal>
    <x-admin.modal :show="$showResourceModal" :title="__('curricula.actions.add_resource')" close-method="$set('showResourceModal', false)" max-width="3xl"><form wire:submit="saveResource" x-data="{ uploadingPdf: false }" x-on:livewire-upload-start="uploadingPdf = true" x-on:livewire-upload-finish="uploadingPdf = false" x-on:livewire-upload-error="uploadingPdf = false" x-on:livewire-upload-cancel="uploadingPdf = false" x-on:submit="if (uploadingPdf) $event.preventDefault()" class="grid gap-4 md:grid-cols-2"><label class="block text-sm">{{ __('curricula.fields.book_name') }}<input wire:model="bookName" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.author') }}<input wire:model="author" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.publisher') }}<input wire:model="publisher" class="mt-1 w-full rounded-xl px-4 py-3"></label><div class="grid grid-cols-2 gap-3"><label class="block text-sm">{{ __('curricula.fields.edition_number') }}<input wire:model="editionNumber" class="mt-1 w-full rounded-xl px-4 py-3"></label><label class="block text-sm">{{ __('curricula.fields.year') }}<input wire:model="publishedOn" type="number" min="1000" max="9999" inputmode="numeric" class="mt-1 w-full rounded-xl px-4 py-3"></label></div><label class="block text-sm md:col-span-2">{{ __('curricula.fields.pdf_file') }}<input wire:model="resourcePdf" type="file" accept="application/pdf" class="mt-1 w-full rounded-xl px-4 py-3"><span wire:loading wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-amber-300"><span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>{{ __('curricula.fields.pdf_uploading') }}</span>@if($resourcePdf)<span wire:loading.remove wire:target="resourcePdf" class="mt-2 inline-flex items-center gap-2 text-sm text-emerald-300">✓ {{ __('curricula.fields.pdf_ready') }}</span>@endif</label>@error('resourcePdf')<div data-pdf-upload-error-for="resourcePdf" class="text-sm text-red-400 md:col-span-2">{{ $message }}</div>@enderror<div class="flex items-center gap-2 md:col-span-2"><button x-bind:disabled="uploadingPdf" wire:loading.attr="disabled" wire:target="resourcePdf" class="pill-link pill-link--accent disabled:cursor-wait disabled:opacity-50">{{ __('curricula.actions.save') }}</button>@if($editingResourceId)<button type="button" wire:click="deleteResource({{ $editingResourceId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--danger">{{ __('crud.common.actions.delete') }}</button>@endif</div></form></x-admin.modal>
</div>
