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
        $permissionGroups = $permissions
            ->groupBy(fn (Permission $permission): string => $this->permissionGroupLabel($permission->name));
        $collator = class_exists(\Collator::class) ? new \Collator(app()->getLocale()) : null;
        $permissionGroups = $permissionGroups->sortKeysUsing(function (string $left, string $right) use ($collator): int {
            if ($collator) {
                return $collator->compare($left, $right) ?: strcmp($left, $right);
            }

            return strnatcmp(
                Str::lower(\App\Support\ArabicSearch::normalize($left)),
                Str::lower(\App\Support\ArabicSearch::normalize($right)),
            );
        });

        return [
            'roles' => $rolesQuery->paginate($this->perPage),
            'filteredRolesCount' => $filteredRolesCount,
            'systemRolesCount' => $systemRolesCount,
            'customRolesCount' => max($filteredRolesCount - $systemRolesCount, 0),
            'permissionGroups' => $permissionGroups,
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

            $orderedRoles = RoleRegistry::sortCollection(
                Role::query()->where('id', '!=', $role->id)->get()
            )->push($role);
            $this->persistRoleOrder($orderedRoles);

            $message = __('access.roles.messages.created');
        }

        $this->selected_role = $normalizedName;
        $this->loadRolePermissions();
        $this->closeRoleModal();

        session()->flash('status', $message);
    }

    public function deleteRole(string $roleName): bool
    {
        $this->authorizePermission('roles.manage');

        $role = Role::findByName($roleName, 'web');

        if ($this->isSystemRole($role->name)) {
            $this->addError('role_delete', __('access.roles.errors.protected'));

            return false;
        }

        if ($role->users()->exists()) {
            $this->addError('role_delete', __('access.roles.errors.delete_linked'));

            return false;
        }

        $role->delete();

        if ($this->selected_role === $roleName) {
            $this->selected_role = RoleRegistry::sortCollection(Role::query()->get())->first()?->name ?? '';
            $this->loadRolePermissions();
        }

        session()->flash('status', __('access.roles.messages.deleted'));

        return true;
    }

    public function moveRole(string $roleName, string $beforeRoleName): void
    {
        $this->authorizePermission('roles.manage');

        if (
            $roleName === $beforeRoleName
            || in_array($roleName, RoleRegistry::fixedBoundaryRoles(), true)
            || in_array($beforeRoleName, [RoleRegistry::SUPER_ADMIN, RoleRegistry::STUDENT], true)
        ) {
            return;
        }

        $roles = RoleRegistry::sortCollection(Role::query()->get());

        if (! $roles->contains('name', $roleName) || ! $roles->contains('name', $beforeRoleName)) {
            return;
        }

        $role = $roles->firstWhere('name', $roleName);
        $orderedRoles = $roles
            ->reject(fn (Role $candidate): bool => $candidate->name === $roleName)
            ->values();
        $position = $orderedRoles->search(fn (Role $candidate): bool => $candidate->name === $beforeRoleName);
        $orderedRoles->splice($position === false ? $orderedRoles->count() : $position, 0, [$role]);

        $this->persistRoleOrder($orderedRoles);
    }

    public function deleteEditingRole(): void
    {
        if ($this->editing_role === '') {
            return;
        }

        if ($this->deleteRole($this->editing_role)) {
            $this->closeRoleModal();
        }
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

    protected function roleLabel(string $roleName): string
    {
        $translationKey = 'ui.roles.'.$roleName;
        $translated = __($translationKey);

        return $translated === $translationKey
            ? Str::of($roleName)->replace('_', ' ')->headline()->toString()
            : $translated;
    }

    protected function persistRoleOrder($roles): void
    {
        $roles = RoleRegistry::pinFixedRolePositions(collect($roles));
        $count = $roles->count();

        foreach ($roles as $index => $role) {
            $role->forceFill(['level' => ($count - $index) * 100])->saveQuietly();
        }
    }

    protected function rolesQuery()
    {
        return Role::query()
            ->withCount(['users', 'permissions'])
            ->when(filled($this->role_search), fn ($query) => $query->where('name', 'like', '%'.$this->role_search.'%'))
            ->orderByRaw(
                'case when name = ? then 0 when name = ? then 2 when name = ? then 3 else 1 end',
                [RoleRegistry::SUPER_ADMIN, RoleRegistry::PARENT, RoleRegistry::STUDENT],
            )
            ->orderByDesc('level')
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

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('ui.common.settings') }}</h1>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.access-control" />

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" x-data="{ draggedRole: null, roleDropTarget: null, settledRole: null }">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
            <div class="admin-grid-meta__title">{{ __('access.common.roles') }}</div>
            <div class="access-role-table-controls flex flex-wrap items-end gap-3" data-mobile-table-filter-controls><div class="admin-filter-field"><label class="sr-only" for="role-search">{{ __('access.roles.fields.search') }}</label><input id="role-search" wire:model.live.debounce.300ms="role_search" type="text" placeholder="{{ __('access.roles.fields.search') }}"></div><x-add-action-button wire:click="openCreateRoleModal" :label="__('access.roles.actions.create')" /></div>
        </div>

        @if ($roles->isEmpty())
            <div class="admin-empty-state">{{ __('access.roles.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.role') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.users') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.permissions') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('access.roles.table.headers.type') }}</th>
                            <th class="admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('access.roles.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($roles as $role)
                            @php
                                $isSystemRole = RoleRegistry::isSystemRole($role->name);
                                $hasFixedPosition = in_array($role->name, RoleRegistry::fixedBoundaryRoles(), true);
                                $canReceiveRoleDrop = ! in_array($role->name, [RoleRegistry::SUPER_ADMIN, RoleRegistry::STUDENT], true);
                            @endphp
                            <tr
                                wire:key="role-row-{{ $role->id }}"
                                class="role-sort-row"
                                :class="{
                                    'role-sort-row--dragging': draggedRole === @js($role->name),
                                    'role-sort-row--drop-target': roleDropTarget === @js($role->name),
                                    'role-sort-row--settled': settledRole === @js($role->name)
                                }"
                                @if ($canReceiveRoleDrop)
                                    @dragenter.prevent="if (draggedRole && draggedRole !== @js($role->name)) roleDropTarget = @js($role->name)"
                                    @dragover.prevent
                                    @drop.prevent="if (draggedRole && draggedRole !== @js($role->name)) { const movingRole = draggedRole; roleDropTarget = @js($role->name); $wire.moveRole(movingRole, @js($role->name)).then(() => { draggedRole = null; roleDropTarget = null; settledRole = movingRole; setTimeout(() => settledRole = null, 320) }) }"
                                @endif
                            >
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex items-center gap-3">
                                        @if ($hasFixedPosition)
                                            <span class="role-sort-handle role-sort-handle--locked" title="{{ __('access.roles.actions.fixed_order') }}" aria-label="{{ __('access.roles.actions.fixed_order') }}">◆</span>
                                        @else
                                            <button
                                                type="button"
                                                draggable="true"
                                                @dragstart.stop="draggedRole = @js($role->name); roleDropTarget = null"
                                                @dragend="draggedRole = null; roleDropTarget = null"
                                                class="role-sort-handle"
                                                title="{{ __('access.roles.actions.reorder') }}"
                                                aria-label="{{ __('access.roles.actions.reorder') }}"
                                            >⠿</button>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="font-semibold text-white"><x-admin.role-label :name="$role->name" /></div>
                                            <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $role->name }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($role->users_count) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($role->permissions_count) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $isSystemRole ? 'status-chip status-chip--gold' : 'status-chip status-chip--emerald' }}">
                                        {{ $isSystemRole ? __('access.roles.types.system') : __('access.roles.types.custom') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="admin-action-cluster">
                                        <button
                                            type="button"
                                            wire:click="openPermissionsModal('{{ $role->name }}')"
                                            class="admin-icon-button"
                                            title="{{ __('access.roles.actions.permissions') }}"
                                            aria-label="{{ __('access.roles.actions.permissions') }}"
                                            data-role-permissions-action
                                        >
                                            <x-admin-action-icon name="permissions" />
                                        </button>
                                        <x-edit-action-button wire:click="openEditRoleModal('{{ $role->name }}')" :label="__('access.roles.actions.edit')" data-role-edit-action />
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
        close-method="closeRoleModal"
        :max-width="$editing_role !== '' ? 'sm' : '2xl'"
    >
        <div class="space-y-4">
            <div>
                <label for="role-name" class="mb-1 block text-sm font-medium">{{ __('access.roles.fields.name') }}</label>
                <input id="role-name" wire:model="role_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('role_name')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
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
                    @error('clone_role')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            @error('role_delete')
                <div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
            @enderror

            <div class="flex flex-wrap items-center gap-3">
                @if ($editing_role === '')
                    <x-admin.create-and-new-button click="saveAndNew('saveRole', 'openCreateRoleModal')" />
                    <button type="button" wire:click="closeRoleModal" class="pill-link">
                        {{ __('access.roles.actions.cancel') }}
                    </button>
                @else
                    <button type="button" wire:click="saveRole" class="admin-icon-button admin-icon-button--accent admin-modal-action-button" title="{{ __('access.roles.actions.edit') }}" aria-label="{{ __('access.roles.actions.edit') }}" data-role-save-action>
                        <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                    </button>
                    @unless ($this->isSystemRole($editing_role))
                        <x-delete-action-button wire:click="deleteEditingRole" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('access.roles.actions.delete')" data-role-delete-action />
                    @endunless
                @endif
            </div>
        </div>
    </x-admin.modal>

    <x-admin.modal
        :show="$showPermissionsModal"
        :title="$selectedRoleRecord ? $this->roleLabel($selectedRoleRecord->name) : ''"
        close-method="closePermissionsModal"
        max-width="6xl"
    >
        <x-slot:header-actions>
            @if ($selectedRoleRecord)
                <button type="button" wire:click="save" class="admin-modal__close" title="{{ __('access.roles.actions.save') }}" aria-label="{{ __('access.roles.actions.save') }}" data-permissions-save-icon>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3.75h11.25L19.5 7v13.25H5V3.75Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 3.75v5.5h8v-5.5M8.25 20.25v-6.5h8v6.5" />
                    </svg>
                </button>
            @endif
        </x-slot:header-actions>
        @if ($selectedRoleRecord)
            <div class="space-y-5">
                @if ($selectedRoleRecord->name === RoleRegistry::SUPER_ADMIN)
                    <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                        {{ __('access.roles.help.super_admin') }}
                    </div>
                @endif

                <div class="admin-filter-field">
                    <label class="sr-only" for="permission-search">{{ __('access.roles.fields.permission_search') }}</label>
                    <input id="permission-search" wire:model.live.debounce.300ms="permission_search" type="text" placeholder="{{ __('access.roles.fields.permission_search') }}">
                </div>

                <div class="role-permission-groups">
                    @foreach ($permissionGroups as $group => $permissions)
                        <details
                            class="role-permission-group"
                            wire:key="role-permission-group-{{ md5($group) }}"
                            @if (filled($permission_search)) open @endif
                        >
                            <summary class="role-permission-group__summary">
                                <span class="role-permission-group__title">{{ $group }}</span>
                                <span class="role-permission-group__arrow" aria-hidden="true">{{ app()->isLocale('ar') ? '‹' : '›' }}</span>
                            </summary>
                            <div class="role-permission-group__body">
                                <div class="role-permission-grid" data-permission-group-rows="3">
                                @foreach ($permissions as $permission)
                                    <label class="role-permission-option">
                                        <input wire:model="selected_permissions" type="checkbox" value="{{ $permission->name }}" class="rounded">
                                        <span>{{ $this->permissionLabel($permission->name) }}</span>
                                    </label>
                                @endforeach
                                </div>
                            </div>
                        </details>
                    @endforeach
                </div>
            </div>
        @else
            <div class="admin-empty-state">{{ __('access.roles.editor.empty') }}</div>
        @endif
    </x-admin.modal>
</div>
