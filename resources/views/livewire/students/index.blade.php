<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\GradeLevel;
use App\Models\ParentProfile;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\StudentGender;
use App\Services\ManagedUserService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $parent_id = null;
    public string $first_name = '';
    public string $last_name = '';
    public string $birth_date = '';
    public string $gender = '';
    public string $school_name = '';
    public ?int $grade_level_id = null;
    public ?int $quran_current_juz_id = null;
    public string $photo_path = '';
    public string $status = 'active';
    public string $joined_at = '';
    public string $notes = '';
    public ?int $accountStudentId = null;
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
    public bool $showQuickParentForm = false;
    public string $quick_parent_father_name = '';
    public string $quick_parent_father_work = '';
    public string $quick_parent_father_phone = '';
    public string $quick_parent_mother_name = '';
    public string $quick_parent_mother_phone = '';
    public string $quick_parent_home_phone = '';
    public string $quick_parent_address = '';

    public function mount(): void
    {
        $this->authorizePermission('students.view');
    }

    public function with(): array
    {
        $baseQuery = $this->scopeStudentsQuery(Student::query());
        $filteredQuery = $this->scopeStudentsQuery(Student::query())
            ->with(['parentProfile', 'gradeLevel', 'quranCurrentJuz'])
            ->withCount('enrollments')
            ->when(filled($this->search), function ($query) {
                $query->where(function ($builder) {
                    $builder
                        ->where('first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('student_number', 'like', '%'.$this->search.'%')
                        ->orWhere('school_name', 'like', '%'.$this->search.'%')
                        ->orWhereHas('parentProfile', fn ($parentQuery) => $parentQuery
                            ->where('father_name', 'like', '%'.$this->search.'%')
                            ->orWhere('mother_name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when(in_array($this->statusFilter, ['active', 'inactive', 'graduated', 'blocked'], true), fn ($query) => $query->where('status', $this->statusFilter))
            ->orderBy('last_name')
            ->orderBy('first_name');

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'students' => $filteredQuery->paginate($this->perPage),
            'parents' => $this->scopeParentsQuery(
                ParentProfile::query()
                    ->with(['students' => fn ($query) => $query->select('id', 'parent_id', 'last_name')->orderBy('last_name')])
                    ->where('is_active', true)
            )->orderBy('father_name')->get(['id', 'father_name', 'mother_name', 'father_phone', 'mother_phone', 'home_phone']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'juzs' => QuranJuz::query()->orderBy('juz_number')->get(['id', 'juz_number']),
            'totals' => [
                'all' => $baseQuery->count(),
                'active' => $this->scopeStudentsQuery(Student::query()->where('status', 'active'))->count(),
                'graduated' => $this->scopeStudentsQuery(Student::query()->where('status', 'graduated'))->count(),
            ],
            'filteredCount' => $filteredCount,
            'genders' => StudentGender::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['code', 'name']),
            'statuses' => ['active', 'inactive', 'graduated', 'blocked'],
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
            'parent_id' => ['required', 'exists:parents,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->isValidBirthYearValue((string) $value)) {
                    $fail(__('validation.date', ['attribute' => __('crud.students.form.fields.birth_year')]));
                }
            }],
            'gender' => ['nullable', Rule::exists('student_genders', 'code')],
            'school_name' => ['nullable', 'string', 'max:255'],
            'grade_level_id' => ['nullable', 'exists:grade_levels,id'],
            'quran_current_juz_id' => ['nullable', 'exists:quran_juzs,id'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive,graduated,blocked'],
            'joined_at' => ['nullable', 'date'],
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
        $this->authorizePermission('students.create');

        $this->cancel();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'students.update' : 'students.create');

        if ($this->editingId) {
            $this->authorizeScopedStudentAccess(Student::query()->findOrFail($this->editingId));
        }

        $validated = $this->validate();
        $this->authorizeScopedParentAccess(ParentProfile::query()->findOrFail($validated['parent_id']));
        $validated['birth_date'] = $this->normalizeBirthYearValue((string) $validated['birth_date']);
        $validated['gender'] = $validated['gender'] ?: null;
        $validated['grade_level_id'] = $validated['grade_level_id'] ?: null;
        $validated['quran_current_juz_id'] = $validated['quran_current_juz_id'] ?: null;
        $validated['photo_path'] = $validated['photo_path'] ?: null;
        $validated['joined_at'] = $validated['joined_at'] ?: null;
        $student = Student::query()->updateOrCreate(
            ['id' => $this->editingId],
            $validated,
        );

        $result = app(ManagedUserService::class)->syncLinkedUser(
            $student->user,
            [
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'phone' => null,
                'is_active' => $student->user?->is_active ?? ! in_array($validated['status'], ['inactive', 'blocked'], true),
            ],
            'student',
        );

        $student->user()->associate($result['user']);
        $student->save();

        if ($result['credentials']['password']) {
            session()->flash('generated_credentials', $result['credentials']);
        }

        session()->flash(
            'status',
            $this->editingId ? __('crud.students.messages.updated') : __('crud.students.messages.created'),
        );

        $this->cancel();
    }

    public function openQuickParentForm(): void
    {
        $this->authorizePermission('parents.create');

        $this->showQuickParentForm = true;
        $this->quick_parent_father_name = '';
        $this->quick_parent_father_work = '';
        $this->quick_parent_father_phone = '';
        $this->quick_parent_mother_name = '';
        $this->quick_parent_mother_phone = '';
        $this->quick_parent_home_phone = '';
        $this->quick_parent_address = '';
        $this->resetValidation([
            'quick_parent_father_name',
            'quick_parent_father_work',
            'quick_parent_father_phone',
            'quick_parent_mother_name',
            'quick_parent_mother_phone',
            'quick_parent_home_phone',
            'quick_parent_address',
        ]);
    }

    public function closeQuickParentForm(): void
    {
        $this->showQuickParentForm = false;
        $this->quick_parent_father_name = '';
        $this->quick_parent_father_work = '';
        $this->quick_parent_father_phone = '';
        $this->quick_parent_mother_name = '';
        $this->quick_parent_mother_phone = '';
        $this->quick_parent_home_phone = '';
        $this->quick_parent_address = '';
        $this->resetValidation([
            'quick_parent_father_name',
            'quick_parent_father_work',
            'quick_parent_father_phone',
            'quick_parent_mother_name',
            'quick_parent_mother_phone',
            'quick_parent_home_phone',
            'quick_parent_address',
        ]);
    }

    public function saveQuickParent(): void
    {
        $this->authorizePermission('parents.create');

        $validated = $this->validate([
            'quick_parent_father_name' => ['required', 'string', 'max:255'],
            'quick_parent_father_work' => ['nullable', 'string', 'max:255'],
            'quick_parent_father_phone' => ['nullable', 'string', 'max:30'],
            'quick_parent_mother_name' => ['nullable', 'string', 'max:255'],
            'quick_parent_mother_phone' => ['nullable', 'string', 'max:30'],
            'quick_parent_home_phone' => ['nullable', 'string', 'max:30'],
            'quick_parent_address' => ['nullable', 'string', 'max:255'],
        ], [], [
            'quick_parent_father_name' => __('crud.parents.form.fields.father_name'),
            'quick_parent_father_work' => __('crud.parents.form.fields.father_work'),
            'quick_parent_father_phone' => __('crud.parents.form.fields.father_phone'),
            'quick_parent_mother_name' => __('crud.parents.form.fields.mother_name'),
            'quick_parent_mother_phone' => __('crud.parents.form.fields.mother_phone'),
            'quick_parent_home_phone' => __('crud.parents.form.fields.home_phone'),
            'quick_parent_address' => __('crud.parents.form.fields.address'),
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => $validated['quick_parent_father_name'],
            'father_work' => $validated['quick_parent_father_work'] ?: null,
            'father_phone' => $validated['quick_parent_father_phone'] ?: null,
            'mother_name' => $validated['quick_parent_mother_name'] ?: null,
            'mother_phone' => $validated['quick_parent_mother_phone'] ?: null,
            'home_phone' => $validated['quick_parent_home_phone'] ?: null,
            'address' => $validated['quick_parent_address'] ?: null,
            'is_active' => true,
        ]);

        $this->parent_id = $parent->id;
        session()->flash('status', __('crud.students.messages.parent_shortcut_created', ['name' => $parent->father_name]));
        $this->closeQuickParentForm();
    }

    public function edit(int $studentId): void
    {
        $this->authorizePermission('students.update');

        $student = Student::query()->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);

        $this->editingId = $student->id;
        $this->parent_id = $student->parent_id;
        $this->first_name = $student->first_name;
        $this->last_name = $student->last_name;
        $this->birth_date = $student->birth_date?->format('Y') ?? '';
        $this->gender = $student->gender ?? '';
        $this->school_name = $student->school_name ?? '';
        $this->grade_level_id = $student->grade_level_id;
        $this->quran_current_juz_id = $student->quran_current_juz_id;
        $this->photo_path = $student->photo_path ?? '';
        $this->status = $student->status;
        $this->joined_at = $student->joined_at?->format('Y-m-d') ?? '';
        $this->notes = $student->notes ?? '';
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function openAccountModal(int $studentId): void
    {
        $this->authorizePermission('students.update');

        $student = Student::query()->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);

        $this->accountStudentId = $student->id;
        $this->account_username = $student->user?->username ?? '';
        $this->account_email = $student->user?->email ?? '';
        $this->account_password = '';
        $this->account_is_active = $student->user?->is_active ?? ! in_array($student->status, ['inactive', 'blocked'], true);
        $this->issued_password = $student->user?->issued_password;
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
        $this->authorizePermission('students.update');

        $this->account_password = Str::password(10);
    }

    public function saveAccount(): void
    {
        $this->authorizePermission('students.update');

        $student = Student::query()->findOrFail($this->accountStudentId);
        $this->authorizeScopedStudentAccess($student);

        $validated = $this->validate($this->accountRules());
        $result = app(ManagedUserService::class)->syncLinkedUser(
            $student->user,
            [
                'name' => trim($student->first_name.' '.$student->last_name),
                'username' => $validated['account_username'] ?: null,
                'email' => $validated['account_email'] ?: null,
                'phone' => null,
                'password' => $validated['account_password'] ?: null,
                'is_active' => (bool) $validated['account_is_active'],
            ],
            'student',
        );

        $student->user()->associate($result['user']);
        $student->save();

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
        $this->accountStudentId = null;
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
        $this->parent_id = null;
        $this->first_name = '';
        $this->last_name = '';
        $this->birth_date = '';
        $this->gender = $this->defaultGenderCode();
        $this->school_name = '';
        $this->grade_level_id = null;
        $this->quran_current_juz_id = null;
        $this->photo_path = '';
        $this->status = 'active';
        $this->joined_at = '';
        $this->notes = '';
        $this->showFormModal = false;
        $this->showQuickParentForm = false;
        $this->quick_parent_father_name = '';
        $this->quick_parent_father_work = '';
        $this->quick_parent_father_phone = '';
        $this->quick_parent_mother_name = '';
        $this->quick_parent_mother_phone = '';
        $this->quick_parent_home_phone = '';
        $this->quick_parent_address = '';

        $this->resetValidation();
    }

    protected function defaultGenderCode(): string
    {
        $defaultGenderCode = StudentGender::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('code');

        if ($defaultGenderCode) {
            return (string) $defaultGenderCode;
        }

        return (string) (StudentGender::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('code') ?? '');
    }

    public function delete(int $studentId): void
    {
        $this->authorizePermission('students.delete');

        $student = Student::query()->withCount('enrollments')->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);

        if ($student->enrollments_count > 0) {
            $this->addError('delete', __('crud.students.errors.delete_linked'));

            return;
        }

        $student->delete();

        if ($this->editingId === $studentId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.students.messages.deleted'));
    }

    protected function isValidBirthYearValue(string $value): bool
    {
        return $this->normalizeBirthYearValue($value) !== null;
    }

    protected function normalizeBirthYearValue(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}$/', $value) === 1) {
            $year = (int) $value;

            return $year >= 1900 && $year <= ((int) now()->format('Y') + 1)
                ? $value.'-01-01'
                : null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            [$year, $month, $day] = array_map('intval', explode('-', $value));

            return checkdate($month, $day, $year) ? $value : null;
        }

        return null;
    }

    protected function linkedUserId(): ?int
    {
        $profileId = $this->accountStudentId ?? $this->editingId;

        return $profileId
            ? Student::query()->whereKey($profileId)->value('user_id')
            : null;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('crud.students.hero.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.students.hero.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.students.hero.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('crud.students.hero.badges.active_parents', ['count' => number_format($parents->count())]) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('crud.students.hero.badges.grade_levels', ['count' => number_format($gradeLevels->count())]) }}</span>
            <span class="badge-soft">{{ __('crud.students.hero.badges.juz_references', ['count' => number_format($juzs->count())]) }}</span>
        </div>
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
            <div class="kpi-label">{{ __('crud.students.stats.all.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['all']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.students.stats.all.description') }}</p>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.students.stats.active.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['active']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.students.stats.active.description') }}</p>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.students.stats.graduated.label') }}</div>
            <div class="metric-value mt-6">{{ number_format($totals['graduated']) }}</div>
            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('crud.students.stats.graduated.description') }}</p>
        </article>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('crud.students.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('crud.students.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="student-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="student-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="student-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="student-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        @foreach ($statuses as $studentStatus)
                            <option value="{{ $studentStatus }}">{{ __('crud.common.status_options.'.$studentStatus) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('students.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('students.export', ['search' => $search, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('crud.students.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($students->isEmpty())
            <div class="admin-empty-state">{{ __('crud.students.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.student_number') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.parent') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.grade') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.juz') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.enrollments') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.students.table.headers.status') }}</th>
                            @if (auth()->user()->can('students.view') || auth()->user()->can('students.update') || auth()->user()->can('students.delete'))
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.students.table.headers.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($students as $student)
                            @php
                                $studentStatusClass = match ($student->status) {
                                    'active' => 'status-chip status-chip--emerald',
                                    'graduated' => 'status-chip status-chip--gold',
                                    'blocked' => 'status-chip status-chip--rose',
                                    default => 'status-chip status-chip--slate',
                                };
                            @endphp
                            <tr>
                                  <td class="px-5 py-4 lg:px-6">
                                      <div class="student-inline">
                                          <x-student-avatar :student="$student" size="sm" />
                                          <div class="student-inline__body">
                                              <div class="student-inline__name">{{ $student->first_name }} {{ $student->last_name }}</div>
                                              <div class="student-inline__meta">{{ $student->school_name ?: __('crud.students.table.no_school') }}</div>
                                          </div>
                                      </div>
                                  </td>
                                  <td class="px-5 py-4 font-mono text-white lg:px-6">{{ $student->student_number ?: $student->id }}</td>
                                   <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $student->parentProfile?->father_name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $student->gradeLevel?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $student->quranCurrentJuz ? __('crud.students.labels.juz_number', ['number' => $student->quranCurrentJuz->juz_number]) : __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $student->enrollments_count }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="{{ $studentStatusClass }}">{{ __('crud.common.status_options.'.$student->status) }}</span></td>
                                @if (auth()->user()->can('students.view') || auth()->user()->can('students.update') || auth()->user()->can('students.delete'))
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <a href="{{ route('students.progress', $student) }}" wire:navigate class="pill-link pill-link--compact">
                                                {{ __('crud.common.actions.progress') }}
                                            </a>
                                            @can('student-notes.view')
                                                <a href="{{ route('student-notes.index', ['student' => $student->id]) }}" wire:navigate class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.notes') }}
                                                </a>
                                            @endcan
                                            @can('students.update')
                                                <button type="button" wire:click="openAccountModal({{ $student->id }})" class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.account') }}
                                                </button>
                                            @endcan
                                            <a href="{{ route('students.files', $student) }}" wire:navigate class="pill-link pill-link--compact">
                                                {{ __('crud.common.actions.media') }}
                                            </a>
                                            @can('students.update')
                                                <button type="button" wire:click="edit({{ $student->id }})" class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.edit') }}
                                                </button>
                                            @endcan
                                            @can('students.delete')
                                                <button type="button" wire:click="delete({{ $student->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                                    {{ __('crud.common.actions.delete') }}
                                                </button>
                                            @endcan
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($students->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $students->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.students.form.edit_title') : __('crud.students.form.create_title')"
        :description="__('crud.students.form.help')"
        close-method="cancel"
        max-width="5xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="student-first-name" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.first_name') }}</label>
                    <input id="student-first-name" wire:model="first_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('first_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="student-last-name" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.last_name') }}</label>
                    <input id="student-last-name" wire:model="last_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('last_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div>
                <div class="mb-1 flex flex-wrap items-center justify-between gap-3">
                    <label for="student-parent" class="block text-sm font-medium">{{ __('crud.students.form.fields.parent') }}</label>
                    @can('parents.create')
                        <button type="button" wire:click="{{ $showQuickParentForm ? 'closeQuickParentForm' : 'openQuickParentForm' }}" class="pill-link pill-link--compact">
                            {{ $showQuickParentForm ? __('crud.students.form.parent_shortcut.cancel') : __('crud.students.form.parent_shortcut.action') }}
                        </button>
                    @endcan
                </div>
                <select
                    id="student-parent"
                    wire:model="parent_id"
                    data-search-hint-target="student-last-name"
                    class="w-full rounded-xl px-4 py-3 text-sm"
                >
                    <option value="">{{ __('crud.students.form.placeholders.select_parent') }}</option>
                    @foreach ($parents as $parent)
                        @php
                            $studentLastNames = $parent->students->pluck('last_name')->filter()->unique()->values();
                            $parentSearch = collect([
                                $parent->father_name,
                                $parent->mother_name,
                                $parent->father_phone,
                                $parent->mother_phone,
                                $parent->home_phone,
                                $studentLastNames->implode(' '),
                            ])->filter()->implode(' ');
                        @endphp
                        <option value="{{ $parent->id }}" data-search="{{ $parentSearch }}">
                            {{ $parent->father_name }}{{ $studentLastNames->isNotEmpty() ? ' | '.$studentLastNames->implode(', ') : '' }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-neutral-500">{{ __('crud.students.form.parent_search_help') }}</p>
                @error('parent_id')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            @if ($showQuickParentForm)
                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">{{ __('crud.students.form.parent_shortcut.title') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('crud.students.form.parent_shortcut.help') }}</p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_name') }}</label>
                            <input wire:model="quick_parent_father_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_father_name')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_phone') }}</label>
                            <input wire:model="quick_parent_father_phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_father_phone')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_work') }}</label>
                            <input wire:model="quick_parent_father_work" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_father_work')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.home_phone') }}</label>
                            <input wire:model="quick_parent_home_phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_home_phone')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.mother_name') }}</label>
                            <input wire:model="quick_parent_mother_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_mother_name')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.mother_phone') }}</label>
                            <input wire:model="quick_parent_mother_phone" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_mother_phone')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.address') }}</label>
                        <input wire:model="quick_parent_address" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('quick_parent_address')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="saveQuickParent" class="pill-link pill-link--accent">
                            {{ __('crud.students.form.parent_shortcut.save') }}
                        </button>
                        <button type="button" wire:click="closeQuickParentForm" class="pill-link">
                            {{ __('crud.students.form.parent_shortcut.cancel') }}
                        </button>
                    </div>
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="student-birth-date" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.birth_year') }}</label>
                    <input id="student-birth-date" wire:model="birth_date" type="number" min="1900" max="{{ now()->format('Y') + 1 }}" step="1" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('birth_date')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="student-gender" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.gender') }}</label>
                    <select id="student-gender" wire:model="gender" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.students.form.placeholders.select_gender') }}</option>
                        @foreach ($genders as $studentGender)
                            <option value="{{ $studentGender->code }}">{{ $studentGender->name }}</option>
                        @endforeach
                    </select>
                    @error('gender')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="student-school" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.school') }}</label>
                    <input id="student-school" wire:model="school_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('school_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="student-grade-level" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.grade_level') }}</label>
                    <select id="student-grade-level" wire:model="grade_level_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.students.form.placeholders.select_grade') }}</option>
                        @foreach ($gradeLevels as $gradeLevel)
                            <option value="{{ $gradeLevel->id }}">{{ $gradeLevel->name }}</option>
                        @endforeach
                    </select>
                    @error('grade_level_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="student-juz" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.current_juz') }}</label>
                    <select id="student-juz" wire:model="quran_current_juz_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.students.form.placeholders.select_juz') }}</option>
                        @foreach ($juzs as $juz)
                            <option value="{{ $juz->id }}">{{ __('crud.students.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                        @endforeach
                    </select>
                    @error('quran_current_juz_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                @if ($editingId)
                    <div>
                        <label for="student-status" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.status') }}</label>
                        <select id="student-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                            @foreach ($statuses as $studentStatus)
                                <option value="{{ $studentStatus }}">{{ __('crud.common.status_options.'.$studentStatus) }}</option>
                            @endforeach
                        </select>
                        @error('status')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="student-joined-at" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.joined_at') }}</label>
                    <input id="student-joined-at" wire:model="joined_at" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('joined_at')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.students.form.update_submit') : __('crud.students.form.create_submit') }}
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
