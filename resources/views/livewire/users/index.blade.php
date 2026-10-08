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
use App\Support\ArabicSearch;
use App\Support\PhoneNumberFormatter;
use App\Support\RoleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
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

    public bool $scope_student_progress_all = false;

    public array $scope_groups = [];

    public array $scope_students = [];

    public array $scope_teachers = [];

    public array $scope_parents = [];

    public string $search = '';

    public string $profileFilter = 'all';

    public string $statusFilter = 'all';

    public int $perPage = 15;

    public bool $showFormModal = false;

    public bool $showPermissionsModal = false;

    #[Locked]
    public ?int $viewingAccountId = null;

    public function mount(): void
    {
        $this->authorizePermission('users.view');
    }

    public function with(): array
    {
        $filteredQuery = User::query()
            ->tenantManaged()
            ->with(['roles', 'permissions', 'teacherProfile', 'parentProfile', 'studentProfile', 'scopeOverrides'])
            ->when(filled($this->search), function ($query) {
                ArabicSearch::whereAllTokens($query, $this->search, function ($builder, string $token): void {
                    $search = '%'.$token.'%';
                    $normalizedPhone = PhoneNumberFormatter::normalize($token);
                    $builder
                        ->where('name', 'like', $search)
                        ->orWhere('username', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('phone', 'like', $search)
                        ->when($normalizedPhone, fn ($query) => $query->orWhere('phone', 'like', '%'.$normalizedPhone.'%'));
                });
            })
            ->when($this->profileFilter === 'standalone', fn ($query) => $query->whereDoesntHave('studentProfile')->whereDoesntHave('parentProfile')->whereDoesntHave('teacherProfile')->whereDoesntHave('roles', fn ($roles) => $roles->whereIn('name', RoleRegistry::actorRoles())))
            ->when($this->profileFilter === 'student', fn ($query) => $query->whereHas('studentProfile'))
            ->when($this->profileFilter === 'parent', fn ($query) => $query->whereHas('parentProfile'))
            ->when($this->profileFilter === 'teacher', fn ($query) => $query->whereHas('teacherProfile'))
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->orderBy('name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'viewedAccount' => $this->viewingAccountId ? User::tenantManaged()->with(['roles', 'teacherProfile', 'parentProfile', 'studentProfile'])->findOrFail($this->viewingAccountId) : null,
            'users' => $filteredQuery->paginate($this->perPage),
            'filteredCount' => $filteredCount,
            'availableRoles' => RoleRegistry::sortCollection(Role::query()->whereNotIn('name', RoleRegistry::actorRoles())->get()),
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
            'roles.*' => ['string', Rule::notIn(RoleRegistry::actorRoles()), Rule::exists('roles', 'name')],
            'direct_permissions' => ['nullable', 'array'],
            'direct_permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'scope_student_progress_all' => ['boolean'],
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
        $existingUser = $this->editingId ? User::query()->tenantManaged()->with(['teacherProfile', 'parentProfile', 'studentProfile'])->findOrFail($this->editingId) : null;
        abort_if($existingUser && $this->isProfileAccount($existingUser), 403);
        $this->phone = PhoneNumberFormatter::normalize($this->phone) ?? '';

        $validated = $this->validate();
        $accountService = app(ManagedUserService::class);
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
            AccessScopeService::ALL_STUDENT_PROGRESS => ($validated['scope_student_progress_all'] ?? false) ? [1] : [],
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

        $user = User::query()->tenantManaged()->with(['roles', 'permissions', 'scopeOverrides', 'studentProfile', 'teacherProfile', 'parentProfile'])->findOrFail($userId);
        abort_if($this->isProfileAccount($user), 403);

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
        $this->scope_student_progress_all = app(AccessScopeService::class)->canViewAllStudentProgress($user);
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
        $this->scope_student_progress_all = false;
        $this->scope_groups = [];
        $this->scope_students = [];
        $this->scope_teachers = [];
        $this->scope_parents = [];
        $this->showFormModal = false;
        $this->showPermissionsModal = false;
        $this->viewingAccountId = null;

        $this->resetValidation();
    }

    public function delete(int $userId): void
    {
        $this->authorizePermission('users.delete');

        $user = User::query()->tenantManaged()->with(['teacherProfile', 'parentProfile', 'studentProfile'])->findOrFail($userId);

        if (Auth::id() === $user->id) {
            $this->addError('delete', __('access.users.errors.delete_self'));

            return;
        }

        if ($this->isProfileAccount($user)) {
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

    public function isProfileAccount(User $user): bool
    {
        return $user->teacherProfile || $user->parentProfile || $user->studentProfile
            || $user->hasAnyRole(RoleRegistry::actorRoles())
            || $user->teacherProfile()->withTrashed()->exists()
            || $user->parentProfile()->withTrashed()->exists()
            || $user->studentProfile()->withTrashed()->exists();
    }

    public function viewLinkedAccount(int $userId): void
    {
        $this->authorizePermission('users.view');
        $user = User::tenantManaged()->with(['teacherProfile', 'parentProfile', 'studentProfile'])->findOrFail($userId);
        abort_unless($this->isProfileAccount($user), 403);
        $this->cancel();
        $this->viewingAccountId = $user->id;
    }

    public function closeLinkedAccount(): void
    {
        $this->cancel();
    }

    public function openAccountPermissions(): void
    {
        $this->authorizePermission('users.update');
        abort_unless($this->viewingAccountId, 404);
        $user = User::tenantManaged()->with('permissions')->findOrFail($this->viewingAccountId);
        $this->direct_permissions = $user->getDirectPermissions()->pluck('name')->all();
        $this->resetValidation();
        $this->showPermissionsModal = true;
    }

    public function closeAccountPermissions(): void
    {
        $this->showPermissionsModal = false;
        $this->resetValidation();
    }

    public function saveAccountPermissions(): void
    {
        $this->authorizePermission('users.update');
        abort_unless($this->viewingAccountId && $this->showPermissionsModal, 404);
        $validated = $this->validate([
            'direct_permissions' => ['nullable', 'array'],
            'direct_permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);
        DB::transaction(function () use ($validated): void {
            $user = User::tenantManaged()->lockForUpdate()->findOrFail($this->viewingAccountId);
            $user->syncPermissions($validated['direct_permissions'] ?? []);
        });
        $this->closeAccountPermissions();
        session()->flash('status', __('access.users.messages.permissions_saved'));
    }

    public function profileLabel(User $user): string
    {
        return collect(['teacher' => $user->teacherProfile || $user->hasRole('teacher'), 'parent' => $user->parentProfile || $user->hasRole('parent'), 'student' => $user->studentProfile || $user->hasRole('student')])
            ->filter()->keys()->map(fn ($role) => __('ui.roles.'.$role))->implode(' · ')
            ?: __('access.users.standalone');
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
                    <input id="user-search" wire:model.live.debounce.500ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>
                <div class="admin-filter-field">
                    <label class="sr-only" for="user-profile-filter">{{ __('access.users.filters.profile') }}</label>
                    <select id="user-profile-filter" wire:model.live="profileFilter" data-user-profile-filter>
                        <option value="all">{{ __('access.users.filters.all_profiles') }}</option>
                        <option value="standalone">{{ __('access.users.standalone') }}</option>
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
                    <x-export-action-button :href="route('users.export', ['search' => $search, 'profile' => $profileFilter, 'status' => $statusFilter])" :label="__('crud.common.actions.export')" />
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
                        $primaryRoleName = $user->primaryRoleName();
                        $directPermissionNames = $user->permissions->pluck('name')->values();
                    @endphp
                    <article class="mobile-record-card">
                        <div class="mobile-record-card__header">
                            <div class="student-inline min-w-0">
                                <x-user-avatar :user="$user" size="sm" />
                                <div class="student-inline__body min-w-0">
                                    <div class="record-person-name student-inline__name">{{ $user->name }}</div>
                                    <div class="student-inline__meta">{{ $user->username }}</div>
                                </div>
                            </div>
                            <span class="status-chip {{ $user->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $user->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}</span>
                        </div>

                        <dl class="mobile-record-card__details">
                            <div>
                                <dt>{{ __('access.users.table.headers.roles') }}</dt>
                                <dd>
                                    @if (! $primaryRoleName)
                                        {{ __('crud.common.not_available') }}
                                    @else
                                        <span class="status-chip status-chip--slate" data-user-primary-role="{{ $user->id }}" data-role="{{ $primaryRoleName }}"><x-admin.role-label :name="$primaryRoleName" /></span>
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

                        @if ($this->isProfileAccount($user))
                            <div class="mobile-record-card__actions"><x-user-profile-actions :user="$user" /></div>
                        @endif
                        @if (! $this->isProfileAccount($user))
                            @can('users.update')
                                <div class="mobile-record-card__actions">
                                    <x-edit-action-button wire:click="edit({{ $user->id }})" :label="__('crud.common.actions.edit')" data-user-edit-action="{{ $user->id }}" />
                                </div>
                            @endcan
                        @endif
                    </article>
                @endforeach
            </div>

            <div class="responsive-records-desktop overflow-x-auto">
                <table class="users-index-table table-content text-sm">
                    <thead>
                        <tr>

                            <th class="table-cell-name px-6 py-4 text-left">{{ __('access.users.table.headers.user') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('access.users.table.headers.roles') }}</th>
                            <th class="px-6 py-4 text-left">{{ __('access.users.table.headers.permissions') }}</th>
                            <th class="table-cell-compact px-6 py-4 text-left">{{ __('access.users.table.headers.profile') }}</th>
                            <th class="table-cell-compact px-6 py-4 text-left">{{ __('access.users.table.headers.status') }}</th>
                            <th class="table-cell-compact admin-actions-column px-6 py-4 text-center">{{ __('access.users.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($users as $user)
                            @php
                                $primaryRoleName = $user->primaryRoleName();
                                $directPermissionNames = $user->permissions->pluck('name')->values();
                            @endphp
                            <tr>

                                <td class="table-cell-name px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <x-user-avatar :user="$user" size="sm" />
                                        <div class="admin-identity-stack">
                                            <div class="record-person-name admin-identity-stack__title">{{ $user->name }}</div>
                                            <div class="admin-identity-stack__meta">
                                                <span>{{ $user->username }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-neutral-300">
                                    @if (! $primaryRoleName)
                                        {{ __('crud.common.not_available') }}
                                    @else
                                        <span class="status-chip status-chip--slate" data-user-primary-role="{{ $user->id }}" data-role="{{ $primaryRoleName }}"><x-admin.role-label :name="$primaryRoleName" /></span>
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
                                <td class="table-cell-compact px-6 py-4 text-sm text-neutral-300">{{ $this->profileLabel($user) }}</td>
                                <td class="table-cell-compact px-6 py-4"><span class="status-chip {{ $user->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $user->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}</span></td>
                                <td class="table-cell-compact px-6 py-4">
                                    <div class="flex justify-end gap-2">
                                        @if ($this->isProfileAccount($user))
                                            <x-user-profile-actions :user="$user" />
                                        @endif
                                        @if (! $this->isProfileAccount($user))
                                            @can('users.update')
                                                <x-edit-action-button wire:click="edit({{ $user->id }})" :label="__('crud.common.actions.edit')" data-user-edit-action="{{ $user->id }}" />
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

    <x-admin.modal :show="$viewingAccountId !== null && ! $showPermissionsModal" :title="__('access.profile_accounts.view_account')" close-method="closeLinkedAccount" max-width="fit" compact>
        <x-slot:headerActions>
            @can('users.update')
                <button type="button" wire:click="openAccountPermissions" class="admin-modal__close" title="{{ __('access.users.shared_permissions') }}" aria-label="{{ __('access.users.shared_permissions') }}" data-user-account-permissions-action><x-admin-action-icon name="permissions" class="size-5" /></button>
            @endcan
        </x-slot:headerActions>
        @if ($viewedAccount)
            <dl class="min-w-0 space-y-4" data-user-readonly-account="{{ $viewedAccount->id }}">
                @foreach (array_chunk([
                    __('access.users.fields.name') => $viewedAccount->name,
                    __('access.users.fields.username') => $viewedAccount->username,
                    __('access.users.fields.email') => $viewedAccount->email,
                    __('access.users.fields.phone') => $viewedAccount->phone,
                    __('access.users.fields.roles') => $viewedAccount->roles->map(fn ($role) => \Illuminate\Support\Facades\Lang::has('ui.roles.'.$role->name) ? __('ui.roles.'.$role->name) : $role->name)->implode(' · '),
                    __('access.users.fields.is_active') => $viewedAccount->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive'),
                ], 2, true) as $detailsRow)
                    <div class="grid min-w-0 gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-2">
                        @foreach ($detailsRow as $label => $value)
                            <div class="min-w-0">
                                <dt class="kpi-label">{{ $label }}</dt>
                                <dd @class(['mt-1 break-words text-white', 'record-person-name' => $label === __('access.users.fields.name'), 'record-phone' => $label === __('access.users.fields.phone')])><bdi dir="{{ in_array($label, [__('access.users.fields.phone'), __('access.users.fields.username'), __('access.users.fields.email')], true) ? 'ltr' : 'auto' }}">{{ $value ?: __('crud.common.not_available') }}</bdi></dd>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </dl>
        @endif
    </x-admin.modal>

    <x-admin.modal :show="$showPermissionsModal" :title="__('access.users.shared_permissions')" close-method="closeAccountPermissions" :dismissible="false" max-width="5xl">
        <x-slot:headerActions>
            <button type="submit" form="user-account-permissions-form" class="admin-modal__close" title="{{ __('access.users.form.save_update') }}" aria-label="{{ __('access.users.form.save_update') }}" data-user-account-permissions-save><x-admin-action-icon name="save" class="size-5" /></button>
        </x-slot:headerActions>
        <form id="user-account-permissions-form" wire:submit="saveAccountPermissions" class="space-y-4">
            <div class="record-person-name text-lg font-semibold">{{ $viewedAccount?->name }}</div>
            @include('livewire.users.partials.access-overrides', ['showScopeOverrides' => false])
            @if ($errors->any())
                <div class="text-sm text-red-400" role="alert">{{ $errors->first() }}</div>
            @endif
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('access.users.form.edit') : __('access.users.form.create')"
        close-method="cancel"
        max-width="6xl"
    >
        <form wire:submit="save" class="space-y-4" data-user-form>
            <p class="text-sm text-neutral-400">{{ __('access.users.creation_help') }}</p>
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
                                <x-avatar-image type="user" :src="$profile_photo_url" alt="{{ __('access.users.fields.profile_photo') }}" class="student-avatar__image" />
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

            @include('livewire.users.partials.access-overrides')

            <div class="admin-action-cluster admin-action-cluster--end">
                @if ($editingId)
                    <button type="submit" class="admin-icon-button admin-icon-button--accent admin-modal-action-button" title="{{ __('access.users.form.save_update') }}" aria-label="{{ __('access.users.form.save_update') }}" data-user-form-save-action>
                        <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                    </button>
                    @can('users.delete')
                        <x-delete-action-button wire:click="deleteEditingUser" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('crud.common.actions.delete')" class="admin-modal-action-button" data-user-form-delete-action />
                    @endcan
                @else
                    <x-admin.create-and-new-button />
                @endif
            </div>
            @error('delete')
                <div class="text-sm text-red-300">{{ $message }}</div>
            @enderror
        </form>
    </x-admin.modal>
</div>
