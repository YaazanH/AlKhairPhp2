<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Support\RoleRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new class extends Component {
    use AuthorizesPermissions;
    use SupportsCreateAndNew;
    use WithPagination;

    public string $selected_role = '';
    public array $selected_permissions = [];
    public string $role_search = '';
    public string $permission_search = '';
    public int $perPage = 15;
    public bool $showRoleModal = false;
    public bool $showPermissionsModal = false;
    public string $editing_role = '';
    public string $role_name = '';
    public string $clone_role = '';

    public function mount(): void
    {
        $this->authorizePermission('roles.manage');
        $this->selected_role = Role::query()->where('name', RoleRegistry::TEACHER)->exists()
            ? RoleRegistry::TEACHER
            : (RoleRegistry::sortCollection(Role::query()->get())->first()?->name ?? '');
        $this->loadRolePermissions();
    }

    public function updatedSelectedRole(): void
    {
        $this->loadRolePermissions();
    }

    public function updatedRoleSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $permissions = Permission::query()->orderBy('name')->get();
        $rolesQuery = $this->rolesQuery();

        if (filled($this->permission_search)) {
            $needle = Str::lower($this->permission_search);

            $permissions = $permissions->filter(fn (Permission $permission): bool => Str::contains(Str::lower($permission->name), $needle)
                || Str::contains(Str::lower($this->permissionLabel($permission->name)), $needle));
        }

        $filteredRolesCount = (clone $rolesQuery)->count();
        $systemRolesCount = (clone $rolesQuery)
            ->whereIn('name', RoleRegistry::systemRoles())
            ->count();

        return [
            'roles' => $rolesQuery->paginate($this->perPage),
            'filteredRolesCount' => $filteredRolesCount,
            'systemRolesCount' => $systemRolesCount,
            'customRolesCount' => max($filteredRolesCount - $systemRolesCount, 0),
            'permissionGroups' => $permissions
                ->groupBy(fn (Permission $permission): string => $this->permissionGroupLabel($permission->name)),
            'selectedRoleRecord' => $this->selected_role !== ''
                ? Role::query()->withCount(['users', 'permissions'])->where('name', $this->selected_role)->first()
                : null,
        ];
    }

    public function selectRole(string $roleName): void
    {
        $this->selected_role = $roleName;
        $this->loadRolePermissions();
    }

    public function openPermissionsModal(string $roleName): void
    {
        $this->authorizePermission('roles.manage');

        $this->selectRole($roleName);
        $this->showPermissionsModal = true;
        $this->resetValidation();
    }

    public function closePermissionsModal(): void
    {
        $this->showPermissionsModal = false;
        $this->permission_search = '';
        $this->resetValidation();
    }

    public function openCreateRoleModal(): void
    {
        $this->authorizePermission('roles.manage');

        $this->editing_role = '';
        $this->role_name = '';
        $this->clone_role = '';
        $this->showRoleModal = true;
        $this->resetValidation();
    }

    public function openEditRoleModal(string $roleName): void
    {
        $this->authorizePermission('roles.manage');

        $role = Role::findByName($roleName, 'web');

        $this->editing_role = $role->name;
        $this->role_name = Str::of($role->name)->replace('_', ' ')->headline()->toString();
        $this->clone_role = '';
        $this->showRoleModal = true;
        $this->resetValidation();
    }

    public function closeRoleModal(): void
    {
        $this->showRoleModal = false;
        $this->editing_role = '';
        $this->role_name = '';
        $this->clone_role = '';
        $this->resetValidation();
    }

    public function saveRole(): void
    {
        $this->authorizePermission('roles.manage');

        $validated = Validator::make(
            ['role_name' => $this->role_name, 'clone_role' => $this->clone_role],
            [
                'role_name' => ['required', 'string', 'max:255'],
                'clone_role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            ]
        )->validate();

        $normalizedName = Str::of($validated['role_name'])->trim()->snake()->toString();

        if ($normalizedName === '') {
            $this->addError('role_name', __('validation.required', ['attribute' => __('access.roles.fields.name')]));

            return;
        }

        if ($this->editing_role !== '' && $this->isSystemRole($this->editing_role) && $normalizedName !== $this->editing_role) {
            $this->addError('role_name', __('access.roles.errors.protected'));

            return;
        }

        $existing = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $normalizedName)
            ->when($this->editing_role !== '', function ($query) {
                $currentId = Role::query()->where('guard_name', 'web')->where('name', $this->editing_role)->value('id');

                if ($currentId) {
                    $query->where('id', '!=', $currentId);
                }
            })
            ->exists();

        if ($existing) {
            $this->addError('role_name', __('validation.unique', ['attribute' => __('access.roles.fields.name')]));

            return;
        }

        if ($this->editing_role !== '') {
            $role = Role::findByName($this->editing_role, 'web');
            $role->name = $normalizedName;
            $role->save();
            $message = __('access.roles.messages.updated');
        } else {
            $role = Role::findOrCreate($normalizedName, 'web');

            if (filled($validated['clone_role'] ?? null)) {
                $cloneRole = Role::findByName($validated['clone_role'], 'web');
                $role->syncPermissions($cloneRole->permissions->pluck('name')->all());
            }

            $message = __('access.roles.messages.created');
        }

        $this->selected_role = $normalizedName;
        $this->loadRolePermissions();
        $this->closeRoleModal();

        session()->flash('status', $message);
    }

    public function deleteRole(string $roleName): void
    {
        $this->authorizePermission('roles.manage');

        $role = Role::findByName($roleName, 'web');

        if ($this->isSystemRole($role->name)) {
            $this->addError('role_delete', __('access.roles.errors.protected'));

            return;
        }

        if ($role->users()->exists()) {
            $this->addError('role_delete', __('access.roles.errors.delete_linked'));

            return;
        }

        $role->delete();

        if ($this->selected_role === $roleName) {
            $this->selected_role = RoleRegistry::sortCollection(Role::query()->get())->first()?->name ?? '';
            $this->loadRolePermissions();
        }

        session()->flash('status', __('access.roles.messages.deleted'));
    }

    public function save(): void
    {
        $this->authorizePermission('roles.manage');

        if ($this->selected_role === '') {
            return;
        }

        $role = Role::findByName($this->selected_role, 'web');
        $role->syncPermissions($this->selected_permissions);

        session()->flash('status', __('access.roles.messages.saved'));

        $this->closePermissionsModal();
    }

    protected function isSystemRole(string $roleName): bool
    {
        return RoleRegistry::isSystemRole($roleName);
    }

    protected function loadRolePermissions(): void
    {
        $role = $this->selected_role !== ''
            ? Role::query()->where('name', $this->selected_role)->first()
            : null;

        $this->selected_permissions = $role?->permissions()->pluck('name')->values()->all() ?? [];
    }

    protected function permissionGroupLabel(string $permissionName): string
    {
        $group = Str::of($permissionName)->before('.')->toString();
        $labels = __('access.permission_groups');

        return is_array($labels) && isset($labels[$group])
            ? $labels[$group]
            : Str::of($group)->replace('-', ' ')->headline()->toString();
    }

    protected function permissionLabel(string $permissionName): string
    {
        $labels = __('access.permissions');

        return is_array($labels) && isset($labels[$permissionName])
            ? $labels[$permissionName]
            : Str::of($permissionName)->replace(['.', '-'], ' ')->headline()->toString();
    }

    protected function rolesQuery()
    {
        return Role::query()
            ->withCount(['users', 'permissions'])
            ->when(filled($this->role_search), fn ($query) => $query->where('name', 'like', '%'.$this->role_search.'%'))
            ->orderByRaw("
                case
                    when name = ? then 0
                    when name = ? then 1
                    when name = ? then 2
                    when name = ? then 3
                    when name = ? then 4
                    when name = ? then 5
                    else 99
                end
            ", [
                RoleRegistry::SUPER_ADMIN,
                RoleRegistry::ADMIN,
                RoleRegistry::MANAGER,
                RoleRegistry::TEACHER,
                RoleRegistry::PARENT,
                RoleRegistry::STUDENT,
            ])
            ->orderBy('name');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.configuration') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('access.roles.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('access.roles.subtitle') }}</p>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.access-control" />

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @error('role_delete')
        <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
    @enderror

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('access.roles.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('access.roles.editor.subtitle') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="role-search">{{ __('access.roles.fields.search') }}</label>
                    <input id="role-search" wire:model.live.debounce.300ms="role_search" type="text" placeholder="{{ __('access.roles.fields.search') }}">
                </div>

                <div class="admin-toolbar__actions">
                    <button type="button" wire:click="openCreateRoleModal" class="pill-link pill-link--accent">
                        {{ __('access.roles.actions.create') }}
                    </button>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('access.roles.stats.total') }}</div>
            <div class="metric-value mt-3">{{ number_format($filteredRolesCount) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('access.roles.stats.system') }}</div>
            <div class="metric-value mt-3">{{ number_format($systemRolesCount) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('access.roles.stats.custom') }}</div>
            <div class="metric-value mt-3">{{ number_format($customRolesCount) }}</div>
        </article>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('access.common.roles') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredRolesCount)]) }}</div>
            </div>
        </div>

        @if ($roles->isEmpty())
            <div class="admin-empty-state">{{ __('access.roles.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.role') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.users') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.permissions') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.type') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('access.roles.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($roles as $role)
                            @php
                                $isSystemRole = RoleRegistry::isSystemRole($role->name);
                            @endphp
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white"><x-admin.role-label :name="$role->name" /></div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $role->name }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($role->users_count) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($role->permissions_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $isSystemRole ? 'status-chip status-chip--gold' : 'status-chip status-chip--emerald' }}">
                                        {{ $isSystemRole ? __('access.roles.types.system') : __('access.roles.types.custom') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button type="button" wire:click="openPermissionsModal('{{ $role->name }}')" class="pill-link pill-link--compact">
                                            {{ __('access.roles.actions.permissions') }}
                                        </button>
                                        <button type="button" wire:click="openEditRoleModal('{{ $role->name }}')" class="pill-link pill-link--compact">
                                            {{ __('access.roles.actions.edit') }}
                                        </button>
                                        @unless ($isSystemRole)
                                            <button type="button" wire:click="deleteRole('{{ $role->name }}')" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                                {{ __('access.roles.actions.delete') }}
                                            </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($roles->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $roles->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showRoleModal"
        :title="$editing_role !== '' ? __('access.roles.actions.edit') : __('access.roles.actions.create')"
        :description="$editing_role !== '' && $this->isSystemRole($editing_role) ? __('access.roles.help.system_role') : __('access.roles.help.custom_role')"
        close-method="closeRoleModal"
        max-width="2xl"
    >
        <div class="space-y-4">
            <div>
                <label for="role-name" class="mb-1 block text-sm font-medium">{{ __('access.roles.fields.name') }}</label>
                <input id="role-name" wire:model="role_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('role_name')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
                @if (filled($role_name))
                    <div class="mt-2 text-xs text-neutral-400">{{ __('access.roles.help.machine_name', ['name' => \Illuminate\Support\Str::of($role_name)->trim()->snake()->toString()]) }}</div>
                @endif
            </div>

            @if ($editing_role === '')
                <div>
                    <label for="clone-role" class="mb-1 block text-sm font-medium">{{ __('access.roles.fields.clone_from') }}</label>
                    <select id="clone-role" wire:model="clone_role" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('access.roles.options.none') }}</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->name }}"><x-admin.role-label :name="$role->name" /></option>
                        @endforeach
                    </select>
                    <div class="mt-2 text-xs text-neutral-400">{{ __('access.roles.help.clone_from') }}</div>
                    @error('clone_role')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="saveRole" class="pill-link pill-link--accent">
                    {{ $editing_role !== '' ? __('access.roles.actions.edit') : __('access.roles.actions.create') }}
                </button>
                <x-admin.create-and-new-button :show="$editing_role === ''" click="saveAndNew('saveRole', 'openCreateRoleModal')" />
                <button type="button" wire:click="closeRoleModal" class="pill-link">
                    {{ __('access.roles.actions.cancel') }}
                </button>
            </div>
        </div>
    </x-admin.modal>

    <x-admin.modal
        :show="$showPermissionsModal"
        :title="__('access.roles.editor.title')"
        :description="$selectedRoleRecord ? __('access.roles.editor.subtitle') : __('access.roles.editor.empty')"
        close-method="closePermissionsModal"
        max-width="6xl"
    >
        @if ($selectedRoleRecord)
            <div class="space-y-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="font-display text-2xl text-white"><x-admin.role-label :name="$selectedRoleRecord->name" /></h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="status-chip status-chip--slate">{{ __('access.roles.editor.counts', ['permissions' => number_format($selectedRoleRecord->permissions_count), 'users' => number_format($selectedRoleRecord->users_count)]) }}</span>
                            <span class="status-chip {{ $this->isSystemRole($selectedRoleRecord->name) ? 'status-chip--gold' : 'status-chip--emerald' }}">
                                {{ $this->isSystemRole($selectedRoleRecord->name) ? __('access.roles.types.system') : __('access.roles.types.custom') }}
                            </span>
                        </div>
                        <p class="mt-3 text-sm leading-7 text-neutral-300">
                            {{ $this->isSystemRole($selectedRoleRecord->name) ? __('access.roles.help.system_role') : __('access.roles.help.custom_role') }}
                        </p>
                    </div>

                    @if ($selectedRoleRecord->name === RoleRegistry::SUPER_ADMIN)
                        <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                            {{ __('access.roles.help.super_admin') }}
                        </div>
                    @endif
                </div>

                <div class="admin-filter-field">
                    <label for="permission-search">{{ __('access.roles.fields.permission_search') }}</label>
                    <input id="permission-search" wire:model.live.debounce.300ms="permission_search" type="text" placeholder="{{ __('access.roles.fields.permission_search') }}">
                </div>

                <div class="space-y-4">
                    @foreach ($permissionGroups as $group => $permissions)
                        <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                            <div class="text-sm font-semibold text-white">{{ $group }}</div>
                            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($permissions as $permission)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="selected_permissions" type="checkbox" value="{{ $permission->name }}" class="mt-0.5 rounded">
                                        <span>{{ $this->permissionLabel($permission->name) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="save" class="pill-link pill-link--accent">{{ __('access.roles.actions.save') }}</button>
                    <button type="button" wire:click="closePermissionsModal" class="pill-link">{{ __('access.roles.actions.cancel') }}</button>
                </div>
            </div>
        @else
            <div class="admin-empty-state">{{ __('access.roles.editor.empty') }}</div>
        @endif
    </x-admin.modal>
</div>
