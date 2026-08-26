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
use App\Support\PhoneNumberFormatter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new class extends Component
{
    use AuthorizesPermissions;
    use SupportsCreateAndNew;
    use WithFileUploads;
    use WithPagination;

    public ?int $editingId = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $profile_photo_path = '';

    public string $profile_photo_url = '';

    public $profile_photo_upload = null;

    public string $finance_signature_url = '';

    public $finance_signature_upload = null;

    public bool $is_active = true;

    public array $roles = [];

    public array $direct_permissions = [];

    public array $scope_groups = [];

    public array $scope_students = [];

    public array $scope_teachers = [];

    public array $scope_parents = [];

    public string $search = '';

    public string $profileFilter = 'all';

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
                $normalizedPhone = PhoneNumberFormatter::normalize($this->search);
                $query->where(function ($builder) use ($normalizedPhone) {
                    $builder
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('username', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%')
                        ->when($normalizedPhone, fn ($query) => $query->orWhere('phone', 'like', '%'.$normalizedPhone.'%'));
                });
            })
            ->when($this->profileFilter === 'student', fn ($query) => $query->whereHas('studentProfile'))
            ->when($this->profileFilter === 'parent', fn ($query) => $query->whereHas('parentProfile'))
            ->when($this->profileFilter === 'teacher', fn ($query) => $query->whereHas('teacherProfile'))
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

    public function updatedProfileFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUsername(string $value): void
    {
        $this->email = filled($value)
            ? app(ManagedUserService::class)->uniqueEmail(null, trim($value), $this->editingId)
            : '';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->editingId)],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:255', Rule::unique('users', 'phone')->ignore($this->editingId)],
            'password' => ['nullable', 'string', 'min:8'],
            'profile_photo_upload' => ['nullable', 'image', 'max:'.config('uploads.image_max_kb')],
            'finance_signature_upload' => ['nullable', 'file', 'mimes:png', 'max:4096'],
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
        $this->phone = PhoneNumberFormatter::normalize($this->phone) ?? '';

        $validated = $this->validate();
        $accountService = app(ManagedUserService::class);
        $existingUser = $this->editingId ? User::query()->with('teacherProfile')->findOrFail($this->editingId) : null;
        abort_if($existingUser?->teacherProfile, 403);
        $username = filled($validated['username'] ?? null)
            ? $accountService->uniqueUsername((string) $validated['username'], $validated['name'], $this->editingId)
            : ($existingUser?->username ?: $accountService->uniqueUsername('', $validated['name'], $this->editingId));
        $email = $accountService->uniqueEmail(null, $username, $this->editingId);
        $plainPassword = filled($validated['password'] ?? null)
            ? (string) $validated['password']
            : ($this->editingId ? null : $accountService->generatePassword());

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

        if ($this->profile_photo_upload) {
            $user->storeProfilePhotoUpload($this->profile_photo_upload);
        }

        $user->syncRoles($validated['roles']);
        $user->syncPermissions($validated['direct_permissions'] ?? []);
        if ($this->finance_signature_upload && $this->hasFullFinancialAccess($user)) {
            $user->storeFinanceSignatureUpload($this->finance_signature_upload);
        }
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

        $user = User::query()->with(['roles', 'permissions', 'scopeOverrides', 'studentProfile', 'teacherProfile', 'parentProfile'])->findOrFail($userId);
        abort_if($user->teacherProfile, 403);

        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->username = $user->username ?? '';
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
        $this->password = '';
        $this->profile_photo_path = $user->profilePhotoPath() ?? '';
        $this->profile_photo_url = $user->profilePhotoUrl() ?? '';
        $this->profile_photo_upload = null;
        $this->finance_signature_url = $user->financeSignatureUrl() ?? '';
        $this->finance_signature_upload = null;
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
        $this->profile_photo_path = '';
        $this->profile_photo_url = '';
        $this->profile_photo_upload = null;
        $this->finance_signature_url = '';
        $this->finance_signature_upload = null;
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

    public function deleteEditingUser(): void
    {
        if (! $this->editingId) {
            return;
        }

        $this->delete($this->editingId);
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

    public function formUserHasFullFinancialAccess(): bool
    {
        $permissionNames = collect($this->direct_permissions)->merge(
            Role::query()
                ->whereIn('name', $this->roles)
                ->with('permissions:id,name')
                ->get()
                ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
        );

        return $permissionNames->contains('finance.entries.update')
            && $permissionNames->contains('finance.reports.export');
    }

    protected function hasFullFinancialAccess(User $user): bool
    {
        return $user->can('finance.entries.update') && $user->can('finance.reports.export');
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
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if (session('generated_credentials'))
        <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            {{ __('access.profile_accounts.messages.credentials', session('generated_credentials')) }}
        </div>
    @endif

    @php
        $linkedProfilesCount = $users->filter(fn (User $user): bool => $user->teacherProfile || $user->parentProfile || $user->studentProfile)->count();
        $activeUsersCount = $users->where('is_active', true)->count();
    @endphp

    <section class="admin-kpi-grid mobile-compact-highlights">
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

    <section class="surface-table mobile-records-surface standard-mobile-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('access.users.title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                <div class="admin-filter-field">
                    <label class="sr-only" for="user-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="user-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>
                <div class="admin-filter-field">
                    <label class="sr-only" for="user-profile-filter">{{ __('access.users.filters.profile') }}</label>
                    <select id="user-profile-filter" wire:model.live="profileFilter" data-user-profile-filter>
                        <option value="all">{{ __('access.users.filters.all_profiles') }}</option>
                        <option value="student">{{ __('ui.roles.student') }}</option>
                        <option value="parent">{{ __('ui.roles.parent') }}</option>
                        <option value="teacher">{{ __('ui.roles.teacher') }}</option>
                    </select>
                </div>
                <div class="admin-filter-field">
                    <label class="sr-only" for="user-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="user-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>
                <div class="admin-toolbar__actions">
                    <a href="{{ route('users.export', ['search' => $search, 'profile' => $profileFilter, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($users->isEmpty())
            <div class="admin-empty-state">{{ __('access.users.table.empty') }}</div>
        @else
            <div class="responsive-records-mobile">
                @foreach ($users as $user)
                    @php
                        $roleNames = RoleRegistry::sortCollection($user->roles)->pluck('name');
                        $directPermissionNames = $user->permissions->pluck('name')->values();
                    @endphp
                    <article class="mobile-record-card">
                        <div class="mobile-record-card__header">
                            <div class="student-inline min-w-0">
                                <x-user-avatar :user="$user" size="sm" />
                                <div class="student-inline__body min-w-0">
                                    <div class="student-inline__name">{{ $user->name }}</div>
                                    <div class="student-inline__meta">{{ $user->username }}</div>
                                </div>
                            </div>
                            <span class="status-chip {{ $user->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $user->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}</span>
                        </div>

                        <dl class="mobile-record-card__details">
                            <div>
                                <dt>{{ __('access.users.table.headers.roles') }}</dt>
                                <dd>
                                    @if ($roleNames->isEmpty())
                                        {{ __('crud.common.not_available') }}
                                    @else
                                        <span class="mobile-record-card__chips">
                                            @foreach ($roleNames as $roleName)
                                                <span class="status-chip status-chip--slate"><x-admin.role-label :name="$roleName" /></span>
                                            @endforeach
                                        </span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>{{ __('access.users.table.headers.profile') }}</dt>
                                <dd>{{ $this->profileLabel($user) }}</dd>
                            </div>
                            <div class="mobile-record-card__detail--wide">
                                <dt>{{ __('access.users.table.headers.permissions') }}</dt>
                                <dd>
                                    @if ($directPermissionNames->isEmpty())
                                        {{ __('access.users.table.none') }}
                                    @else
                                        <span class="mobile-record-card__chips">
                                            @foreach ($directPermissionNames->take(2) as $permissionName)
                                                <span class="status-chip status-chip--slate">{{ $this->permissionLabel($permissionName) }}</span>
                                            @endforeach
                                            @if ($directPermissionNames->count() > 2)
                                                <span class="status-chip status-chip--slate">+{{ $directPermissionNames->count() - 2 }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if (! $user->teacherProfile)
                            @can('users.update')
                                <div class="mobile-record-card__actions">
                                    <button type="button" wire:click="edit({{ $user->id }})" class="pill-link pill-link--compact" data-user-edit-action="{{ $user->id }}">{{ __('crud.common.actions.edit') }}</button>
                                </div>
                            @endcan
                        @endif
                    </article>
                @endforeach
            </div>

            <div class="responsive-records-desktop overflow-x-auto">
                <table class="users-index-table table-fixed text-sm">
                    <colgroup><col class="w-[24%]"><col class="w-[18%]"><col class="w-[24%]"><col class="w-[12%]"><col class="w-[10%]"><col class="w-[12%]"></colgroup>
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
                                $roleNames = RoleRegistry::sortCollection($user->roles)->pluck('name');
                                $directPermissionNames = $user->permissions->pluck('name')->values();
                            @endphp
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-user-avatar :user="$user" size="sm" />
                                        <div class="admin-identity-stack">
                                            <div class="admin-identity-stack__title">{{ $user->name }}</div>
                                            <div class="admin-identity-stack__meta">
                                                <span>{{ $user->username }}</span>
                                            </div>
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
                                        @if (! $user->teacherProfile)
                                            @can('users.update')
                                                <button type="button" wire:click="edit({{ $user->id }})" class="pill-link pill-link--compact" data-user-edit-action="{{ $user->id }}">{{ __('crud.common.actions.edit') }}</button>
                                            @endcan
                                        @endif
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
        close-method="cancel"
        max-width="6xl"
    >
        <form wire:submit="save" class="space-y-4" data-user-form>
            <section class="admin-section-card" data-user-identity-box>
                <div class="admin-form-grid" data-user-identity-grid>
                    <div class="admin-form-field">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.name') }}</label>
                        <input wire:model="name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('name')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.username') }}</label>
                        <input wire:model.live.debounce.300ms="username" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('username')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field">
                        <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.phone') }}</label>
                        <x-phone-input model="phone" :value="$phone" />
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

                @if ($editingId)
                    <label class="mt-2 flex items-center gap-3 text-sm" data-user-active-toggle>
                        <input wire:model="is_active" type="checkbox" class="rounded">
                        <span>{{ __('access.users.fields.is_active') }}</span>
                    </label>
                @endif
            </section>

            <section class="admin-section-card" data-user-role-box>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($availableRoles as $availableRole)
                        <label class="flex items-center gap-3 rounded-2xl border border-white/8 px-3 py-3 text-sm text-neutral-200">
                            <input wire:model.live="roles" type="checkbox" value="{{ $availableRole->name }}" class="rounded">
                            <span><x-admin.role-label :name="$availableRole->name" /></span>
                        </label>
                    @endforeach
                </div>
                @error('roles')
                    <div class="text-sm text-red-400">{{ $message }}</div>
                @enderror
            </section>

            <details
                class="admin-collapsible"
                data-user-media
                @if ($errors->has('profile_photo_upload') || $errors->has('finance_signature_upload')) open @endif
            >
                <summary class="admin-collapsible__summary">
                    <span>{{ __('access.users.sections.media') }}</span>
                </summary>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="grid gap-4 rounded-2xl border border-white/8 p-4 md:grid-cols-[auto_minmax(0,1fr)] md:items-center">
                        <span class="student-avatar student-avatar--lg">
                            @if ($profile_photo_upload)
                                <img src="{{ $profile_photo_upload->temporaryUrl() }}" alt="{{ __('access.users.fields.profile_photo') }}" class="student-avatar__image">
                            @elseif ($profile_photo_url)
                                <img src="{{ $profile_photo_url }}" alt="{{ __('access.users.fields.profile_photo') }}" class="student-avatar__image">
                            @else
                                <span class="student-avatar__fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($name ?: 'U', 0, 1)) }}</span>
                            @endif
                        </span>
                        <div class="min-w-0">
                            <label class="mb-1 block text-sm font-medium">{{ __('access.users.fields.profile_photo') }}</label>
                            <input wire:model="profile_photo_upload" type="file" accept="image/*" class="block w-full text-sm text-neutral-300">
                            @error('profile_photo_upload')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    @if ($this->formUserHasFullFinancialAccess())
                        <div class="grid gap-4 rounded-2xl border border-white/8 p-4 md:grid-cols-[minmax(0,10rem)_minmax(0,1fr)] md:items-center">
                            <div class="grid min-h-24 place-items-center rounded-2xl bg-white p-3">
                                @if ($finance_signature_upload)
                                    <img src="{{ $finance_signature_upload->temporaryUrl() }}" alt="" class="max-h-20 max-w-full object-contain">
                                @elseif ($finance_signature_url)
                                    <img src="{{ $finance_signature_url }}" alt="" class="max-h-20 max-w-full object-contain">
                                @else
                                    <span class="text-xs text-neutral-500">{{ __('access.users.help.finance_signature_empty') }}</span>
                                @endif
                            </div>
                            <div class="min-w-0">
                                <label class="mb-1 block text-sm font-semibold text-white">{{ __('access.users.fields.finance_signature') }}</label>
                                <input wire:model="finance_signature_upload" type="file" accept="image/png" class="block w-full text-sm text-neutral-300">
                                @error('finance_signature_upload')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    @endif
                </div>
            </details>

            <section class="admin-section-card" data-user-access-overrides-box>
                <details
                    class="admin-collapsible"
                    data-user-direct-permissions
                    @if ($errors->has('direct_permissions') || $errors->has('direct_permissions.*')) open @endif
                >
                    <summary class="admin-collapsible__summary">
                        <span>{{ __('access.users.fields.permissions') }}</span>
                        <span class="admin-collapsible__count">{{ count($direct_permissions) }}/{{ $permissionGroups->flatten(1)->count() }}</span>
                    </summary>
                    <div>
                        <div class="space-y-4">
                        @foreach ($permissionGroups as $group => $permissions)
                            @php
                                $selectedPermissionCount = $permissions->pluck('name')->intersect($direct_permissions)->count();
                            @endphp
                            <details class="admin-collapsible">
                                <summary class="admin-collapsible__summary">
                                    <span>{{ $group }}</span>
                                    <span class="admin-collapsible__count">{{ $selectedPermissionCount }}/{{ $permissions->count() }}</span>
                                </summary>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    @foreach ($permissions as $permission)
                                        <label class="flex items-start gap-3 text-sm text-neutral-200">
                                            <input wire:model.live="direct_permissions" type="checkbox" value="{{ $permission->name }}" class="mt-0.5 rounded">
                                            <span>{{ $this->permissionLabel($permission->name) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                        </div>
                    </div>
                </details>
                <details
                    class="admin-collapsible"
                    data-user-scope-overrides
                    @if ($errors->has('scope_groups') || $errors->has('scope_students') || $errors->has('scope_teachers') || $errors->has('scope_parents')) open @endif
                >
                    <summary class="admin-collapsible__summary">
                        <span>{{ __('access.users.sections.scope') }}</span>
                        <span class="admin-collapsible__count">
                            {{ count($scope_groups) + count($scope_students) + count($scope_teachers) + count($scope_parents) }}/{{ $availableScopeGroups->count() + $availableScopeStudents->count() + $availableScopeTeachers->count() + $availableScopeParents->count() }}
                        </span>
                    </summary>
                    <div>
                        <div class="space-y-4">
                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.groups') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_groups) }}/{{ $availableScopeGroups->count() }}</span>
                            </summary>
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
                        </details>

                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.students') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_students) }}/{{ $availableScopeStudents->count() }}</span>
                            </summary>
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
                        </details>

                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.teachers') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_teachers) }}/{{ $availableScopeTeachers->count() }}</span>
                            </summary>
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
                        </details>

                        <details class="admin-collapsible">
                            <summary class="admin-collapsible__summary">
                                <span>{{ __('access.users.scopes.parents') }}</span>
                                <span class="admin-collapsible__count">{{ count($scope_parents) }}/{{ $availableScopeParents->count() }}</span>
                            </summary>
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
                        </details>
                        </div>
                    </div>
                </details>
            </section>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ $editingId ? __('access.users.form.save_update') : __('access.users.form.save_create') }}</button>
                <x-admin.create-and-new-button :show="! $editingId" />
                @if ($editingId)
                    @can('users.delete')
                        <button type="button" wire:click="deleteEditingUser" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                    @endcan
                @endif
            </div>
            @error('delete')
                <div class="text-sm text-red-300">{{ $message }}</div>
            @enderror
        </form>
    </x-admin.modal>
</div>
