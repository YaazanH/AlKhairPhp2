<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Teacher;
use App\Services\ManagedUserService;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?int $editingId = null;
    public string $first_name = '';
    public string $last_name = '';
    public string $phone = '';
    public string $job_title = '';
    public string $status = 'active';
    public string $hired_at = '';
    public string $notes = '';
    public ?int $accountTeacherId = null;
    public string $account_username = '';
    public string $account_email = '';
    public string $account_password = '';
    public bool $account_is_active = true;
    public ?string $issued_password = null;
    public string $search = '';
    public string $statusFilter = 'all';
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
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%')
                        ->orWhere('job_title', 'like', '%'.$this->search.'%');
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive', 'blocked'], true), fn ($query) => $query->where('status', $this->statusFilter))
            ->withCount(['assignedGroups', 'assistedGroups'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        return [
            'teachers' => $filteredQuery->get(),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeTeachersQuery(Teacher::query()->where('status', 'active'))->count(),
                'blocked' => $this->scopeTeachersQuery(Teacher::query()->where('status', 'blocked'))->count(),
            ],
            'filteredCount' => (clone $filteredQuery)->count(),
            'statuses' => ['active', 'inactive', 'blocked'],
        ];
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive,blocked'],
            'hired_at' => ['nullable', 'date'],
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
        $validated['hired_at'] = $validated['hired_at'] ?: null;
        $teacher = Teacher::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

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

        if ($result['credentials']['password']) {
            session()->flash('generated_credentials', $result['credentials']);
        }

        session()->flash(
            'status',
            $this->editingId ? __('crud.teachers.messages.updated') : __('crud.teachers.messages.created'),
        );

        $this->cancel();
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
        $this->job_title = $teacher->job_title ?? '';
        $this->status = $teacher->status;
        $this->hired_at = $teacher->hired_at?->format('Y-m-d') ?? '';
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

        $this->account_password = Str::password(10);
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
        $this->job_title = '';
        $this->status = 'active';
        $this->hired_at = '';
        $this->notes = '';
        $this->showFormModal = false;

        $this->resetValidation();
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

    <div class="grid gap-4 md:grid-cols-3">
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

                <div class="admin-toolbar__actions">
                    @can('teachers.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('teachers.export', ['search' => $search, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
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
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.job_title') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.groups') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.teachers.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.teachers.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($teachers as $teacher)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="font-semibold text-white">{{ $teacher->first_name }} {{ $teacher->last_name }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $teacher->phone }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $teacher->job_title ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ number_format($teacher->assigned_groups_count + $teacher->assisted_groups_count) }}</td>
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

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="teacher-phone" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.phone') }}</label>
                    <input id="teacher-phone" wire:model="phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="teacher-job-title" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.job_title') }}</label>
                    <input id="teacher-job-title" wire:model="job_title" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('job_title')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
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

                <div>
                    <label for="teacher-hired-at" class="mb-1 block text-sm font-medium">{{ __('crud.teachers.form.fields.hired_at') }}</label>
                    <input id="teacher-hired-at" wire:model="hired_at" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('hired_at')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

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
