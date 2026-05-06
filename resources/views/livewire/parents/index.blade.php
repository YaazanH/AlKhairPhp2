<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\ParentProfile;
use App\Services\ManagedUserService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public string $father_name = '';
    public string $father_work = '';
    public string $father_phone = '';
    public string $mother_name = '';
    public string $mother_phone = '';
    public string $home_phone = '';
    public string $address = '';
    public string $notes = '';
    public bool $is_active = true;
    public ?int $accountParentId = null;
    public string $account_username = '';
    public string $account_email = '';
    public string $account_password = '';
    public bool $account_is_active = true;
    public ?string $issued_password = null;
    public string $search = '';
    public string $statusFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showAccountModal = false;

    public function mount(): void
    {
        $this->authorizePermission('parents.view');
    }

    public function with(): array
    {
        $baseQuery = $this->scopeParentsQuery(ParentProfile::query());
        $filteredQuery = $this->scopeParentsQuery(ParentProfile::query())
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('father_name', 'like', '%'.$this->search.'%')
                        ->orWhere('mother_name', 'like', '%'.$this->search.'%')
                        ->orWhere('father_phone', 'like', '%'.$this->search.'%')
                        ->orWhere('mother_phone', 'like', '%'.$this->search.'%')
                        ->orWhere('home_phone', 'like', '%'.$this->search.'%');
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->withCount('students')
            ->orderBy('father_name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'parents' => $filteredQuery->paginate($this->perPage),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeParentsQuery(ParentProfile::query()->where('is_active', true))->count(),
            ],
            'filteredCount' => $filteredCount,
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function rules(): array
    {
        return [
            'father_name' => ['required', 'string', 'max:255'],
            'father_work' => ['nullable', 'string', 'max:255'],
            'father_phone' => ['nullable', 'string', 'max:30'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_phone' => ['nullable', 'string', 'max:30'],
            'home_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function accountRules(): array
    {
        return [
            'account_username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->linkedUserId())],
            'account_email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->linkedUserId())],
            'account_password' => ['nullable', 'string', 'min:8'],
            'account_is_active' => ['boolean'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('parents.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'parents.update' : 'parents.create');

        if ($this->editingId) {
            $this->authorizeScopedParentAccess(ParentProfile::query()->findOrFail($this->editingId));
        }

        $validated = $this->validate();
        $parent = ParentProfile::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        $result = app(ManagedUserService::class)->syncLinkedUser(
            $parent->user,
            [
                'name' => $validated['father_name'],
                'phone' => $validated['father_phone'] ?: ($validated['mother_phone'] ?: ($validated['home_phone'] ?: null)),
                'is_active' => $parent->user?->is_active ?? (bool) $validated['is_active'],
            ],
            'parent',
        );

        $parent->user()->associate($result['user']);
        $parent->save();

        if ($result['credentials']['password']) {
            session()->flash('generated_credentials', $result['credentials']);
        }

        session()->flash(
            'status',
            $this->editingId ? __('crud.parents.messages.updated') : __('crud.parents.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $parentId): void
    {
        $this->authorizePermission('parents.update');

        $parent = ParentProfile::query()->findOrFail($parentId);
        $this->authorizeScopedParentAccess($parent);

        $this->editingId = $parent->id;
        $this->father_name = $parent->father_name;
        $this->father_work = $parent->father_work ?? '';
        $this->father_phone = $parent->father_phone ?? '';
        $this->mother_name = $parent->mother_name ?? '';
        $this->mother_phone = $parent->mother_phone ?? '';
        $this->home_phone = $parent->home_phone ?? '';
        $this->address = $parent->address ?? '';
        $this->notes = $parent->notes ?? '';
        $this->is_active = $parent->is_active;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function openAccountModal(int $parentId): void
    {
        $this->authorizePermission('parents.update');

        $parent = ParentProfile::query()->findOrFail($parentId);
        $this->authorizeScopedParentAccess($parent);

        $this->accountParentId = $parent->id;
        $this->account_username = $parent->user?->username ?? '';
        $this->account_email = $parent->user?->email ?? '';
        $this->account_password = '';
        $this->account_is_active = $parent->user?->is_active ?? $parent->is_active;
        $this->issued_password = $parent->user?->issued_password;
        $this->showAccountModal = true;

        $this->resetValidation([
            'account_username',
            'account_email',
            'account_password',
            'account_is_active',
        ]);
    }

    public function generateAccountPassword(): void
    {
        $this->authorizePermission('parents.update');

        $this->account_password = app(ManagedUserService::class)->generatePassword();
    }

    public function saveAccount(): void
    {
        $this->authorizePermission('parents.update');

        $parent = ParentProfile::query()->findOrFail($this->accountParentId);
        $this->authorizeScopedParentAccess($parent);

        $validated = $this->validate($this->accountRules());
        $result = app(ManagedUserService::class)->syncLinkedUser(
            $parent->user,
            [
                'name' => $parent->father_name,
                'username' => $validated['account_username'] ?: null,
                'email' => $validated['account_email'] ?: null,
                'phone' => $parent->father_phone ?: ($parent->mother_phone ?: ($parent->home_phone ?: null)),
                'password' => $validated['account_password'] ?: null,
                'is_active' => (bool) $validated['account_is_active'],
            ],
            'parent',
        );

        $parent->user()->associate($result['user']);
        $parent->save();

        $this->account_username = $result['user']->username ?? '';
        $this->account_email = $result['user']->email ?? '';
        $this->account_password = '';
        $this->account_is_active = $result['user']->is_active;
        $this->issued_password = $result['user']->issued_password;

        if ($result['credentials']['password']) {
            session()->flash('generated_credentials', $result['credentials']);
        }

        session()->flash('status', __('access.profile_accounts.messages.saved'));
    }

    public function closeAccountModal(): void
    {
        $this->accountParentId = null;
        $this->account_username = '';
        $this->account_email = '';
        $this->account_password = '';
        $this->account_is_active = true;
        $this->issued_password = null;
        $this->showAccountModal = false;

        $this->resetValidation([
            'account_username',
            'account_email',
            'account_password',
            'account_is_active',
        ]);
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->father_name = '';
        $this->father_work = '';
        $this->father_phone = '';
        $this->mother_name = '';
        $this->mother_phone = '';
        $this->home_phone = '';
        $this->address = '';
        $this->notes = '';
        $this->is_active = true;
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function delete(int $parentId): void
    {
        $this->authorizePermission('parents.delete');

        $parent = ParentProfile::query()->with('user')->withCount('students')->findOrFail($parentId);
        $this->authorizeScopedParentAccess($parent);

        if ($parent->students_count > 0) {
            $this->addError('delete', __('crud.parents.errors.delete_linked'));

            return;
        }

        $linkedUser = $parent->user;
        $parent->delete();
        $linkedUser?->delete();

        if ($this->editingId === $parentId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.parents.messages.deleted'));
    }

    protected function linkedUserId(): ?int
    {
        $profileId = $this->accountParentId ?? $this->editingId;

        return $profileId
            ? ParentProfile::query()->whereKey($profileId)->value('user_id')
            : null;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.people') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.parents.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.parents.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if (session('generated_credentials'))
        <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            {{ __('access.profile_accounts.messages.credentials', session('generated_credentials')) }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.parents.stats.all') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.parents.stats.active') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
        </article>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('crud.parents.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('crud.parents.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="parent-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="parent-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="parent-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="parent-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('parents.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('parents.export', ['search' => $search, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('crud.parents.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($parents->isEmpty())
            <div class="admin-empty-state">{{ __('crud.parents.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.father') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.mother') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.students') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.phone') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.parents.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($parents as $parent)
                            <tr>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $parent->father_name }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $parent->mother_name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ number_format($parent->students_count) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $parent->father_phone ?: ($parent->mother_phone ?: $parent->home_phone ?: __('crud.common.not_available')) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $parent->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ $parent->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @can('parents.update')
                                            <button type="button" wire:click="openAccountModal({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.account') }}</button>
                                        @endcan
                                        @can('parents.update')
                                            <button type="button" wire:click="edit({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                        @endcan
                                        @can('parents.delete')
                                            <button type="button" wire:click="delete({{ $parent->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($parents->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $parents->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.parents.form.edit_title') : __('crud.parents.form.create_title')"
        :description="__('crud.parents.form.help')"
        close-method="cancel"
        max-width="4xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div>
                <label for="father-name" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_name') }}</label>
                <input id="father-name" wire:model="father_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('father_name')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="father-work" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_work') }}</label>
                    <input id="father-work" wire:model="father_work" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('father_work')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="father-phone" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_phone') }}</label>
                    <input id="father-phone" wire:model="father_phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('father_phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="mother-name" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.mother_name') }}</label>
                    <input id="mother-name" wire:model="mother_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('mother_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="mother-phone" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.mother_phone') }}</label>
                    <input id="mother-phone" wire:model="mother_phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('mother_phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="home-phone" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.home_phone') }}</label>
                    <input id="home-phone" wire:model="home_phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('home_phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="parent-address" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.address') }}</label>
                    <input id="parent-address" wire:model="address" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('address')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div>
                <label for="parent-notes" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.notes') }}</label>
                <textarea id="parent-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.parents.form.active_profile') }}</span>
            </label>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.parents.form.update_submit') : __('crud.parents.form.create_submit') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingId" />
                <button type="button" wire:click="cancel" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showAccountModal"
        :title="__('access.profile_accounts.title')"
        :description="__('access.profile_accounts.description')"
        close-method="closeAccountModal"
        max-width="4xl"
    >
        <form wire:submit="saveAccount" class="space-y-4">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-white">{{ __('access.profile_accounts.sections.identity') }}</div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.username') }}</label>
                        <input wire:model="account_username" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('account_username')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                        <div class="mt-1 text-xs text-neutral-500">{{ __('access.profile_accounts.help.username') }}</div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.email') }}</label>
                        <input wire:model="account_email" type="email" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('account_email')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                        <div class="mt-1 text-xs text-neutral-500">{{ __('access.profile_accounts.help.email') }}</div>
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-3 text-sm">
                    <input wire:model="account_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                    <span>{{ __('access.profile_accounts.fields.is_active') }}</span>
                </label>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-white">{{ __('access.profile_accounts.sections.password') }}</div>
                <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('access.profile_accounts.help.issued_password') }}</p>

                <div class="mt-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_auto]">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.issued_password') }}</label>
                        <input type="text" readonly value="{{ $issued_password ?: __('access.profile_accounts.empty.issued_password') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>

                    <div class="flex items-end">
                        <button type="button" wire:click="generateAccountPassword" class="pill-link pill-link--compact">{{ __('access.profile_accounts.actions.generate_password') }}</button>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.password') }}</label>
                    <input wire:model="account_password" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('account_password')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                    <div class="mt-1 text-xs text-neutral-500">{{ __('access.profile_accounts.help.password') }}</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('access.profile_accounts.actions.save') }}</button>
                <button type="button" wire:click="closeAccountModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
