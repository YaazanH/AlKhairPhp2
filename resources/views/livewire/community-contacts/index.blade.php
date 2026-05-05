<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\CommunityContact;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public string $name = '';
    public string $category = '';
    public string $organization = '';
    public string $phone = '';
    public string $secondary_phone = '';
    public string $email = '';
    public string $address = '';
    public string $notes = '';
    public bool $is_active = true;
    public string $search = '';
    public string $categoryFilter = 'all';
    public string $statusFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;

    public function mount(): void
    {
        $this->authorizePermission('community-contacts.view');
    }

    public function with(): array
    {
        $baseQuery = CommunityContact::query();
        $filteredQuery = CommunityContact::query()
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('category', 'like', '%'.$this->search.'%')
                        ->orWhere('organization', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%')
                        ->orWhere('secondary_phone', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%')
                        ->orWhere('address', 'like', '%'.$this->search.'%')
                        ->orWhere('notes', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->categoryFilter !== 'all', fn ($query) => $query->where('category', $this->categoryFilter))
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('category')
            ->orderBy('name');

        return [
            'contacts' => $filteredQuery->paginate($this->perPage),
            'filteredCount' => (clone $filteredQuery)->count(),
            'categories' => CommunityContact::query()
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->values(),
            'totals' => [
                'all' => (clone $baseQuery)->count(),
                'active' => CommunityContact::query()->where('is_active', true)->count(),
                'inactive' => CommunityContact::query()->where('is_active', false)->count(),
                'categories' => CommunityContact::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->count('category'),
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'secondary_phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('community-contacts.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function edit(int $contactId): void
    {
        $this->authorizePermission('community-contacts.update');

        $contact = CommunityContact::query()->findOrFail($contactId);
        $this->editingId = $contact->id;
        $this->name = $contact->name;
        $this->category = $contact->category ?? '';
        $this->organization = $contact->organization ?? '';
        $this->phone = $contact->phone ?? '';
        $this->secondary_phone = $contact->secondary_phone ?? '';
        $this->email = $contact->email ?? '';
        $this->address = $contact->address ?? '';
        $this->notes = $contact->notes ?? '';
        $this->is_active = $contact->is_active;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'community-contacts.update' : 'community-contacts.create');

        $validated = $this->validate();

        CommunityContact::query()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => trim($validated['name']),
                'category' => filled($validated['category']) ? trim($validated['category']) : null,
                'organization' => filled($validated['organization']) ? trim($validated['organization']) : null,
                'phone' => filled($validated['phone']) ? trim($validated['phone']) : null,
                'secondary_phone' => filled($validated['secondary_phone']) ? trim($validated['secondary_phone']) : null,
                'email' => filled($validated['email']) ? trim($validated['email']) : null,
                'address' => filled($validated['address']) ? trim($validated['address']) : null,
                'notes' => filled($validated['notes']) ? trim($validated['notes']) : null,
                'is_active' => (bool) $validated['is_active'],
            ],
        );

        session()->flash('status', $this->editingId ? __('community_contacts.messages.updated') : __('community_contacts.messages.created'));

        $this->cancel();
    }

    public function delete(int $contactId): void
    {
        $this->authorizePermission('community-contacts.delete');

        CommunityContact::query()->findOrFail($contactId)->delete();

        if ($this->editingId === $contactId) {
            $this->cancel();
        }

        session()->flash('status', __('community_contacts.messages.deleted'));
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->category = '';
        $this->organization = '';
        $this->phone = '';
        $this->secondary_phone = '';
        $this->email = '';
        $this->address = '';
        $this->notes = '';
        $this->is_active = true;
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->categoryFilter = 'all';
        $this->statusFilter = 'all';
        $this->resetPage();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.people') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('community_contacts.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('community_contacts.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card"><div class="kpi-label">{{ __('community_contacts.stats.all') }}</div><div class="metric-value mt-3">{{ number_format($totals['all']) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('community_contacts.stats.active') }}</div><div class="metric-value mt-3">{{ number_format($totals['active']) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('community_contacts.stats.inactive') }}</div><div class="metric-value mt-3">{{ number_format($totals['inactive']) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('community_contacts.stats.categories') }}</div><div class="metric-value mt-3">{{ number_format($totals['categories']) }}</div></article>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('community_contacts.sections.directory.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('community_contacts.sections.directory.copy', ['count' => number_format($filteredCount)]) }}</p>
            </div>

            @can('community-contacts.create')
                <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('community_contacts.actions.create') }}</button>
            @endcan
        </div>

        <div class="mt-5 grid gap-3 lg:grid-cols-[minmax(0,1fr)_14rem_12rem_auto]">
            <input wire:model.live.debounce.300ms="search" type="search" class="rounded-xl px-4 py-3 text-sm" placeholder="{{ __('community_contacts.filters.search') }}">

            <select wire:model.live="categoryFilter" class="rounded-xl px-4 py-3 text-sm">
                <option value="all">{{ __('community_contacts.filters.all_categories') }}</option>
                @foreach ($categories as $categoryOption)
                    <option value="{{ $categoryOption }}">{{ $categoryOption }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" class="rounded-xl px-4 py-3 text-sm">
                <option value="all">{{ __('community_contacts.filters.all_statuses') }}</option>
                <option value="active">{{ __('community_contacts.statuses.active') }}</option>
                <option value="inactive">{{ __('community_contacts.statuses.inactive') }}</option>
            </select>

            <button type="button" wire:click="clearFilters" class="pill-link">{{ __('crud.common.actions.reset') }}</button>
        </div>

        <div class="mt-5 overflow-hidden rounded-2xl border border-white/10">
            <table class="min-w-full divide-y divide-white/10 text-sm">
                <thead class="bg-white/5 text-xs uppercase tracking-[0.2em] text-neutral-400">
                    <tr>
                        <th class="px-5 py-4 text-left">{{ __('community_contacts.table.headers.name') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('community_contacts.table.headers.category') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('community_contacts.table.headers.contact') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('community_contacts.table.headers.status') }}</th>
                        <th class="px-5 py-4 text-right">{{ __('community_contacts.table.headers.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse ($contacts as $contact)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="font-semibold text-white">{{ $contact->name }}</div>
                                @if ($contact->organization)
                                    <div class="mt-1 text-xs text-neutral-400">{{ $contact->organization }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-neutral-300">{{ $contact->category ?: __('community_contacts.empty.category') }}</td>
                            <td class="px-5 py-4 text-neutral-300">
                                @if ($contact->phone)
                                    <div dir="ltr" class="inline-block text-left" style="unicode-bidi: isolate;">{{ $contact->phone }}</div>
                                @endif
                                @if ($contact->email)
                                    <div class="mt-1 text-xs text-neutral-400">{{ $contact->email }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span @class([
                                    'rounded-full border px-3 py-1 text-xs font-semibold',
                                    'border-emerald-400/30 bg-emerald-500/10 text-emerald-100' => $contact->is_active,
                                    'border-neutral-500/30 bg-neutral-500/10 text-neutral-300' => ! $contact->is_active,
                                ])>
                                    {{ $contact->is_active ? __('community_contacts.statuses.active') : __('community_contacts.statuses.inactive') }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2">
                                    @can('community-contacts.update')
                                        <button type="button" wire:click="edit({{ $contact->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                    @endcan
                                    @can('community-contacts.delete')
                                        <button type="button" wire:click="delete({{ $contact->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact pill-link--danger">{{ __('crud.common.actions.delete') }}</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-neutral-400">{{ __('community_contacts.table.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            {{ $contacts->links() }}
        </div>
    </section>

    <x-admin.modal :show="$showFormModal" :title="$editingId ? __('community_contacts.form.edit') : __('community_contacts.form.create')" :description="__('community_contacts.form.description')" close-method="cancel" max-width="4xl">
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.name') }}</label>
                    <input wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.category') }}</label>
                    <input wire:model="category" type="text" list="community-contact-categories" class="w-full rounded-xl px-4 py-3 text-sm">
                    <datalist id="community-contact-categories">
                        @foreach ($categories as $categoryOption)
                            <option value="{{ $categoryOption }}"></option>
                        @endforeach
                    </datalist>
                    @error('category') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.organization') }}</label>
                    <input wire:model="organization" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('organization') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.email') }}</label>
                    <input wire:model="email" type="email" dir="ltr" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('email') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.phone') }}</label>
                    <input wire:model="phone" type="text" dir="ltr" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('phone') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.secondary_phone') }}</label>
                    <input wire:model="secondary_phone" type="text" dir="ltr" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('secondary_phone') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.address') }}</label>
                <textarea wire:model="address" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('address') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('community_contacts.fields.notes') }}</label>
                <textarea wire:model="notes" rows="3" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('community_contacts.fields.is_active') }}</span>
            </label>

            <div class="admin-action-cluster admin-action-cluster--end">
                <button type="button" wire:click="cancel" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <x-admin.create-and-new-button :show="! $editingId" click="saveAndNew('save', 'openCreateModal')" />
                <button type="submit" class="pill-link pill-link--accent">{{ $editingId ? __('community_contacts.actions.update') : __('community_contacts.actions.save') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
