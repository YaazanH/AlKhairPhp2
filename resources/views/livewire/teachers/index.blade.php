<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Course;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithFileUploads;
    use WithPagination;

    public ?int $editingId = null;
    public string $first_name = '';
    public string $last_name = '';
    public string $phone = '';
    public string $access_role_id = '';
    public array $access_roles = [];
    public array $direct_permissions = [];
    public array $scope_groups = [];
    public array $scope_students = [];
    public array $scope_teachers = [];
    public array $scope_parents = [];
    public string $course_id = '';
    public string $status = 'active';
    public string $hired_at = '';
    public bool $is_helping = true;
    public string $photo_path = '';
    public $photo_upload = null;
    public string $finance_signature_url = '';
    public $finance_signature_upload = null;
    public string $notes = '';
    public ?int $reviewingId = null;
    public string $account_username = '';
    public string $account_password = '';
    public bool $account_is_active = true;
    public string $search = '';
    public string $statusFilter = 'all';
    public string $helpingFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showReviewModal = false;

    public function mount(): void
    {
        $this->authorizePermission('teachers.view');
    }

    public function with(): array
    {
        $baseQuery = $this->scopeTeachersQuery(Teacher::query());
        $filteredQuery = $this->scopeTeachersQuery(Teacher::query())
            ->with(['accessRole', 'course', 'user'])
            ->when(filled($this->search), function ($query) {
                $normalizedPhone = PhoneNumberFormatter::normalize($this->search);
                $query->where(function ($builder) use ($normalizedPhone) {
                    $builder
                        ->where('first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%')
                        ->when($normalizedPhone, fn ($query) => $query->orWhere('phone', 'like', '%'.$normalizedPhone.'%'))
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('username', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('accessRole', fn ($roleQuery) => $roleQuery->where('name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive', 'blocked', 'pending', 'declined'], true), fn ($query) => $query->where('status', $this->statusFilter))
            ->when(in_array($this->helpingFilter, ['helping', 'not_helping'], true), fn ($query) => $query->where('is_helping', $this->helpingFilter === 'helping'))
            ->withCount(['assignedGroups', 'assistedGroups'])
            ->orderByDesc('is_helping')
            ->orderBy('first_name')
            ->orderBy('last_name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'teachers' => $filteredQuery->paginate($this->perPage),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeTeachersQuery(Teacher::query()->where('status', 'active'))->count(),
                'pending' => $this->scopeTeachersQuery(Teacher::query()->where('status', 'pending'))->count(),
                'blocked' => $this->scopeTeachersQuery(Teacher::query()->where('status', 'blocked'))->count(),
                'helping' => $this->scopeTeachersQuery(Teacher::query()->where('is_helping', true))->count(),
            ],
            'filteredCount' => $filteredCount,
            'statuses' => ['active', 'inactive', 'blocked', 'pending', 'declined'],
            'helpingOptions' => ['all', 'helping', 'not_helping'],
            'availableRoles' => RoleRegistry::sortCollection(
                Role::query()
                    ->whereNotIn('name', RoleRegistry::actorRoles())
                    ->get()
            ),
            'availableScopeGroups' => Group::query()->with('course')->orderBy('name')->get(),
            'availableScopeParents' => ParentProfile::query()->withCount('students')->orderBy('father_name')->get(),
            'availableScopeStudents' => Student::query()->with('parentProfile')->orderBy('last_name')->orderBy('first_name')->get(),
            'availableScopeTeachers' => Teacher::query()->orderBy('first_name')->orderBy('last_name')->get(),
            'permissionGroups' => Permission::query()
                ->orderBy('name')
                ->get()
                ->groupBy(fn (Permission $permission): string => $this->permissionGroupLabel($permission->name)),
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(),
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

    public function updatedHelpingFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFirstName(): void
    {
        $this->generateUsernameFromTeacherName();
    }

    public function updatedLastName(): void
    {
        $this->generateUsernameFromTeacherName();
    }

    protected function generateUsernameFromTeacherName(): void
    {
        if ($this->editingId !== null || ! $this->showFormModal) {
            return;
        }

        $firstName = trim($this->first_name);
        $lastName = trim($this->last_name);

        $this->account_username = $firstName === '' || $lastName === ''
            ? ''
            : app(ManagedUserService::class)->uniqueUsername('', $firstName.' '.$lastName);

        $this->resetValidation('account_username');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'access_roles' => ['nullable', 'array'],
            'access_roles.*' => ['string', 'distinct', Rule::notIn(RoleRegistry::actorRoles()), Rule::exists('roles', 'name')],
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
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'hired_at' => ['nullable', 'date'],
            'is_helping' => ['boolean'],
            'photo_upload' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('uploads.image_max_kb')],
            'finance_signature_upload' => ['nullable', 'file', 'mimes:png', 'max:4096'],
            'account_username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->linkedUserId())],
            'account_password' => ['nullable', 'string', 'min:8'],
            'account_is_active' => ['boolean'],
        ];
    }

    public function reviewRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'access_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'hired_at' => ['nullable', 'date'],
            'is_helping' => ['boolean'],
            'photo_upload' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('uploads.image_max_kb')],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('teachers.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'teachers.update' : 'teachers.create');

        if ($this->editingId) {
            $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail($this->editingId));
        }

        $this->phone = PhoneNumberFormatter::normalize($this->phone) ?? '';
        $validated = $this->validate();
        $accessRoles = RoleRegistry::sortCollection(
            Role::query()->whereIn('name', $validated['access_roles'] ?? [])->get()
        );
        $primaryAccessRole = $accessRoles->first();

        $payload = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'teacher_job_title_id' => null,
            'job_title' => null,
            'access_role_id' => $primaryAccessRole?->id,
            'course_id' => filled($validated['course_id']) ? (int) $validated['course_id'] : null,
            'status' => $this->editingId
                ? ((bool) $validated['account_is_active'] ? 'active' : 'inactive')
                : 'active',
            'hired_at' => $validated['hired_at'] ?: null,
            'is_helping' => (bool) $validated['is_helping'],
        ];

        $teacher = Teacher::query()->updateOrCreate(
            ['id' => $this->editingId],
            $payload,
        );

        if ($this->photo_upload) {
            if ($teacher->photo_path) {
                Storage::disk('public')->delete($teacher->photo_path);
            }

            $teacher->forceFill([
                'photo_path' => $this->photo_upload->store('teachers/photos/'.$teacher->id, 'public'),
            ])->save();
        }

        $result = app(ManagedUserService::class)->syncLinkedUser(
            $teacher->user,
            [
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'username' => $validated['account_username'] ?: null,
                'phone' => $validated['phone'],
                'password' => $validated['account_password'] ?: null,
                'is_active' => (bool) $validated['account_is_active'],
            ],
            'teacher',
        );

        $teacher->user()->associate($result['user']);
        $teacher->save();

        $result['user']->syncRoles(
            $accessRoles->isEmpty()
                ? [RoleRegistry::TEACHER]
                : $accessRoles->pluck('name')->all()
        );

        $result['user']->syncPermissions($validated['direct_permissions'] ?? []);
        app(AccessScopeService::class)->syncUserOverrides($result['user'], [
            'group' => $validated['scope_groups'] ?? [],
            'parent' => $validated['scope_parents'] ?? [],
            'student' => $validated['scope_students'] ?? [],
            'teacher' => $validated['scope_teachers'] ?? [],
        ], Auth::id());

        if ($this->finance_signature_upload && $this->hasFullFinancialAccess($result['user'])) {
            $result['user']->storeFinanceSignatureUpload($this->finance_signature_upload);
        }

        if ($result['credentials']['password']) {
            session()->flash('generated_credentials', $result['credentials']);
        }

        session()->flash(
            'status',
            $this->editingId ? __('crud.teachers.messages.updated') : __('crud.teachers.messages.created'),
        );

        $this->cancel();
    }

    public function updatedPhotoUpload(): void
    {
        $teacherId = $this->editingId ?? $this->reviewingId;

        if (! $this->photo_upload || ! $teacherId) {
            return;
        }

        $this->authorizePermission($this->reviewingId ? 'teachers.review-signups' : 'teachers.update');

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $validated = $this->validateOnly('photo_upload');

        if ($teacher->photo_path) {
            Storage::disk('public')->delete($teacher->photo_path);
        }

        $teacher->forceFill([
            'photo_path' => $validated['photo_upload']->store('teachers/photos/'.$teacher->id, 'public'),
        ])->save();

        $this->photo_path = $teacher->photo_path ?? '';
        $this->photo_upload = null;
        session()->flash('status', __('crud.teachers.messages.photo_updated'));
    }

    public function edit(int $teacherId): void
    {
        $this->authorizePermission('teachers.update');

        $teacher = Teacher::query()->with(['user.roles', 'user.permissions', 'user.scopeOverrides'])->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $this->editingId = $teacher->id;
        $this->first_name = $teacher->first_name;
        $this->last_name = $teacher->last_name;
        $this->phone = $teacher->phone;
        $this->access_role_id = $teacher->access_role_id ? (string) $teacher->access_role_id : '';
        $this->access_roles = $teacher->user
            ? $teacher->user->getRoleNames()->reject(fn (string $roleName): bool => in_array($roleName, RoleRegistry::actorRoles(), true))->values()->all()
            : [];
        if ($this->access_roles === [] && $teacher->accessRole) {
            $this->access_roles = [$teacher->accessRole->name];
        }
        $this->direct_permissions = $teacher->user?->getDirectPermissions()->pluck('name')->values()->all() ?? [];
        $this->scope_groups = $teacher->user?->scopeOverrides->where('scope_type', 'group')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
        $this->scope_parents = $teacher->user?->scopeOverrides->where('scope_type', 'parent')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
        $this->scope_students = $teacher->user?->scopeOverrides->where('scope_type', 'student')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
        $this->scope_teachers = $teacher->user?->scopeOverrides->where('scope_type', 'teacher')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
        $this->course_id = $teacher->course_id ? (string) $teacher->course_id : '';
        $this->status = $teacher->status;
        $this->hired_at = $teacher->hired_at?->format('Y-m-d') ?? '';
        $this->is_helping = $teacher->is_helping;
        $this->photo_path = $teacher->photo_path ?? '';
        $this->photo_upload = null;
        $this->finance_signature_url = $teacher->user?->financeSignatureUrl() ?? '';
        $this->finance_signature_upload = null;
        $this->notes = $teacher->notes ?? '';
        $this->account_username = $teacher->user?->username ?? '';
        $this->account_password = '';
        $this->account_is_active = $teacher->user?->is_active ?? ! in_array($teacher->status, ['inactive', 'blocked', 'pending', 'declined'], true);
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function openReviewModal(int $teacherId): void
    {
        $this->authorizePermission('teachers.review-signups');

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        abort_unless($teacher->status === 'pending', 403);

        $this->reviewingId = $teacher->id;
        $this->first_name = $teacher->first_name;
        $this->last_name = $teacher->last_name;
        $this->phone = $teacher->phone;
        $this->access_role_id = $teacher->access_role_id ? (string) $teacher->access_role_id : '';
        $this->course_id = $teacher->course_id ? (string) $teacher->course_id : '';
        $this->status = $teacher->status;
        $this->hired_at = $teacher->hired_at?->format('Y-m-d') ?? '';
        $this->is_helping = $teacher->is_helping;
        $this->photo_path = $teacher->photo_path ?? '';
        $this->photo_upload = null;
        $this->notes = $teacher->notes ?? '';
        $this->showReviewModal = true;

        $this->resetValidation();
    }

    public function approveSignupRequest(): void
    {
        $this->authorizePermission('teachers.review-signups');

        $teacher = Teacher::query()->with(['accessRole', 'user'])->findOrFail($this->reviewingId);
        $this->authorizeScopedTeacherAccess($teacher);

        abort_unless($teacher->status === 'pending', 403);

        $validated = $this->validate($this->reviewRules());
        $previousAccessRoleName = $teacher->accessRole?->name;
        $accessRole = filled($validated['access_role_id'])
            ? Role::query()->find((int) $validated['access_role_id'])
            : null;

        $teacher->forceFill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'access_role_id' => filled($validated['access_role_id']) ? (int) $validated['access_role_id'] : null,
            'course_id' => filled($validated['course_id']) ? (int) $validated['course_id'] : null,
            'status' => 'active',
            'hired_at' => $validated['hired_at'],
            'is_helping' => (bool) $validated['is_helping'],
            'notes' => $validated['notes'] ?: null,
        ])->save();

        if ($this->photo_upload) {
            if ($teacher->photo_path) {
                Storage::disk('public')->delete($teacher->photo_path);
            }

            $teacher->forceFill([
                'photo_path' => $this->photo_upload->store('teachers/photos/'.$teacher->id, 'public'),
            ])->save();
        }

        $result = app(ManagedUserService::class)->syncLinkedUser(
            $teacher->user,
            [
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'phone' => $validated['phone'],
                'is_active' => true,
            ],
            'teacher',
        );

        $teacher->user()->associate($result['user']);
        $teacher->save();

        if ($previousAccessRoleName && $previousAccessRoleName !== $accessRole?->name && $result['user']->hasRole($previousAccessRoleName)) {
            $result['user']->removeRole($previousAccessRoleName);
        }

        if ($accessRole && ! $result['user']->hasRole($accessRole->name)) {
            $result['user']->assignRole($accessRole->name);
        }

        if ($accessRole && $result['user']->hasRole('teacher')) {
            $result['user']->removeRole('teacher');
        }

        session()->flash('status', __('crud.teachers.messages.request_approved'));

        $this->closeReviewModal();
    }

    public function declineSignupRequest(): void
    {
        $this->authorizePermission('teachers.review-signups');

        $teacher = Teacher::query()->with('user')->findOrFail($this->reviewingId);
        $this->authorizeScopedTeacherAccess($teacher);

        abort_unless($teacher->status === 'pending', 403);

        $teacher->forceFill([
            'status' => 'declined',
            'notes' => $this->notes ?: $teacher->notes,
        ])->save();

        if ($teacher->user) {
            $teacher->user->forceFill(['is_active' => false])->save();
        }

        session()->flash('status', __('crud.teachers.messages.request_declined'));

        $this->closeReviewModal();
    }

    public function closeReviewModal(): void
    {
        $this->reviewingId = null;
        $this->first_name = '';
        $this->last_name = '';
        $this->phone = '';
        $this->access_role_id = '';
        $this->access_roles = [];
        $this->direct_permissions = [];
        $this->scope_groups = [];
        $this->scope_students = [];
        $this->scope_teachers = [];
        $this->scope_parents = [];
        $this->course_id = '';
        $this->status = 'active';
        $this->hired_at = '';
        $this->is_helping = true;
        $this->notes = '';
        $this->photo_path = '';
        $this->photo_upload = null;
        $this->finance_signature_url = '';
        $this->finance_signature_upload = null;
        $this->showReviewModal = false;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->first_name = '';
        $this->last_name = '';
        $this->phone = '';
        $this->access_role_id = '';
        $this->access_roles = [];
        $this->direct_permissions = [];
        $this->scope_groups = [];
        $this->scope_students = [];
        $this->scope_teachers = [];
        $this->scope_parents = [];
        $this->course_id = '';
        $this->status = 'active';
        $this->hired_at = '';
        $this->is_helping = true;
        $this->notes = '';
        $this->photo_path = '';
        $this->photo_upload = null;
        $this->finance_signature_url = '';
        $this->finance_signature_upload = null;
        $this->account_username = '';
        $this->account_password = '';
        $this->account_is_active = true;
        $this->showFormModal = false;

        $this->resetValidation();
    }

    public function toggleHelping(int $teacherId): void
    {
        $this->authorizePermission('teachers.update');

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $teacher->forceFill(['is_helping' => ! $teacher->is_helping])->save();

        session()->flash('status', __('crud.teachers.messages.helping_updated'));
    }

    public function removePhoto(): void
    {
        $teacherId = $this->editingId ?? $this->reviewingId;

        if (! $teacherId) {
            $this->photo_path = '';
            $this->photo_upload = null;

            return;
        }

        $this->authorizePermission($this->reviewingId ? 'teachers.review-signups' : 'teachers.update');

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        if ($teacher->photo_path) {
            Storage::disk('public')->delete($teacher->photo_path);
        }

        $teacher->forceFill(['photo_path' => null])->save();
        $this->photo_path = '';
        $this->photo_upload = null;

        session()->flash('status', __('crud.teachers.messages.photo_removed'));
    }

    public function delete(int $teacherId): void
    {
        $this->authorizePermission('teachers.delete');

        $teacher = Teacher::query()
            ->with('user')
            ->withCount(['assignedGroups', 'assistedGroups'])
            ->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        if (($teacher->assigned_groups_count + $teacher->assisted_groups_count) > 0) {
            $this->addError('delete', __('crud.teachers.errors.delete_linked'));

            return;
        }

        $linkedUser = $teacher->user;
        $teacher->delete();
        $linkedUser?->delete();

        if ($this->editingId === $teacherId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.teachers.messages.deleted'));
    }

    public function deleteEditingTeacher(): void
    {
        if (! $this->editingId) {
            return;
        }

        $this->delete($this->editingId);
    }

    public function formTeacherHasFullFinancialAccess(): bool
    {
        $permissionNames = collect($this->direct_permissions)->merge(
            Role::query()
                ->whereIn('name', $this->access_roles)
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

    protected function linkedUserId(): ?int
    {
        return $this->editingId
            ? Teacher::query()->whereKey($this->editingId)->value('user_id')
            : null;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.people') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.teachers.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.teachers.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if (session('generated_credentials'))
        <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            {{ __('access.profile_accounts.messages.credentials', session('generated_credentials')) }}
        </div>
    @endif

    <div class="mobile-compact-highlights mobile-compact-highlights--five grid gap-4 md:grid-cols-5">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.teachers.stats.all') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.teachers.stats.active') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.teachers.stats.pending') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['pending']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.teachers.stats.blocked') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['blocked']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.teachers.stats.helping') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['helping']) }}</div>
        </article>
    </div>

    <section class="surface-table mobile-records-surface standard-mobile-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('crud.teachers.table.title') }}</div>
            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label class="sr-only" for="teacher-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="teacher-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="teacher-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="teacher-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        @foreach ($statuses as $teacherStatus)
                            <option value="{{ $teacherStatus }}">{{ __('crud.common.status_options.' . $teacherStatus) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="teacher-helping-filter">{{ __('crud.teachers.filters.helping') }}</label>
                    <select id="teacher-helping-filter" wire:model.live="helpingFilter">
                        @foreach ($helpingOptions as $helpingOption)
                            <option value="{{ $helpingOption }}">{{ __('crud.teachers.helping_options.' . $helpingOption) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('teachers.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('teachers.export', ['search' => $search, 'status' => $statusFilter, 'helping' => $helpingFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($teachers->isEmpty())
            <div class="admin-empty-state">{{ __('crud.teachers.table.empty') }}</div>
        @else
            <div class="responsive-records-mobile">
                @foreach ($teachers as $teacher)
                    @php
                        $accessRoleName = $teacher->accessRole?->name;
                        $accessRoleLabel = $accessRoleName
                            ? ((__('ui.roles.'.$accessRoleName) === 'ui.roles.'.$accessRoleName)
                                ? \Illuminate\Support\Str::of($accessRoleName)->replace('_', ' ')->headline()->toString()
                                : __('ui.roles.'.$accessRoleName))
                            : __('crud.common.not_available');
                    @endphp
                    <article class="mobile-record-card">
                        <div class="mobile-record-card__header">
                            <div class="student-inline min-w-0">
                                <x-teacher-avatar :teacher="$teacher" size="sm" />
                                <div class="student-inline__body min-w-0">
                                    <div class="student-inline__name">{{ $teacher->first_name }} {{ $teacher->last_name }}</div>
                                    <div class="student-inline__meta">{{ $teacher->user?->username ?: __('crud.common.not_available') }}</div>
                                </div>
                            </div>
                            <span class="{{ $teacher->status === 'active' ? 'status-chip status-chip--emerald' : ($teacher->status === 'pending' ? 'status-chip status-chip--gold' : (in_array($teacher->status, ['blocked', 'declined'], true) ? 'status-chip status-chip--rose' : 'status-chip status-chip--slate')) }}">
                                {{ __('crud.common.status_options.' . $teacher->status) }}
                            </span>
                        </div>

                        <dl class="mobile-record-card__details">
                            <div>
                                <dt>{{ __('crud.teachers.table.headers.phone') }}</dt>
                                <dd><bdi dir="ltr">{{ $teacher->phone ?: __('crud.common.not_available') }}</bdi></dd>
                            </div>
                            <div>
                                <dt>{{ __('crud.teachers.table.headers.access_role') }}</dt>
                                <dd>{{ $accessRoleLabel }}</dd>
                            </div>
                            <div class="mobile-record-card__detail--wide">
                                <dt>{{ __('crud.teachers.table.headers.helping') }}</dt>
                                <dd>
                                    @can('teachers.update')
                                        <button type="button" wire:click="toggleHelping({{ $teacher->id }})" class="{{ $teacher->is_helping ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                            {{ $teacher->is_helping ? __('crud.teachers.helping_options.helping') : __('crud.teachers.helping_options.not_helping') }}
                                        </button>
                                    @else
                                        <span class="{{ $teacher->is_helping ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                            {{ $teacher->is_helping ? __('crud.teachers.helping_options.helping') : __('crud.teachers.helping_options.not_helping') }}
                                        </span>
                                    @endcan
                                </dd>
                            </div>
                        </dl>

                        <div class="mobile-record-card__actions">
                            @can('teachers.review-signups')
                                @if ($teacher->status === 'pending')
                                    <button type="button" wire:click="openReviewModal({{ $teacher->id }})" class="pill-link pill-link--compact">{{ __('crud.teachers.review.action') }}</button>
                                @endif
                            @endcan
                            @can('teachers.update')
                                <button type="button" wire:click="edit({{ $teacher->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                            @endcan
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="responsive-records-desktop overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.name') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.phone') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.access_role') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.helping') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.teachers.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($teachers as $teacher)
                            @php
                                $accessRoleName = $teacher->accessRole?->name;
                                $accessRoleLabel = $accessRoleName
                                    ? ((__('ui.roles.'.$accessRoleName) === 'ui.roles.'.$accessRoleName)
                                        ? \Illuminate\Support\Str::of($accessRoleName)->replace('_', ' ')->headline()->toString()
                                        : __('ui.roles.'.$accessRoleName))
                                    : __('crud.common.not_available');
                            @endphp
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="student-inline">
                                        <x-teacher-avatar :teacher="$teacher" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $teacher->first_name }} {{ $teacher->last_name }}</div>
                                            <div class="student-inline__meta">{{ $teacher->user?->username ?: __('crud.common.not_available') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6"><bdi dir="ltr" class="inline-block">{{ $teacher->phone }}</bdi></td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $accessRoleLabel }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    @can('teachers.update')
                                        <button type="button" wire:click="toggleHelping({{ $teacher->id }})" class="{{ $teacher->is_helping ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                            {{ $teacher->is_helping ? __('crud.teachers.helping_options.helping') : __('crud.teachers.helping_options.not_helping') }}
                                        </button>
                                    @else
                                        <span class="{{ $teacher->is_helping ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                            {{ $teacher->is_helping ? __('crud.teachers.helping_options.helping') : __('crud.teachers.helping_options.not_helping') }}
                                        </span>
                                    @endcan
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $teacher->status === 'active' ? 'status-chip status-chip--emerald' : ($teacher->status === 'pending' ? 'status-chip status-chip--gold' : (in_array($teacher->status, ['blocked', 'declined'], true) ? 'status-chip status-chip--rose' : 'status-chip status-chip--slate')) }}">
                                        {{ __('crud.common.status_options.' . $teacher->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @can('teachers.review-signups')
                                            @if ($teacher->status === 'pending')
                                                <button type="button" wire:click="openReviewModal({{ $teacher->id }})" class="pill-link pill-link--compact">{{ __('crud.teachers.review.action') }}</button>
                                            @endif
                                        @endcan
                                        @can('teachers.update')
                                            <button type="button" wire:click="edit({{ $teacher->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($teachers->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $teachers->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.teachers.form.edit_title') : __('crud.teachers.form.create_title')"
        close-method="cancel"
        max-width="2xl"
    >
        <form wire:submit="save" class="space-y-4" data-user-form data-teacher-profile-account-form>
            <section class="admin-section-card" data-teacher-identity-box>
                <div class="admin-form-grid" data-teacher-identity-grid>
                    <div class="admin-form-field" data-teacher-identity-third>
                        <label for="teacher-first-name" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.first_name') }}</label>
                        <input id="teacher-first-name" wire:model.live.debounce.300ms="first_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('first_name')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field" data-teacher-identity-third>
                        <label for="teacher-last-name" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.last_name') }}</label>
                        <input id="teacher-last-name" wire:model.live.debounce.300ms="last_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('last_name')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field" data-teacher-identity-third>
                        <label for="teacher-account-username" class="mb-1 block text-sm font-medium">{{ __('access.users.fields.username') }}</label>
                        <input id="teacher-account-username" wire:model="account_username" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('account_username')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field" data-teacher-identity-half>
                        <label for="teacher-phone" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.phone') }}</label>
                        <x-phone-input id="teacher-phone" model="phone" :value="$phone" :required="true" />
                        @error('phone')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="admin-form-field" data-teacher-identity-half>
                        <label for="teacher-account-password" class="mb-1 block text-sm font-medium">{{ __('access.users.fields.password') }}</label>
                        <input id="teacher-account-password" wire:model="account_password" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('account_password')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                </div>

                @if ($editingId)
                    <label class="flex items-center gap-3 text-sm" data-teacher-active-toggle>
                        <input wire:model="account_is_active" type="checkbox" class="rounded">
                        <span>{{ __('access.users.fields.is_active') }}</span>
                    </label>
                @endif
            </section>

            <section class="admin-section-card" data-teacher-role-box>
                <div class="grid gap-3 md:grid-cols-2" data-teacher-role-options>
                    @foreach ($availableRoles as $availableRole)
                        <label class="flex items-center gap-3 rounded-2xl border border-white/8 px-3 py-3 text-sm text-neutral-200">
                            <input wire:model.live="access_roles" type="checkbox" value="{{ $availableRole->name }}" class="rounded">
                            <span><x-admin.role-label :name="$availableRole->name" /></span>
                        </label>
                    @endforeach
                </div>
                @error('access_roles')
                    <div class="text-sm text-red-400">{{ $message }}</div>
                @enderror
                @error('access_roles.*')
                    <div class="text-sm text-red-400">{{ $message }}</div>
                @enderror
            </section>

            <details class="admin-collapsible" data-teacher-media>
                <summary class="admin-collapsible__summary">
                    <span>{{ __('access.users.sections.media') }}</span>
                </summary>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="grid gap-4 rounded-2xl border border-white/8 p-4 md:grid-cols-[auto_minmax(0,1fr)] md:items-center" data-teacher-photo-box>
                        <div>
                            @if ($photo_upload)
                                <img src="{{ $photo_upload->temporaryUrl() }}" alt="{{ __('crud.teachers.photo.preview_alt') }}" class="h-24 w-24 rounded-3xl object-cover shadow-sm">
                            @elseif ($photo_path)
                                <img src="{{ asset('storage/'.ltrim($photo_path, '/')) }}" alt="{{ __('crud.teachers.photo.alt') }}" class="h-24 w-24 rounded-3xl object-cover shadow-sm">
                            @else
                                <x-teacher-avatar :teacher="(object) ['first_name' => $first_name, 'last_name' => $last_name, 'photo_path' => null]" size="lg" />
                            @endif
                        </div>

                        <div class="min-w-0">
                            <label for="teacher-photo-upload" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.photo.upload') }}</label>
                            <input id="teacher-photo-upload" wire:model="photo_upload" type="file" accept="image/*" class="block w-full text-sm text-neutral-300">
                            @error('photo_upload')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror

                            @if ($photo_path || $photo_upload)
                                <button type="button" wire:click="removePhoto" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact mt-3 border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                    {{ __('crud.teachers.photo.remove') }}
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($this->formTeacherHasFullFinancialAccess())
                        <div class="grid gap-4 rounded-2xl border border-white/8 p-4 md:grid-cols-[minmax(0,10rem)_minmax(0,1fr)] md:items-center" data-teacher-finance-signature>
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
                                <label for="teacher-finance-signature-upload" class="mb-1 block text-sm font-semibold text-white">{{ __('access.users.fields.finance_signature') }}</label>
                                <input id="teacher-finance-signature-upload" wire:model="finance_signature_upload" type="file" accept="image/png" class="block w-full text-sm text-neutral-300">
                                @error('finance_signature_upload')
                                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    @endif
                </div>
            </details>

            <details
                class="admin-collapsible"
                data-teacher-additional-permissions
                @if ($errors->has('direct_permissions') || $errors->has('direct_permissions.*') || $errors->has('scope_groups') || $errors->has('scope_students') || $errors->has('scope_teachers') || $errors->has('scope_parents')) open @endif
            >
                <summary class="admin-collapsible__summary">
                    <span>{{ __('access.users.sections.additional_permissions') }}</span>
                </summary>

                <div class="space-y-4" data-teacher-access-overrides-box>
                    <details
                        class="admin-collapsible"
                        data-teacher-direct-permissions
                        @if ($errors->has('direct_permissions') || $errors->has('direct_permissions.*')) open @endif
                    >
                        <summary class="admin-collapsible__summary">
                            <span>{{ __('access.users.fields.permissions') }}</span>
                            <span class="admin-collapsible__count">{{ count($direct_permissions) }}/{{ $permissionGroups->flatten(1)->count() }}</span>
                        </summary>
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
                    </details>

                    <details
                        class="admin-collapsible"
                        data-teacher-scope-overrides
                        @if ($errors->has('scope_groups') || $errors->has('scope_students') || $errors->has('scope_teachers') || $errors->has('scope_parents')) open @endif
                    >
                        <summary class="admin-collapsible__summary">
                            <span>{{ __('access.users.sections.scope') }}</span>
                            <span class="admin-collapsible__count">
                                {{ count($scope_groups) + count($scope_students) + count($scope_teachers) + count($scope_parents) }}/{{ $availableScopeGroups->count() + $availableScopeStudents->count() + $availableScopeTeachers->count() + $availableScopeParents->count() }}
                            </span>
                        </summary>
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
                    </details>
                </div>
            </details>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.teachers.form.update_submit') : __('crud.teachers.form.create_submit') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingId" />
                @if ($editingId)
                    @can('teachers.delete')
                        <button type="button" wire:click="deleteEditingTeacher" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                    @endcan
                @endif
            </div>
            @error('delete')
                <div class="text-sm text-red-300">{{ $message }}</div>
            @enderror
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showReviewModal"
        :title="__('crud.teachers.review.title')"
        :description="__('crud.teachers.review.description')"
        close-method="closeReviewModal"
        max-width="4xl"
    >
        <form wire:submit="approveSignupRequest" class="space-y-4">
            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-neutral-300">
                {{ __('crud.teachers.review.help') }}
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="review-teacher-first-name" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.first_name') }}</label>
                    <input id="review-teacher-first-name" wire:model="first_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('first_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="review-teacher-last-name" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.last_name') }}</label>
                    <input id="review-teacher-last-name" wire:model="last_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('last_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                <div class="grid gap-4 md:grid-cols-[auto_minmax(0,1fr)] md:items-center">
                    <div>
                        @if ($photo_upload)
                            <img src="{{ $photo_upload->temporaryUrl() }}" alt="{{ __('crud.teachers.photo.preview_alt') }}" class="h-24 w-24 rounded-3xl object-cover shadow-sm">
                        @elseif ($photo_path)
                            <img src="{{ asset('storage/'.ltrim($photo_path, '/')) }}" alt="{{ __('crud.teachers.photo.alt') }}" class="h-24 w-24 rounded-3xl object-cover shadow-sm">
                        @else
                            <x-teacher-avatar :teacher="(object) ['first_name' => $first_name, 'last_name' => $last_name, 'photo_path' => null]" size="lg" />
                        @endif
                    </div>

                    <div>
                        <label for="review-teacher-photo-upload" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.photo.upload') }}</label>
                        <input id="review-teacher-photo-upload" wire:model="photo_upload" type="file" accept="image/*" class="block w-full text-sm text-neutral-300">
                        <p class="mt-2 text-xs leading-5 text-neutral-400">{{ __('crud.teachers.photo.help') }}</p>
                        @error('photo_upload')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror

                        @if ($photo_path || $photo_upload)
                            <button type="button" wire:click="removePhoto" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact mt-3 border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                {{ __('crud.teachers.photo.remove') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="review-teacher-phone" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.phone') }}</label>
                    <x-phone-input id="review-teacher-phone" model="phone" :value="$phone" :required="true" />
                    @error('phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="review-teacher-access-role" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.access_role') }}</label>
                    <select id="review-teacher-access-role" wire:model="access_role_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.teachers.form.options.select_access_role') }}</option>
                        @foreach ($availableRoles as $availableRole)
                            <option value="{{ $availableRole->id }}">{{ __('ui.roles.'.$availableRole->name) === 'ui.roles.'.$availableRole->name ? \Illuminate\Support\Str::of($availableRole->name)->replace('_', ' ')->headline()->toString() : __('ui.roles.'.$availableRole->name) }}</option>
                        @endforeach
                    </select>
                    @error('access_role_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">

            </div>

            <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm">
                <input wire:model="is_helping" type="checkbox" class="rounded">
                <span>{{ __('crud.teachers.form.fields.is_helping') }}</span>
            </label>

            <div>
                <label for="review-teacher-notes" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.notes') }}</label>
                <textarea id="review-teacher-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('crud.teachers.review.approve') }}</button>
                <button type="button" wire:click="declineSignupRequest" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.teachers.review.decline') }}</button>
                <button type="button" wire:click="closeReviewModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>
