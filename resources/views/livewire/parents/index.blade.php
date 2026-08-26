<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\FatherJob;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Services\ManagedUserService;
use App\Services\ParentNumberService;
use App\Support\ArabicSearch;
use App\Support\PhoneNumberFormatter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
    public string $new_father_work = '';
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
    public bool $showAccountViewModal = false;
    public string $account_father_name = '';
    public bool $showChildrenModal = false;
    public bool $showBulkStatusModal = false;
    public ?int $childrenParentId = null;
    public string $childrenParentName = '';
    public array $childrenRows = [];
    public string $bulk_status_action = 'deactivate';
    public string $bulk_scope = 'all';
    public string $bulk_parent_number_from = '';
    public string $bulk_parent_number_to = '';
    public bool $bulk_sync_accounts = true;

    public function mount(): void
    {
        $this->authorizePermission('parents.view');
    }

    public function with(): array
    {
        $baseQuery = $this->scopeParentsQuery(ParentProfile::query());
        $filteredQuery = $this->scopeParentsQuery(ParentProfile::query())
            ->when(filled($this->search), function ($query) {
                $normalizedPhone = PhoneNumberFormatter::normalize($this->search);
                $query->where(function ($builder) use ($normalizedPhone) {
                    $builder
                        ->where('parent_number', 'like', '%'.$this->search.'%')
                        ->orWhere('father_name', 'like', '%'.$this->search.'%')
                        ->orWhere('mother_name', 'like', '%'.$this->search.'%')
                        ->orWhere('father_phone', 'like', '%'.$this->search.'%')
                        ->orWhere('mother_phone', 'like', '%'.$this->search.'%')
                        ->orWhere('home_phone', 'like', '%'.$this->search.'%')
                        ->when($normalizedPhone, fn ($query) => $query
                            ->orWhere('father_phone', 'like', '%'.$normalizedPhone.'%')
                            ->orWhere('mother_phone', 'like', '%'.$normalizedPhone.'%')
                            ->orWhere('home_phone', 'like', '%'.$normalizedPhone.'%'));
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->withCount('students')
            ->orderBy('father_name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'parents' => $filteredQuery->paginate($this->perPage),
            'fatherJobs' => FatherJob::query()->where('is_active', true)->orderBy('name')->get(['name']),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeParentsQuery(ParentProfile::query()->where('is_active', true))->count(),
            ],
            'filteredCount' => $filteredCount,
            'bulkStatusPreview' => $this->showBulkStatusModal ? $this->bulkStatusPreview() : ['profiles' => 0, 'accounts' => 0],
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

    public function updatedBulkScope(): void
    {
        $this->bulk_parent_number_from = '';
        $this->bulk_parent_number_to = '';

        $this->resetValidation([
            'bulk_parent_number_from',
            'bulk_parent_number_to',
            'bulk_status',
        ]);
    }

    public function openBulkStatusModal(): void
    {
        $this->authorizePermission('parents.update');

        $this->bulk_status_action = 'deactivate';
        $this->bulk_scope = 'all';
        $this->bulk_parent_number_from = '';
        $this->bulk_parent_number_to = '';
        $this->bulk_sync_accounts = true;
        $this->showBulkStatusModal = true;

        $this->resetValidation([
            'bulk_parent_number_from',
            'bulk_parent_number_to',
            'bulk_status',
        ]);
    }

    public function closeBulkStatusModal(): void
    {
        $this->showBulkStatusModal = false;
        $this->bulk_status_action = 'deactivate';
        $this->bulk_scope = 'all';
        $this->bulk_parent_number_from = '';
        $this->bulk_parent_number_to = '';
        $this->bulk_sync_accounts = true;

        $this->resetValidation([
            'bulk_parent_number_from',
            'bulk_parent_number_to',
            'bulk_status',
        ]);
    }

    public function applyBulkStatus(): void
    {
        $this->authorizePermission('parents.update');

        $targets = $this->targetParentIdsForBulkStatus();
        $parentCount = count($targets);

        if ($parentCount === 0) {
            $this->addError('bulk_status', __('crud.parents.bulk_status.errors.no_targets'));

            return;
        }

        $accountIds = [];

        if ($this->bulk_sync_accounts) {
            $accountIds = ParentProfile::query()
                ->whereIn('id', $targets)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->all();
        }

        DB::transaction(function () use ($targets, $accountIds): void {
            ParentProfile::query()
                ->whereIn('id', $targets)
                ->update([
                    'is_active' => $this->bulk_status_action === 'activate',
                    'updated_at' => now(),
                ]);

            if ($this->bulk_sync_accounts && $accountIds !== []) {
                User::query()
                    ->whereIn('id', $accountIds)
                    ->update([
                        'is_active' => $this->bulk_status_action === 'activate',
                        'updated_at' => now(),
                    ]);
            }
        });

        session()->flash('status', __('crud.parents.bulk_status.messages.updated', [
            'count' => number_format($parentCount),
            'status' => __('crud.common.status_options.'.($this->bulk_status_action === 'activate' ? 'active' : 'inactive')),
        ]));

        $this->closeBulkStatusModal();
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
            'account_username' => ['nullable', 'string', 'max:255'],
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

        foreach (['father_phone', 'mother_phone', 'home_phone'] as $phoneField) {
            $this->{$phoneField} = PhoneNumberFormatter::normalize($this->{$phoneField}) ?? '';
        }
        $validated = $this->validate();

        if ($duplicate = $this->findDuplicateParent($validated)) {
            $this->addError('father_name', __('crud.parents.errors.duplicate_profile', [
                'name' => $duplicate->father_name,
                'number' => $duplicate->parent_number ?: $duplicate->id,
            ]));

            return;
        }

        $parent = ParentProfile::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        $result = app(ManagedUserService::class)->syncLinkedUser(
            $parent->user,
            [
                'name' => $validated['father_name'],
                'username' => $parent->parent_number ?: null,
                'phone' => $validated['father_phone'] ?: ($validated['mother_phone'] ?: ($validated['home_phone'] ?: null)),
                'phones' => [
                    $validated['father_phone'] ?? null,
                    $validated['mother_phone'] ?? null,
                    $validated['home_phone'] ?? null,
                ],
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
        $this->new_father_work = '';
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
        app(ParentNumberService::class)->syncParent($parent);
        $parent->refresh();

        $this->accountParentId = $parent->id;
        $this->account_username = $parent->parent_number ?? ($parent->user?->username ?? '');
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

    public function viewAccount(int $parentId): void
    {
        $this->authorizePermission('parents.view');
        $parent = ParentProfile::query()->with('user')->findOrFail($parentId);
        $this->authorizeScopedParentAccess($parent);
        $this->account_father_name = $parent->father_name;
        $this->account_username = $parent->parent_number ?? ($parent->user?->username ?? '');
        $this->issued_password = $parent->user?->issued_password;
        $this->showAccountViewModal = true;
    }

    public function openChildrenModal(int $parentId): void
    {
        $this->authorizePermission('students.view');

        $parent = ParentProfile::query()->findOrFail($parentId);
        $this->authorizeScopedParentAccess($parent);

        $students = $this->scopeStudentsQuery(
            Student::query()
                ->where('parent_id', $parent->id)
                ->with(['gradeLevel', 'enrollments.group'])
        )
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $this->childrenParentId = $parent->id;
        $this->childrenParentName = $parent->father_name;
        $this->childrenRows = $students
            ->map(fn (Student $student): array => [
                'id' => $student->id,
                'name' => $student->full_name,
                'student_number' => $student->student_number ?: (string) $student->id,
                'grade_level' => $student->gradeLevel?->name ?: __('crud.common.not_available'),
                'group_name' => $student->currentGroupName() ?: __('crud.common.not_available'),
                'status' => (string) $student->status,
            ])
            ->all();
        $this->showChildrenModal = true;
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
                'username' => $parent->parent_number ?: ($validated['account_username'] ?: null),
                'email' => $validated['account_email'] ?: null,
                'phone' => $parent->father_phone ?: ($parent->mother_phone ?: ($parent->home_phone ?: null)),
                'phones' => [
                    $parent->father_phone,
                    $parent->mother_phone,
                    $parent->home_phone,
                ],
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

    public function closeChildrenModal(): void
    {
        $this->childrenParentId = null;
        $this->childrenParentName = '';
        $this->childrenRows = [];
        $this->showChildrenModal = false;
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->father_name = '';
        $this->father_work = '';
        $this->new_father_work = '';
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

    public function createFatherJobShortcut(): void
    {
        $this->authorizePermission('parents.create');
        $this->new_father_work = trim($this->father_work);

        $validated = $this->validate([
            'new_father_work' => ['required', 'string', 'max:255', Rule::unique('father_jobs', 'name')],
        ], [], [
            'new_father_work' => __('crud.parents.form.fields.father_work'),
        ]);

        $job = FatherJob::query()->create([
            'name' => trim($validated['new_father_work']),
            'is_active' => true,
        ]);

        $this->father_work = $job->name;
        $this->new_father_work = '';
        $this->resetValidation('new_father_work');
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

    protected function findDuplicateParent(array $validated): ?ParentProfile
    {
        $fatherName = ArabicSearch::normalizeForDuplicate((string) ($validated['father_name'] ?? ''));
        $motherName = ArabicSearch::normalizeForDuplicate((string) ($validated['mother_name'] ?? ''));
        $phones = collect([
            $validated['father_phone'] ?? null,
            $validated['mother_phone'] ?? null,
            $validated['home_phone'] ?? null,
        ])
            ->map(fn ($phone) => preg_replace('/\D+/', '', (string) $phone) ?: null)
            ->filter()
            ->values();

        if ($fatherName === '' && $phones->isEmpty()) {
            return null;
        }

        return $this->scopeParentsQuery(
            ParentProfile::query()
                ->when($this->editingId, fn (Builder $query) => $query->whereKeyNot($this->editingId))
                ->orderByDesc('id')
        )
            ->get()
            ->first(function (ParentProfile $parent) use ($fatherName, $motherName, $phones): bool {
                $parentPhones = collect([$parent->father_phone, $parent->mother_phone, $parent->home_phone])
                    ->map(fn ($phone) => preg_replace('/\D+/', '', (string) $phone) ?: null)
                    ->filter();

                $phoneMatches = $phones->isNotEmpty() && $phones->intersect($parentPhones)->isNotEmpty();
                $fatherMatches = $fatherName !== '' && ArabicSearch::normalizeForDuplicate($parent->father_name) === $fatherName;
                $motherMatches = $motherName !== '' && ArabicSearch::normalizeForDuplicate((string) $parent->mother_name) === $motherName;

                return $phoneMatches || ($fatherMatches && $motherName !== '' && $motherMatches);
            });
    }

    protected function bulkStatusPreview(): array
    {
        $targets = $this->targetParentIdsForBulkStatus(false);

        if ($targets === []) {
            return ['profiles' => 0, 'accounts' => 0];
        }

        $accounts = $this->bulk_sync_accounts
            ? User::query()
                ->whereIn('id', ParentProfile::query()->whereIn('id', $targets)->whereNotNull('user_id')->pluck('user_id'))
                ->where('is_active', $this->bulk_status_action !== 'activate')
                ->count()
            : 0;

        return [
            'profiles' => count($targets),
            'accounts' => $accounts,
        ];
    }

    protected function targetParentIdsForBulkStatus(bool $withValidation = true): array
    {
        $query = $this->bulkStatusParentQuery($withValidation);

        if (! $query) {
            return [];
        }

        return $query->pluck('id')->all();
    }

    protected function bulkStatusParentQuery(bool $withValidation = true): ?Builder
    {
        $query = $this->scopeParentsQuery(ParentProfile::query());

        if ($this->bulk_scope === 'parent_number_range') {
            [$from, $to] = $this->parentNumberRangeBounds($withValidation);

            if ($from === null && $to === null) {
                return null;
            }

            if ($from !== null) {
                $query->where('id', '>=', $from);
            }

            if ($to !== null) {
                $query->where('id', '<=', $to);
            }
        }

        return $query->where('is_active', $this->bulk_status_action !== 'activate');
    }

    protected function parentNumberRangeBounds(bool $withValidation = true): array
    {
        $from = $this->parseParentNumberInput($this->bulk_parent_number_from);
        $to = $this->parseParentNumberInput($this->bulk_parent_number_to);

        if ($from === null && $to === null) {
            if ($withValidation) {
                $this->addError('bulk_parent_number_from', __('crud.parents.bulk_status.errors.number_range_required'));
            }

            return [null, null];
        }

        if ((filled($this->bulk_parent_number_from) && $from === null) || (filled($this->bulk_parent_number_to) && $to === null)) {
            if ($withValidation) {
                $this->addError('bulk_parent_number_from', __('crud.parents.bulk_status.errors.invalid_number_range'));
            }

            return [null, null];
        }

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    protected function parseParentNumberInput(string $value): ?int
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        $prefix = app(ParentNumberService::class)->prefix();

        if ($prefix !== '' && strncasecmp($normalized, $prefix, strlen($prefix)) === 0) {
            $normalized = substr($normalized, strlen($prefix));
        }

        $digits = preg_replace('/\D+/', '', $normalized);

        if ($digits === '') {
            return null;
        }

        return (int) ltrim($digits, '0') ?: 0;
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

    <div class="mobile-compact-highlights mobile-compact-highlights--two grid gap-4 md:grid-cols-2">
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.parents.stats.all') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.parents.stats.active') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
        </article>
    </div>

    <section class="surface-table mobile-records-surface standard-mobile-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('crud.parents.table.title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact" data-parent-table-controls>
                <div class="admin-filter-field">
                    <label class="sr-only" for="parent-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="parent-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="parent-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="parent-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="active">{{ __('crud.common.status_options.active') }}</option>
                        <option value="inactive">{{ __('crud.common.status_options.inactive') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    <a href="{{ route('parents.export', ['search' => $search, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($parents->isEmpty())
            <div class="admin-empty-state">{{ __('crud.parents.table.empty') }}</div>
        @else
            <div class="responsive-records-mobile">
                @foreach ($parents as $parent)
                    <article class="mobile-record-card">
                        <div class="mobile-record-card__header">
                            <div class="min-w-0">
                                <div class="mobile-record-card__title">{{ $parent->father_name }}</div>
                                <div class="mobile-record-card__subtitle">{{ $parent->parent_number ?: $parent->id }}</div>
                            </div>
                            <span class="{{ $parent->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                {{ $parent->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}
                            </span>
                        </div>

                        <dl class="mobile-record-card__details">
                            <div>
                                <dt>{{ __('crud.parents.table.headers.mother') }}</dt>
                                <dd>{{ $parent->mother_name ?: __('crud.common.not_available') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('crud.parents.table.headers.students') }}</dt>
                                <dd>{{ number_format($parent->students_count) }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('crud.parents.table.headers.father_phone') }}</dt>
                                <dd><bdi dir="ltr">{{ $parent->father_phone ?: __('crud.common.not_available') }}</bdi></dd>
                            </div>
                            <div>
                                <dt>{{ __('crud.parents.table.headers.mother_phone') }}</dt>
                                <dd><bdi dir="ltr">{{ $parent->mother_phone ?: __('crud.common.not_available') }}</bdi></dd>
                            </div>
                        </dl>

                        <div class="mobile-record-card__actions">
                            <button type="button" wire:click="viewAccount({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.account') }}</button>
                            @can('students.view')
                                <button type="button" wire:click="openChildrenModal({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.parents.children.action') }}</button>
                            @endcan
                            @can('parents.update')
                                <button type="button" wire:click="edit({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                            @endcan
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="responsive-records-desktop overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.father') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.parent_number') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.mother') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.students') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.father_phone') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.mother_phone') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.parents.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.parents.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($parents as $parent)
                            <tr>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $parent->father_name }}</td>
                                <td class="px-5 py-4 font-mono text-white lg:px-6">{{ $parent->parent_number ?: $parent->id }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $parent->mother_name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ number_format($parent->students_count) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6"><bdi dir="ltr" class="inline-block">{{ $parent->father_phone ?: __('crud.common.not_available') }}</bdi></td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6"><bdi dir="ltr" class="inline-block">{{ $parent->mother_phone ?: __('crud.common.not_available') }}</bdi></td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $parent->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ $parent->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button type="button" wire:click="viewAccount({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.account') }}</button>
                                        @can('students.view')
                                            <button type="button" wire:click="openChildrenModal({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.parents.children.action') }}</button>
                                        @endcan
                                        @can('parents.update')
                                            <button type="button" wire:click="edit({{ $parent->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
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
        :show="$showBulkStatusModal"
        :title="__('crud.parents.bulk_status.title')"
        :description="__('crud.parents.bulk_status.description')"
        close-method="closeBulkStatusModal"
        max-width="4xl"
    >
        <form wire:submit="applyBulkStatus" class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.bulk_status.fields.action') }}</label>
                    <select wire:model.live="bulk_status_action" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="deactivate">{{ __('crud.common.actions.deactivate') }}</option>
                        <option value="activate">{{ __('crud.common.actions.activate') }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.bulk_status.fields.scope') }}</label>
                    <select wire:model.live="bulk_scope" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="all">{{ __('crud.parents.bulk_status.scopes.all') }}</option>
                        <option value="parent_number_range">{{ __('crud.parents.bulk_status.scopes.parent_number_range') }}</option>
                    </select>
                </div>
            </div>

            @if ($bulk_scope === 'parent_number_range')
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.bulk_status.fields.number_from') }}</label>
                        <input wire:model.live="bulk_parent_number_from" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.parents.bulk_status.placeholders.number_from') }}">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.bulk_status.fields.number_to') }}</label>
                        <input wire:model.live="bulk_parent_number_to" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.parents.bulk_status.placeholders.number_to') }}">
                    </div>
                </div>
            @endif

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="bulk_sync_accounts" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.parents.bulk_status.fields.sync_accounts') }}</span>
            </label>

            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-neutral-200">
                <div>{{ __('crud.parents.bulk_status.preview.profiles', ['count' => number_format($bulkStatusPreview['profiles'])]) }}</div>
                <div class="mt-1 text-neutral-400">{{ __('crud.parents.bulk_status.preview.accounts', ['count' => number_format($bulkStatusPreview['accounts'])]) }}</div>
                <div class="mt-2 text-xs text-neutral-500">{{ __('crud.parents.bulk_status.help') }}</div>
            </div>

            @error('bulk_status')
                <div class="text-sm text-red-400">{{ $message }}</div>
            @enderror
            @error('bulk_parent_number_from')
                <div class="text-sm text-red-400">{{ $message }}</div>
            @enderror

            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="closeBulkStatusModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('crud.common.actions.bulk_status') }}</button>
            </div>
        </form>
    </x-admin.modal>

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
                    <div class="flex gap-2">
                        <input id="father-work" wire:model.live.debounce.300ms="father_work" list="father-work-options" class="min-w-0 flex-1 rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.parents.form.placeholders.new_father_work') }}">
                        @if (filled($father_work) && ! $fatherJobs->contains(fn ($fatherJob) => strcasecmp($fatherJob->name, trim($father_work)) === 0))
                            <button type="button" wire:click="createFatherJobShortcut" class="pill-link pill-link--compact" title="{{ __('crud.common.actions.create') }}" aria-label="{{ __('crud.common.actions.create') }}">+</button>
                        @endif
                    </div>
                    <datalist id="father-work-options">
                        @foreach ($fatherJobs as $fatherJob)
                            <option value="{{ $fatherJob->name }}">{{ $fatherJob->name }}</option>
                        @endforeach
                    </datalist>
                    @error('father_work')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                    @error('new_father_work')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="father-phone" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_phone') }}</label>
                    <x-phone-input id="father-phone" model="father_phone" :value="$father_phone" />
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
                    <x-phone-input id="mother-phone" model="mother_phone" :value="$mother_phone" />
                    @error('mother_phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="home-phone" class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.home_phone') }}</label>
                    <x-phone-input id="home-phone" model="home_phone" :value="$home_phone" />
                    @error('home_phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="parent-address" class="mb-1 block text-sm font-medium">
                        {{ __('crud.parents.form.fields.address') }}
                        <span class="text-xs font-normal text-neutral-400">{{ __('crud.parents.form.address_hint') }}</span>
                    </label>
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
                @if($editingId)@can('parents.update')<button type="button" wire:click="openAccountModal({{ $editingId }})" class="pill-link">{{ __('access.profile_accounts.title') }}</button>@endcan @can('parents.delete')<button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200">{{ __('crud.common.actions.delete') }}</button>@endcan @endif
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showChildrenModal"
        :title="__('crud.parents.children.title', ['name' => $childrenParentName ?: __('crud.common.not_available')])"
        :description="__('crud.parents.children.description', ['count' => number_format(count($childrenRows))])"
        close-method="closeChildrenModal"
        max-width="5xl"
    >
        @if ($childrenRows === [])
            <div class="rounded-3xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-neutral-300">
                {{ __('crud.parents.children.empty') }}
            </div>
        @else
            <div class="overflow-x-auto rounded-3xl border border-white/10 bg-white/5">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left">{{ __('crud.parents.children.headers.student') }}</th>
                            <th class="px-5 py-4 text-left">{{ __('crud.parents.children.headers.student_number') }}</th>
                            <th class="px-5 py-4 text-left">{{ __('crud.parents.children.headers.grade') }}</th>
                            <th class="px-5 py-4 text-left">{{ __('crud.parents.children.headers.group') }}</th>
                            <th class="px-5 py-4 text-left">{{ __('crud.parents.children.headers.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($childrenRows as $child)
                            @php
                                $statusClass = match ($child['status']) {
                                    'active' => 'status-chip status-chip--emerald',
                                    'graduated' => 'status-chip status-chip--gold',
                                    'blocked' => 'status-chip status-chip--rose',
                                    default => 'status-chip status-chip--slate',
                                };
                            @endphp
                            <tr>
                                <td class="px-5 py-4 text-white">{{ $child['name'] }}</td>
                                <td class="px-5 py-4 font-mono text-white">{{ $child['student_number'] }}</td>
                                <td class="px-5 py-4 text-neutral-300">{{ $child['grade_level'] }}</td>
                                <td class="px-5 py-4 text-neutral-300">{{ $child['group_name'] }}</td>
                                <td class="px-5 py-4">
                                    <span class="{{ $statusClass }}">{{ __('crud.common.status_options.'.$child['status']) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-4 flex justify-end">
            <button type="button" wire:click="closeChildrenModal" class="pill-link">
                {{ __('crud.common.actions.close') }}
            </button>
        </div>
    </x-admin.modal>

    <x-admin.modal :show="$showAccountViewModal" :title="__('access.profile_accounts.title')" close-method="$set('showAccountViewModal', false)" max-width="2xl">
        <div class="rounded-3xl border border-white/15 bg-white p-8 text-neutral-900 shadow-xl" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
            <div class="text-center text-2xl font-bold">{{ $account_father_name }}</div>
            <div class="mt-8 grid grid-cols-[auto_1fr] gap-x-5 gap-y-4 text-lg">
                <div class="font-semibold">{{ __('access.profile_accounts.fields.username') }}</div><div class="font-mono">{{ $account_username ?: __('crud.common.not_available') }}</div>
                <div class="font-semibold">{{ __('access.profile_accounts.fields.password') }}</div><div class="font-mono">{{ $issued_password ?: __('access.profile_accounts.empty.issued_password') }}</div>
            </div>
        </div>
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
                        <input wire:model="account_username" type="text" readonly class="w-full rounded-xl px-4 py-3 text-sm opacity-80">
                        @error('account_username')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                        <div class="mt-1 text-xs text-neutral-500">{{ __('access.profile_accounts.help.username') }}</div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.email') }}</label>
                        <input wire:model="account_email" type="email" readonly class="w-full rounded-xl px-4 py-3 text-sm opacity-75">
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
