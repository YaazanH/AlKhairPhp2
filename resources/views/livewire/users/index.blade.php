<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\ManagedUserService;
use App\Support\RoleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

    public ?int $editingId = null;
    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $phone = '';
    public string $password = '';
    public bool $is_active = true;
    public array $roles = [];
    public array $direct_permissions = [];
    public array $scope_groups = [];
    public array $scope_students = [];
    public array $scope_teachers = [];
    public array $scope_parents = [];
    public string $search = '';
    public string $roleFilter = 'all';
    public string $statusFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;

    public function mount(): void
    {
        $this->authorizePermission('users.view');
    }

    public function with(): array
    {
        $filteredQuery = User::query()
            ->with(['roles', 'permissions', 'teacherProfile', 'parentProfile', 'studentProfile', 'scopeOverrides'])
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('username', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->roleFilter !== 'all', fn ($query) => $query->role($this->roleFilter))
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->orderBy('name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'users' => $filteredQuery->paginate($this->perPage),
            'filteredCount' => $filteredCount,
            'availableRoles' => RoleRegistry::sortCollection(Role::query()->get()),
            'availableScopeGroups' => Group::query()->with('course')->orderBy('name')->get(),
            'availableScopeParents' => ParentProfile::query()->withCount('students')->orderBy('father_name')->get(),
            'availableScopeStudents' => Student::query()->with('parentProfile')->orderBy('last_name')->orderBy('first_name')->get(),
            'availableScopeTeachers' => Teacher::query()->orderBy('first_name')->orderBy('last_name')->get(),
            'permissionGroups' => Permission::query()
                ->orderBy('name')
                ->get()
                ->groupBy(fn (Permission $permission): string => $this->permissionGroupLabel($permission->name)),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
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
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->editingId)],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:255', Rule::unique('users', 'phone')->ignore($this->editingId)],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'direct_permissions' => ['nullable', 'array'],
            'direct_permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'scope_groups' => ['nullable', 'array'],
            'scope_groups.*' => ['integer', Rule::exists('groups', 'id')],
            'scope_students' => ['nullable', 'array'],
            'scope_students.*' => ['integer', Rule::exists('students', 'id')],
            'scope_teachers' => ['nullable', 'array'],
            'scope_teachers.*' => ['integer', Rule::exists('teachers', 'id')],
            'scope_parents' => ['nullable', 'array'],
            'scope_parents.*' => ['integer', Rule::exists('parents', 'id')],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('users.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'users.update' : 'users.create');

        $validated = $this->validate();
        $accountService = app(ManagedUserService::class);
        $existingUser = $this->editingId ? User::query()->findOrFail($this->editingId) : null;
        $username = filled($validated['username'] ?? null)
            ? $accountService->uniqueUsername((string) $validated['username'], $validated['name'], $this->editingId)
            : ($existingUser?->username ?: $accountService->uniqueUsername('', $validated['name'], $this->editingId));
        $email = filled($validated['email'] ?? null)
            ? $accountService->uniqueEmail((string) $validated['email'], $username, $this->editingId)
            : ($existingUser?->email ?: $accountService->uniqueEmail(null, $username, $this->editingId));
        $plainPassword = filled($validated['password'] ?? null) ? (string) $validated['password'] : ($this->editingId ? null : Str::password(10));

        $payload = [
            'name' => $validated['name'],
            'username' => $username,
            'email' => $email,
            'phone' => filled($validated['phone']) ? $validated['phone'] : null,
            'is_active' => $validated['is_active'],
            'email_verified_at' => $existingUser?->email_verified_at ?? now(),
        ];

        if ($plainPassword !== null) {
            $payload['password'] = Hash::make($plainPassword);
            $payload['issued_password'] = $plainPassword;
        }

        $user = User::query()->updateOrCreate(
            ['id' => $this->editingId],
            $payload,
        );

        $user->syncRoles($validated['roles']);
        $user->syncPermissions($validated['direct_permissions'] ?? []);
        app(AccessScopeService::class)->syncUserOverrides($user, [
            'group' => $validated['scope_groups'] ?? [],
            'parent' => $validated['scope_parents'] ?? [],
            'student' => $validated['scope_students'] ?? [],
            'teacher' => $validated['scope_teachers'] ?? [],
        ], Auth::id());

        session()->flash('status', $this->editingId ? __('access.users.messages.updated') : __('access.users.messages.created'));

        if ($plainPassword !== null) {
            session()->flash('generated_credentials', [
                'login' => $user->username ?: $user->email ?: $user->phone,
                'password' => $plainPassword,
            ]);
        }

        $this->cancel();
    }

    public function edit(int $userId): void
    {
        $this->authorizePermission('users.update');

        $user = User::query()->with(['roles', 'permissions', 'scopeOverrides'])->findOrFail($userId);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->username = $user->username ?? '';
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
        $this->password = '';
        $this->is_active = $user->is_active;
        $this->roles = $user->getRoleNames()->values()->all();
        $this->direct_permissions = $user->getDirectPermissions()->pluck('name')->values()->all();
        $this->scope_groups = $user->scopeOverrides->where('scope_type', 'group')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all();
        $this->scope_parents = $user->scopeOverrides->where('scope_type', 'parent')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all();
        $this->scope_students = $user->scopeOverrides->where('scope_type', 'student')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all();
        $this->scope_teachers = $user->scopeOverrides->where('scope_type', 'teacher')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all();
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->username = '';
        $this->email = '';
        $this->phone = '';
        $this->password = '';
        $this->is_active = true;
        $this->roles = [];
        $this->direct_permissions = [];
        $this->scope_groups = [];
        $this->scope_students = [];
        $this->scope_teachers = [];
        $this->scope_parents = [];
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function delete(int $userId): void
    {
        $this->authorizePermission('users.delete');

        $user = User::query()->with(['teacherProfile', 'parentProfile', 'studentProfile'])->findOrFail($userId);

        if (Auth::id() === $user->id) {
            $this->addError('delete', __('access.users.errors.delete_self'));

            return;
        }

        if ($user->teacherProfile || $user->parentProfile || $user->studentProfile) {
            $this->addError('delete', __('access.users.errors.delete_linked_profile'));

            return;
        }

        $user->delete();

        if ($this->editingId === $userId) {
            $this->cancel();
        }

        session()->flash('status', __('access.users.messages.deleted'));
    }

    public function profileLabel(User $user): string
    {
        if ($user->teacherProfile) {
            return __('ui.roles.teacher');
        }

        if ($user->parentProfile) {
            return __('ui.roles.parent');
        }

        if ($user->studentProfile) {
            return __('ui.roles.student');
        }

        return __('crud.common.not_available');
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
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.people') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('access.users.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('access.users.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if (session('generated_credentials'))
        <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            {{ __('access.profile_accounts.messages.credentials', session('generated_credentials')) }}
        </div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('access.users.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('access.users.subtitle') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="user-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="user-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="user-role-filter">{{ __('access.users.filters.role') }}</label>
                    <select id="user-role-filter" wire:model.live="roleFilter">
                        <option value="all">{{ __('access.users.filters.all_roles') }}</option>
                        @foreach ($availableRoles as $availableRole)
                            <option value="{{ $availableRole->name }}">{{ __('ui.roles.'.$availableRole->name) === 'ui.roles.'.$availableRole->name ? \Illuminate\Support\Str::of($availableRole->name)->replace('_', ' ')->headline()->toString() : __('ui.roles.'.$availableRole->name) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="user-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="user-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('users.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('users.export', ['search' => $search, 'role' => $roleFilter, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>
    </section>

    @php
        $linkedProfilesCount = $users->filter(fn (User $user): bool => $user->teacherProfile || $user->parentProfile || $user->studentProfile)->count();
        $activeUsersCount = $users->where('is_active', true)->count();
    @endphp

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('access.users.stats.total') }}</div>
            <div class="metric-value mt-3">{{ number_format($filteredCount) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('access.users.stats.active') }}</div>
            <div class="metric-value mt-3">{{ number_format($activeUsersCount) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('access.users.stats.linked_profiles') }}</div>
            <div class="metric-value mt-3">{{ number_format($linkedProfilesCount) }}</div>
        </article>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('access.users.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($users->isEmpty())
            <div class="admin-empty-state">{{ __('access.users.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-6 py-4 text-left">{{ __('access.users.table.headers.user') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('access.users.table.headers.roles') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('access.users.table.headers.permissions') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('access.users.table.headers.profile') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('access.users.table.headers.status') }}</th>
                            <th class="px-6 py-4 text-right">{{ __('access.users.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($users as $user)
                            @php
                                $roleNames = $user->getRoleNames()->values();
                                $directPermissionNames = $user->permissions->pluck('name')->values();
                            @endphp
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="admin-identity-stack">
                                        <div class="admin-identity-stack__title">{{ $user->name }}</div>
                                        <div class="admin-identity-stack__meta">
                                            <span>{{ $user->username }}</span>
                                            <span>{{ $user->email }}</span>
                                            @if ($user->phone)
                                                <span>{{ $user->phone }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-neutral-300">
                                    @if ($roleNames->isEmpty())
                                        {{ __('crud.common.not_available') }}
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($roleNames as $roleName)
                                                <span class="status-chip status-chip--slate"><x-admin.role-label :name="$roleName" /></span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-neutral-300">
                                    @if ($directPermissionNames->isEmpty())
                                        {{ __('access.users.table.none') }}
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($directPermissionNames->take(3) as $permissionName)
                                                <span class="status-chip status-chip--slate">{{ $this->permissionLabel($permissionName) }}</span>
                                            @endforeach
                                            @if ($directPermissionNames->count() > 3)
                                                <span class="status-chip status-chip--slate">+{{ $directPermissionNames->count() - 3 }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-neutral-300">{{ $this->profileLabel($user) }}</td>
                                <td class="px-6 py-4"><span class="status-chip {{ $user->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $user->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}</span></td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-end gap-2">
                                        @can('users.update')
                                            <button type="button" wire:click="edit({{ $user->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                        @endcan
                                        @can('users.delete')
                                            <button type="button" wire:click="delete({{ $user->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $users->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('access.users.form.edit') : __('access.users.form.create')"
        :description="__('access.users.subtitle')"
        close-method="cancel"
        max-width="6xl"
    >
        <form wire:submit="save" class="space-y-4">
            <section class="admin-section-card">
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ __('access.users.sections.identity') }}</div>
                    <p class="admin-section-card__copy">{{ __('access.users.help.password') }}</p>
                </div>

                <div class="admin-form-grid">
                    <div class="admin-form-field admin-form-field--full">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.name') }}</label>
                        <input wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('name')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.username') }}</label>
                        <input wire:model="username" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('username')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.email') }}</label>
                        <input wire:model="email" type="email" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('email')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.phone') }}</label>
                        <input wire:model="phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('phone')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.password') }}</label>
                        <input wire:model="password" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('password')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <label class="mt-2 flex items-center gap-3 text-sm">
                    <input wire:model="is_active" type="checkbox" class="rounded">
                    <span>{{ __('access.users.fields.is_active') }}</span>
                </label>
            </section>

            <section class="admin-section-card">
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ __('access.users.sections.access') }}</div>
                    <p class="admin-section-card__copy">{{ __('access.users.help.permissions') }}</p>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">{{ __('access.users.fields.roles') }}</div>
                    <div class="mt-2 text-sm text-neutral-400">{{ __('access.users.help.roles') }}</div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        @foreach ($availableRoles as $availableRole)
                            <label class="flex items-center gap-3 rounded-2xl border border-white/8 px-3 py-3 text-sm text-neutral-200">
                                <input wire:model="roles" type="checkbox" value="{{ $availableRole->name }}" class="rounded">
                                <span><x-admin.role-label :name="$availableRole->name" /></span>
                            </label>
                        @endforeach
                    </div>
                    @error('roles')
                        <div class="mt-2 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">{{ __('access.users.fields.permissions') }}</div>
                    <div class="mt-2 text-sm text-neutral-400">{{ __('access.users.help.permissions') }}</div>
                    <div class="mt-4 space-y-4">
                        @foreach ($permissionGroups as $group => $permissions)
                            <div class="rounded-2xl border border-white/8 p-4">
                                <div class="text-sm font-semibold text-white">{{ $group }}</div>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    @foreach ($permissions as $permission)
                                        <label class="flex items-start gap-3 text-sm text-neutral-200">
                                            <input wire:model="direct_permissions" type="checkbox" value="{{ $permission->name }}" class="mt-0.5 rounded">
                                            <span>{{ $this->permissionLabel($permission->name) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="admin-section-card">
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ __('access.users.sections.scope') }}</div>
                    <p class="admin-section-card__copy">{{ __('access.users.help.scope') }}</p>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">{{ __('access.users.scopes.title') }}</div>
                    <p class="mt-2 text-sm text-neutral-400">{{ __('access.users.scopes.help') }}</p>

                    <div class="mt-4 space-y-4">
                        <div class="rounded-2xl border border-white/8 p-4">
                            <div class="text-sm font-semibold text-white">{{ __('access.users.scopes.groups') }}</div>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeGroups as $scopeGroup)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_groups" type="checkbox" value="{{ $scopeGroup->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeGroup->name }}{{ $scopeGroup->course ? ' | '.$scopeGroup->course->name : '' }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-2xl border border-white/8 p-4">
                            <div class="text-sm font-semibold text-white">{{ __('access.users.scopes.students') }}</div>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeStudents as $scopeStudent)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_students" type="checkbox" value="{{ $scopeStudent->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeStudent->first_name }} {{ $scopeStudent->last_name }}{{ $scopeStudent->parentProfile?->father_name ? ' | '.$scopeStudent->parentProfile->father_name : '' }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-2xl border border-white/8 p-4">
                            <div class="text-sm font-semibold text-white">{{ __('access.users.scopes.teachers') }}</div>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeTeachers as $scopeTeacher)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_teachers" type="checkbox" value="{{ $scopeTeacher->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeTeacher->first_name }} {{ $scopeTeacher->last_name }}</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-2xl border border-white/8 p-4">
                            <div class="text-sm font-semibold text-white">{{ __('access.users.scopes.parents') }}</div>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @forelse ($availableScopeParents as $scopeParent)
                                    <label class="flex items-start gap-3 text-sm text-neutral-200">
                                        <input wire:model="scope_parents" type="checkbox" value="{{ $scopeParent->id }}" class="mt-0.5 rounded">
                                        <span>{{ $scopeParent->father_name }} ({{ $scopeParent->students_count }})</span>
                                    </label>
                                @empty
                                    <div class="text-sm text-neutral-400">{{ __('access.users.scopes.empty') }}</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ $editingId ? __('access.users.form.save_update') : __('access.users.form.save_create') }}</button>
                <x-admin.create-and-new-button :show="! $editingId" />
                <button type="button" wire:click="cancel" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
