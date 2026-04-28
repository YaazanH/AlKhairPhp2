<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Course;
use App\Models\Teacher;
use App\Services\ManagedUserService;
use App\Support\RoleRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
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
    public string $course_id = '';
    public string $status = 'active';
    public bool $is_helping = true;
    public string $photo_path = '';
    public $photo_upload = null;
    public string $notes = '';
    public ?int $accountTeacherId = null;
    public string $account_username = '';
    public string $account_email = '';
    public string $account_password = '';
    public bool $account_is_active = true;
    public ?string $issued_password = null;
    public string $search = '';
    public string $statusFilter = 'all';
    public string $helpingFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showAccountModal = false;

    public function mount(): void
    {
        $this->authorizePermission('teachers.view');
    }

    public function with(): array
    {
        $baseQuery = $this->scopeTeachersQuery(Teacher::query());
        $filteredQuery = $this->scopeTeachersQuery(Teacher::query())
            ->with(['accessRole', 'course'])
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%')
                        ->orWhereHas('accessRole', fn ($roleQuery) => $roleQuery->where('name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('course', fn ($courseQuery) => $courseQuery->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive', 'blocked'], true), fn ($query) => $query->where('status', $this->statusFilter))
            ->when(in_array($this->helpingFilter, ['helping', 'not_helping'], true), fn ($query) => $query->where('is_helping', $this->helpingFilter === 'helping'))
            ->withCount(['assignedGroups', 'assistedGroups'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'teachers' => $filteredQuery->paginate($this->perPage),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeTeachersQuery(Teacher::query()->where('status', 'active'))->count(),
                'blocked' => $this->scopeTeachersQuery(Teacher::query()->where('status', 'blocked'))->count(),
                'helping' => $this->scopeTeachersQuery(Teacher::query()->where('is_helping', true))->count(),
            ],
            'filteredCount' => $filteredCount,
            'statuses' => ['active', 'inactive', 'blocked'],
            'helpingOptions' => ['all', 'helping', 'not_helping'],
            'availableRoles' => RoleRegistry::sortCollection(
                Role::query()
                    ->whereNotIn('name', RoleRegistry::systemRoles())
                    ->get()
            ),
            'courses' => Course::query()->orderByDesc('is_active')->orderBy('name')->get(),
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

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'access_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'status' => ['required', 'in:active,inactive,blocked'],
            'is_helping' => ['boolean'],
            'photo_upload' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'notes' => ['nullable', 'string'],
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

        $validated = $this->validate();
        $existingTeacher = $this->editingId
            ? Teacher::query()->with('accessRole')->findOrFail($this->editingId)
            : null;
        $previousAccessRoleName = $existingTeacher?->accessRole?->name;
        $accessRole = filled($validated['access_role_id'])
            ? Role::query()->find((int) $validated['access_role_id'])
            : null;

        $payload = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'teacher_job_title_id' => null,
            'job_title' => null,
            'access_role_id' => filled($validated['access_role_id']) ? (int) $validated['access_role_id'] : null,
            'course_id' => filled($validated['course_id']) ? (int) $validated['course_id'] : null,
            'status' => $validated['status'],
            'is_helping' => (bool) $validated['is_helping'],
            'notes' => $validated['notes'] ?: null,
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
                'phone' => $validated['phone'],
                'is_active' => $teacher->user?->is_active ?? ! in_array($validated['status'], ['inactive', 'blocked'], true),
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
        if (! $this->photo_upload || ! $this->editingId) {
            return;
        }

        $this->authorizePermission('teachers.update');

        $teacher = Teacher::query()->findOrFail($this->editingId);
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

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $this->editingId = $teacher->id;
        $this->first_name = $teacher->first_name;
        $this->last_name = $teacher->last_name;
        $this->phone = $teacher->phone;
        $this->access_role_id = $teacher->access_role_id ? (string) $teacher->access_role_id : '';
        $this->course_id = $teacher->course_id ? (string) $teacher->course_id : '';
        $this->status = $teacher->status;
        $this->is_helping = $teacher->is_helping;
        $this->photo_path = $teacher->photo_path ?? '';
        $this->photo_upload = null;
        $this->notes = $teacher->notes ?? '';
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function openAccountModal(int $teacherId): void
    {
        $this->authorizePermission('teachers.update');

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $this->accountTeacherId = $teacher->id;
        $this->account_username = $teacher->user?->username ?? '';
        $this->account_email = $teacher->user?->email ?? '';
        $this->account_password = '';
        $this->account_is_active = $teacher->user?->is_active ?? ! in_array($teacher->status, ['inactive', 'blocked'], true);
        $this->issued_password = $teacher->user?->issued_password;
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
        $this->authorizePermission('teachers.update');

        $this->account_password = app(ManagedUserService::class)->generatePassword();
    }

    public function saveAccount(): void
    {
        $this->authorizePermission('teachers.update');

        $teacher = Teacher::query()->findOrFail($this->accountTeacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $validated = $this->validate($this->accountRules());
        $result = app(ManagedUserService::class)->syncLinkedUser(
            $teacher->user,
            [
                'name' => trim($teacher->first_name.' '.$teacher->last_name),
                'username' => $validated['account_username'] ?: null,
                'email' => $validated['account_email'] ?: null,
                'phone' => $teacher->phone,
                'password' => $validated['account_password'] ?: null,
                'is_active' => (bool) $validated['account_is_active'],
            ],
            'teacher',
        );

        $teacher->user()->associate($result['user']);
        $teacher->save();

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
        $this->accountTeacherId = null;
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
        $this->first_name = '';
        $this->last_name = '';
        $this->phone = '';
        $this->access_role_id = '';
        $this->course_id = '';
        $this->status = 'active';
        $this->is_helping = true;
        $this->notes = '';
        $this->photo_path = '';
        $this->photo_upload = null;
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
        $this->authorizePermission('teachers.update');

        if (! $this->editingId) {
            $this->photo_path = '';
            $this->photo_upload = null;

            return;
        }

        $teacher = Teacher::query()->findOrFail($this->editingId);
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
            ->withCount(['assignedGroups', 'assistedGroups'])
            ->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        if (($teacher->assigned_groups_count + $teacher->assisted_groups_count) > 0) {
            $this->addError('delete', __('crud.teachers.errors.delete_linked'));

            return;
        }

        $teacher->delete();

        if ($this->editingId === $teacherId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.teachers.messages.deleted'));
    }

    protected function linkedUserId(): ?int
    {
        $profileId = $this->accountTeacherId ?? $this->editingId;

        return $profileId
            ? Teacher::query()->whereKey($profileId)->value('user_id')
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

    <div class="grid gap-4 md:grid-cols-4">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.teachers.stats.all') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.teachers.stats.active') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
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

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('crud.teachers.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('crud.teachers.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="teacher-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="teacher-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="teacher-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="teacher-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        @foreach ($statuses as $teacherStatus)
                            <option value="{{ $teacherStatus }}">{{ __('crud.common.status_options.' . $teacherStatus) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="teacher-helping-filter">{{ __('crud.teachers.filters.helping') }}</label>
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
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('crud.teachers.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($teachers->isEmpty())
            <div class="admin-empty-state">{{ __('crud.teachers.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.name') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.phone') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.access_role') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.groups') }}</th>
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
                                            <div class="student-inline__meta">{{ $accessRoleLabel }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $teacher->phone }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $accessRoleLabel }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $teacher->course?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ number_format($teacher->assigned_groups_count + $teacher->assisted_groups_count) }}</td>
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
                                    <span class="{{ $teacher->status === 'active' ? 'status-chip status-chip--emerald' : ($teacher->status === 'blocked' ? 'status-chip status-chip--rose' : 'status-chip status-chip--slate') }}">
                                        {{ __('crud.common.status_options.' . $teacher->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @can('teachers.update')
                                            <button type="button" wire:click="openAccountModal({{ $teacher->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.account') }}</button>
                                        @endcan
                                        @can('teachers.update')
                                            <button type="button" wire:click="edit({{ $teacher->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                        @endcan
                                        @can('teachers.delete')
                                            <button type="button" wire:click="delete({{ $teacher->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
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
        :description="__('crud.teachers.form.help')"
        close-method="cancel"
        max-width="4xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="teacher-first-name" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.first_name') }}</label>
                    <input id="teacher-first-name" wire:model="first_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('first_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="teacher-last-name" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.last_name') }}</label>
                    <input id="teacher-last-name" wire:model="last_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
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
                        <label for="teacher-photo-upload" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.photo.upload') }}</label>
                        <input id="teacher-photo-upload" wire:model="photo_upload" type="file" accept="image/*" class="block w-full text-sm text-neutral-300">
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
                    <label for="teacher-phone" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.phone') }}</label>
                    <input id="teacher-phone" wire:model="phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="teacher-access-role" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.access_role') }}</label>
                    <select id="teacher-access-role" wire:model="access_role_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.teachers.form.options.select_access_role') }}</option>
                        @foreach ($availableRoles as $availableRole)
                            <option value="{{ $availableRole->id }}">{{ __('ui.roles.'.$availableRole->name) === 'ui.roles.'.$availableRole->name ? \Illuminate\Support\Str::of($availableRole->name)->replace('_', ' ')->headline()->toString() : __('ui.roles.'.$availableRole->name) }}</option>
                        @endforeach
                    </select>
                    <div class="mt-2 text-xs leading-5 text-neutral-400">{{ __('crud.teachers.form.help_access_role') }}</div>
                    @error('access_role_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 {{ $editingId ? 'md:grid-cols-2' : '' }}">
                @if ($editingId)
                    <div>
                        <label for="teacher-status" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.status') }}</label>
                        <select id="teacher-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                            @foreach ($statuses as $teacherStatus)
                                <option value="{{ $teacherStatus }}">{{ __('crud.common.status_options.' . $teacherStatus) }}</option>
                            @endforeach
                        </select>
                        @error('status')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>
                @endif

                <div>
                    <label for="teacher-course" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.course') }}</label>
                    <select id="teacher-course" wire:model="course_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.teachers.form.options.select_course') }}</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}{{ $course->is_active ? '' : ' - '.__('settings.common.states.inactive') }}</option>
                        @endforeach
                    </select>
                    @error('course_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if ($editingId)
                <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm">
                    <input wire:model="is_helping" type="checkbox" class="rounded">
                    <span>{{ __('crud.teachers.form.fields.is_helping') }}</span>
                </label>
            @endif

            <div>
                <label for="teacher-notes" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.notes') }}</label>
                <textarea id="teacher-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.teachers.form.update_submit') : __('crud.teachers.form.create_submit') }}
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
