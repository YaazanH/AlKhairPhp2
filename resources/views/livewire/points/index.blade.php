<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Services\PointLedgerService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public ?int $editingTransactionId = null;
    public ?int $selectedStudentId = null;
    public ?int $selectedEnrollmentId = null;
    public ?int $manual_point_type_id = null;
    public string $search = '';
    public string $stateFilter = 'active';
    public string $sortField = 'entered_at';
    public string $sortDirection = 'desc';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showVoidModal = false;
    public ?int $voidTransactionId = null;
    public string $void_reason = '';

    protected array $sortableFields = [
        'entered_at',
        'points',
        'point_type',
        'state',
        'student',
    ];

    public function mount(): void
    {
        $this->authorizePermission('points.view');
        $this->resetManualForm();
    }

    public function with(): array
    {
        $transactionsQuery = $this->visiblePointTransactionsQuery(
            PointTransaction::query()->with([
                'enteredBy',
                'pointType',
                'policy',
                'voidedBy',
                'enrollment.group.course',
                'student.parentProfile',
            ])
        )
            ->when(filled($this->search), function (Builder $query) {
                $search = '%'.$this->search.'%';

                $query->where(function (Builder $builder) use ($search) {
                    $builder
                        ->whereHas('student', function (Builder $studentQuery) use ($search) {
                            $studentQuery
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        })
                        ->orWhereHas('enrollment.group', fn (Builder $groupQuery) => $groupQuery->where('name', 'like', $search))
                        ->orWhereHas('pointType', fn (Builder $typeQuery) => $typeQuery->where('name', 'like', $search))
                        ->orWhere('notes', 'like', $search);
                });
            })
            ->when($this->stateFilter === 'active', fn (Builder $query) => $query->effectiveActive())
            ->when($this->stateFilter === 'voided', fn (Builder $query) => $query->whereNotNull('voided_at'));
        $this->applyPointSort($transactionsQuery);

        $studentOptions = $this->scopeStudentsQuery(
            Student::query()
                ->with('parentProfile')
                ->whereHas('enrollments', function (Builder $query) {
                    $this->scopeEnrollmentsQuery($query)->where('status', 'active');
                })
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return [
            'transactions' => $transactionsQuery->paginate($this->perPage),
            'filteredCount' => (clone $transactionsQuery)->count(),
            'studentOptions' => $studentOptions,
            'enrollmentOptions' => $this->availableEnrollmentsQuery()
                ->with(['group.course'])
                ->orderByDesc('enrolled_at')
                ->orderByDesc('id')
                ->get(),
            'manualPointTypes' => PointType::query()
                ->where('is_active', true)
                ->where('allow_manual_entry', true)
                ->where('default_points', '!=', 0)
                ->where(fn (Builder $query) => $query->where('allow_negative', true)->orWhere('default_points', '>', 0))
                ->orderBy('name')
                ->get(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = in_array($field, ['student', 'point_type', 'state'], true) ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function updatedSelectedStudentId(): void
    {
        $enrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->selectedEnrollmentId = $enrollmentIds[0] ?? null;

        if ($this->editingTransactionId) {
            $this->editingTransactionId = null;
        }

        $this->resetValidation([
            'selectedStudentId',
            'selectedEnrollmentId',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('points.create-manual');

        $this->resetManualForm();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->resetManualForm();
        $this->showFormModal = false;
    }

    public function editManual(int $transactionId): void
    {
        $this->authorizePermission('points.create-manual');

        $transaction = $this->visiblePointTransactionsQuery(PointTransaction::query())
            ->findOrFail($transactionId);

        if ($transaction->source_type !== 'manual' || $transaction->voided_at) {
            $this->addError('manual_point_type_id', __('workflow.points.errors.edit_manual_only'));

            return;
        }

        $this->editingTransactionId = $transaction->id;
        $this->selectedStudentId = $transaction->student_id;
        $this->selectedEnrollmentId = $transaction->enrollment_id;
        $this->manual_point_type_id = $transaction->point_type_id;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function saveManual(): void
    {
        $this->authorizePermission('points.create-manual');

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'manual_point_type_id' => ['required', 'exists:point_types,id'],
        ], [], [
            'selectedStudentId' => __('workflow.points.workbench.form.student'),
            'selectedEnrollmentId' => __('workflow.points.workbench.form.group'),
        ]);

        $pointType = PointType::query()->findOrFail((int) $validated['manual_point_type_id']);
        $points = (int) $pointType->default_points;

        if (! $pointType->is_active || ! $pointType->allow_manual_entry || $points === 0) {
            $this->addError('manual_point_type_id', __('workflow.points.errors.invalid_manual_point_type'));

            return;
        }

        if (! $pointType->allow_negative && $points < 0) {
            $this->addError('manual_point_type_id', __('workflow.points.errors.negative_not_allowed'));

            return;
        }

        if ($this->editingTransactionId) {
            $transaction = $this->visiblePointTransactionsQuery(PointTransaction::query())
                ->findOrFail($this->editingTransactionId);

            if ($transaction->source_type !== 'manual' || $transaction->voided_at) {
                $this->addError('manual_point_type_id', __('workflow.points.errors.edit_manual_only'));

                return;
            }

            $enrollment = $this->scopeEnrollmentsQuery(Enrollment::query()->with(['student', 'group.course']))
                ->findOrFail($transaction->enrollment_id);

            if (! app(PointLedgerService::class)->enrollmentAwardsPoints($enrollment)) {
                $this->addError('manual_point_type_id', __('workflow.points.errors.course_points_disabled'));

                return;
            }

            $transaction->update([
                'point_type_id' => $pointType->id,
                'points' => $points,
                'notes' => null,
            ]);
        } else {
            $student = $this->scopeStudentsQuery(Student::query())->findOrFail($validated['selectedStudentId']);
            $this->authorizeScopedStudentAccess($student);

            $availableEnrollmentIds = $this->availableEnrollmentsQuery()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($availableEnrollmentIds === []) {
                $this->addError('selectedStudentId', __('workflow.points.errors.no_active_enrollment'));

                return;
            }

            $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
            $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];

            $enrollment = $this->scopeEnrollmentsQuery(Enrollment::query()->with(['student', 'group.course']))
                ->findOrFail((int) $validated['selectedEnrollmentId']);

            if (! app(PointLedgerService::class)->recordManualPoints($enrollment, $pointType, $points)) {
                $this->addError('manual_point_type_id', __('workflow.points.errors.course_points_disabled'));

                return;
            }
        }

        app(PointLedgerService::class)->syncEnrollmentCaches($enrollment->fresh(['student']));

        session()->flash(
            'status',
            $this->editingTransactionId
                ? __('workflow.points.messages.updated')
                : __('workflow.points.messages.created'),
        );

        $this->closeFormModal();
    }

    public function saveManualAndNew(): void
    {
        $preservedPointTypeId = $this->manual_point_type_id;
        $errorCount = $this->getErrorBag()->count();

        $this->saveManual();

        if ($this->getErrorBag()->count() > $errorCount) {
            return;
        }

        $this->editingTransactionId = null;
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->manual_point_type_id = $preservedPointTypeId;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function openVoidModal(int $transactionId): void
    {
        $this->authorizePermission('points.void');

        $this->visiblePointTransactionsQuery(PointTransaction::query())
            ->whereNull('voided_at')
            ->findOrFail($transactionId);

        $this->voidTransactionId = $transactionId;
        $this->void_reason = '';
        $this->showVoidModal = true;
        $this->resetValidation(['void_reason']);
    }

    public function closeVoidModal(): void
    {
        $this->showVoidModal = false;
        $this->voidTransactionId = null;
        $this->void_reason = '';
        $this->resetValidation(['void_reason']);
    }

    public function voidSelected(): void
    {
        $this->authorizePermission('points.void');

        $validated = $this->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ], [], [
            'void_reason' => __('workflow.points.void.form.reason'),
        ]);

        if (! $this->voidTransactionId) {
            $this->addError('void_reason', __('workflow.points.errors.void_transaction_missing'));

            return;
        }

        $transaction = $this->visiblePointTransactionsQuery(PointTransaction::query())
            ->whereKey($this->voidTransactionId)
            ->first();

        if (! $transaction) {
            $this->addError('void_reason', __('workflow.points.errors.void_transaction_missing'));

            return;
        }

        if ($transaction->voided_at) {
            $this->closeVoidModal();

            return;
        }

        $transaction->update([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => $validated['void_reason'],
        ]);

        $enrollment = $transaction->enrollment_id
            ? $this->scopeEnrollmentsQuery(Enrollment::query()->with('student'))->find($transaction->enrollment_id)
            : null;

        if ($enrollment) {
            app(PointLedgerService::class)->syncEnrollmentCaches($enrollment->fresh(['student']));
        }

        if ($this->editingTransactionId === $transaction->id) {
            $this->resetManualForm();
            $this->showFormModal = false;
        }

        $this->closeVoidModal();

        session()->flash('status', __('workflow.points.messages.voided'));
    }

    public function resetManualForm(): void
    {
        $this->editingTransactionId = null;
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->manual_point_type_id = null;
        $this->resetValidation();
    }

    protected function availableEnrollmentsQuery(): Builder
    {
        return $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->where('status', 'active')
                ->when($this->selectedStudentId, fn (Builder $query) => $query->where('student_id', $this->selectedStudentId))
                ->when(! $this->selectedStudentId, fn (Builder $query) => $query->whereRaw('1 = 0'))
        );
    }

    protected function visiblePointTransactionsQuery(Builder $query): Builder
    {
        return $this->scopePointTransactionsQuery($query)
            ->where(function (Builder $transactionQuery): void {
                $transactionQuery
                    ->whereNull('enrollment_id')
                    ->orWhereHas('enrollment', fn (Builder $enrollmentQuery) => $enrollmentQuery
                        ->whereNull('course_finished_at')
                        ->whereDoesntHave('group.course', fn (Builder $courseQuery) => $courseQuery->whereNotNull('finished_at')));
            });
    }

    protected function applyPointSort(Builder $query): void
    {
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        match ($this->sortField) {
            'points' => $query->orderBy('points', $direction)->orderByDesc('id'),
            'point_type' => $query
                ->orderBy(
                    PointType::query()
                        ->select('name')
                        ->whereColumn('point_types.id', 'point_transactions.point_type_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
            'state' => $query->orderByRaw('case when voided_at is null then 0 else 1 end '.($direction === 'desc' ? 'desc' : 'asc'))->orderByDesc('id'),
            'student' => $query
                ->orderBy(
                    Student::query()
                        ->select('first_name')
                        ->whereColumn('students.id', 'point_transactions.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy(
                    Student::query()
                        ->select('last_name')
                        ->whereColumn('students.id', 'point_transactions.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
            default => $query->orderBy('entered_at', $direction)->orderBy('id', $direction),
        };
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.points.workbench.title') }}</h1>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table points-ledger-surface">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('workflow.points.workbench.table.title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                <div class="admin-filter-field">
                    <label class="sr-only" for="points-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="points-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="points-state-filter">{{ __('workflow.points.workbench.filters.state') }}</label>
                    <select id="points-state-filter" wire:model.live="stateFilter">
                        <option value="all">{{ __('workflow.points.workbench.filters.all_states') }}</option>
                        <option value="active">{{ __('workflow.common.ledger_state.active') }}</option>
                        <option value="voided">{{ __('workflow.common.ledger_state.voided') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('points.create-manual')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.points.workbench.create') }}</button>
                    @endcan
                </div>
            </div>
        </div>

        @if ($transactions->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.points.workbench.table.empty') }}</div>
        @else
            <div class="points-ledger-desktop overflow-hidden">
                <table class="points-ledger-table w-full table-fixed text-sm" data-has-void-reason="{{ $stateFilter !== 'active' ? 'true' : 'false' }}">
                    <colgroup>
                        <col class="points-ledger-col--student">
                        <col class="points-ledger-col--group">
                        <col class="points-ledger-col--entered">
                        <col class="points-ledger-col--type">
                        <col class="points-ledger-col--source">
                        <col class="points-ledger-col--points">
                        <col class="points-ledger-col--state">
                        @if ($stateFilter !== 'active')
                            <col class="points-ledger-col--void-reason">
                        @endif
                        <col class="points-ledger-col--actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-3 py-4 text-left">
                                <button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.points.workbench.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-4 text-left">{{ __('workflow.points.workbench.table.headers.group') }}</th>
                            <th class="px-3 py-4 text-left">
                                <button type="button" wire:click="sortBy('entered_at')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.points.workbench.table.headers.entered_at') }} <span>{{ $this->sortIndicator('entered_at') }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-4 text-left">
                                <button type="button" wire:click="sortBy('point_type')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.points.workbench.table.headers.type') }} <span>{{ $this->sortIndicator('point_type') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.source') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('points')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.points.workbench.table.headers.points') }} <span>{{ $this->sortIndicator('points') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('state')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.points.workbench.table.headers.state') }} <span>{{ $this->sortIndicator('state') }}</span>
                                </button>
                            </th>
                            @if ($stateFilter !== 'active')
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.void_reason') }}</th>
                            @endif
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.points.workbench.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($transactions as $transaction)
                            @php
                                $sourceTranslationKey = 'workflow.common.source_type.' . $transaction->source_type;
                                $sourceLabel = trans()->has($sourceTranslationKey)
                                    ? __($sourceTranslationKey)
                                    : str($transaction->source_type)->headline();
                                $state = $transaction->effectiveState();
                            @endphp
                            <tr class="{{ $state !== 'active' ? 'opacity-60' : '' }}">
                                <td class="px-3 py-4">
                                    @if ($transaction->student)
                                        <div class="student-inline">
                                            <x-student-avatar :student="$transaction->student" size="sm" />
                                            <div class="student-inline__body">
                                                <div class="student-inline__name whitespace-nowrap">{{ trim($transaction->student->first_name.' '.$transaction->student->last_name) }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-4 text-neutral-300">
                                    <div class="truncate font-medium text-white" title="{{ $transaction->enrollment?->group?->name }}">{{ $transaction->enrollment?->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 truncate text-xs uppercase tracking-[0.12em] text-neutral-500" title="{{ $transaction->enrollment?->group?->course?->name }}">{{ $transaction->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <span class="points-ledger-entered-at" dir="ltr">
                                        <span>{{ $transaction->entered_at?->format('d-m-Y') }}</span>
                                        <span>{{ $transaction->entered_at?->format('H:i') }}</span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-white lg:px-6">{{ $transaction->pointType?->name ?: __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $sourceLabel }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $transaction->points >= 0 ? 'status-chip status-chip--emerald' : 'status-chip status-chip--rose' }}">{{ $transaction->points }}</span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $state === 'active' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('workflow.common.ledger_state.'.$state) }}
                                    </span>
                                </td>
                                @if ($stateFilter !== 'active')
                                    <td class="max-w-xs px-5 py-4 text-neutral-300 lg:px-6">
                                        @if ($transaction->voided_at)
                                            <div class="line-clamp-2">{{ $transaction->void_reason ?: __('crud.common.not_available') }}</div>
                                        @else
                                            <span class="text-neutral-500">{{ __('crud.common.not_available') }}</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @if (auth()->user()->can('points.create-manual') && $transaction->source_type === 'manual' && ! $transaction->voided_at)
                                            <button type="button" wire:click="editManual({{ $transaction->id }})" class="pill-link pill-link--compact">{{ __('workflow.common.actions.edit') }}</button>
                                        @elseif (auth()->user()->can('points.void') && ! $transaction->voided_at)
                                            <button type="button" wire:click="openVoidModal({{ $transaction->id }})" class="pill-link pill-link--compact pill-link--danger">{{ __('crud.common.actions.delete') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="points-ledger-mobile">
                @foreach ($transactions as $transaction)
                    @php
                        $sourceTranslationKey = 'workflow.common.source_type.' . $transaction->source_type;
                        $sourceLabel = trans()->has($sourceTranslationKey)
                            ? __($sourceTranslationKey)
                            : str($transaction->source_type)->headline();
                        $state = $transaction->effectiveState();
                    @endphp
                    <article class="points-ledger-mobile__item {{ $state !== 'active' ? 'points-ledger-mobile__item--inactive' : '' }}" wire:key="points-mobile-{{ $transaction->id }}">
                        <div class="points-ledger-mobile__header">
                            @if ($transaction->student)
                                <div class="student-inline min-w-0">
                                    <x-student-avatar :student="$transaction->student" size="sm" />
                                    <div class="student-inline__body min-w-0">
                                        <div class="points-ledger-mobile__student-name">{{ trim($transaction->student->first_name.' '.$transaction->student->last_name) }}</div>
                                    </div>
                                </div>
                            @else
                                <span class="text-white">{{ __('crud.common.not_available') }}</span>
                            @endif

                            <span class="{{ $transaction->points >= 0 ? 'status-chip status-chip--emerald' : 'status-chip status-chip--rose' }}">
                                <bdi>{{ $transaction->points }}</bdi>
                            </span>
                        </div>

                        <div class="points-ledger-mobile__group">
                            <div>{{ $transaction->enrollment?->group?->name ?: __('workflow.common.no_group') }}</div>
                            <small>{{ $transaction->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</small>
                        </div>

                        <dl class="points-ledger-mobile__metrics">
                            <div>
                                <dt>{{ __('workflow.points.workbench.table.headers.entered_at') }}</dt>
                                <dd>
                                    <span class="points-ledger-entered-at" dir="ltr">
                                        <span>{{ $transaction->entered_at?->format('d-m-Y') }}</span>
                                        <span>{{ $transaction->entered_at?->format('H:i') }}</span>
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt>{{ __('workflow.points.workbench.table.headers.type') }}</dt>
                                <dd>{{ $transaction->pointType?->name ?: __('workflow.common.not_available') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('workflow.points.workbench.table.headers.source') }}</dt>
                                <dd>{{ $sourceLabel }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('workflow.points.workbench.table.headers.state') }}</dt>
                                <dd>
                                    <span class="{{ $state === 'active' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('workflow.common.ledger_state.'.$state) }}
                                    </span>
                                </dd>
                            </div>
                            @if ($stateFilter !== 'active')
                                <div class="points-ledger-mobile__void-reason">
                                    <dt>{{ __('workflow.points.workbench.table.headers.void_reason') }}</dt>
                                    <dd>{{ $transaction->voided_at ? ($transaction->void_reason ?: __('crud.common.not_available')) : __('crud.common.not_available') }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ((auth()->user()->can('points.create-manual') && $transaction->source_type === 'manual' && ! $transaction->voided_at) || (auth()->user()->can('points.void') && ! $transaction->voided_at))
                            <div class="points-ledger-mobile__actions">
                                @if (auth()->user()->can('points.create-manual') && $transaction->source_type === 'manual')
                                    <button type="button" wire:click="editManual({{ $transaction->id }})" class="pill-link pill-link--compact">{{ __('workflow.common.actions.edit') }}</button>
                                @else
                                    <button type="button" wire:click="openVoidModal({{ $transaction->id }})" class="pill-link pill-link--compact pill-link--danger">{{ __('crud.common.actions.delete') }}</button>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>

            @if ($transactions->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $transactions->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingTransactionId ? __('workflow.points.workbench.form.edit_title') : __('workflow.points.workbench.form.title')"
        close-method="closeFormModal"
        max-width="fit"
        compact
    >
        <form wire:submit="saveManual" class="w-[min(28rem,calc(100vw-3rem))] space-y-3">
            <div class="space-y-3">
                <div>
                    <label for="points-workbench-student" class="mb-1 block text-sm font-medium">{{ __('workflow.points.workbench.form.student') }}</label>
                    <select id="points-workbench-student" wire:model.live="selectedStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" class="w-full rounded-xl px-4 py-3 text-sm" @disabled($editingTransactionId !== null)>
                        <option value="">{{ __('workflow.points.workbench.form.select_student') }}</option>
                        @foreach ($studentOptions as $student)
                            <option value="{{ $student->id }}">
                                {{ trim($student->first_name.' '.$student->last_name) }}
                            </option>
                        @endforeach
                    </select>
                    @error('selectedStudentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="points-workbench-type" class="mb-1 block text-sm font-medium">{{ __('workflow.points.form.point_type') }}</label>
                    <select id="points-workbench-type" wire:model="manual_point_type_id" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.points.form.select_point_type') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.points.form.select_point_type') }}</option>
                        @foreach ($manualPointTypes as $pointType)
                            <option value="{{ $pointType->id }}">{{ $pointType->name }} ({{ $pointType->default_points > 0 ? '+'.$pointType->default_points : $pointType->default_points }})</option>
                        @endforeach
                    </select>
                    @error('manual_point_type_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-start gap-2">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingTransactionId ? __('workflow.common.actions.update_point_entry') : __('workflow.common.actions.save_point_entry') }}
                </button>
                @if ($editingTransactionId && auth()->user()->can('points.void'))
                    <button type="button" wire:click="openVoidModal({{ $editingTransactionId }})" class="pill-link pill-link--danger">{{ __('crud.common.actions.delete') }}</button>
                @endif
                <x-admin.create-and-new-button :show="! $editingTransactionId" click="saveManualAndNew" />
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showVoidModal"
        :title="__('workflow.points.void.title')"
        :description="__('workflow.points.void.description')"
        close-method="closeVoidModal"
        max-width="xl"
    >
        <form wire:submit="voidSelected" class="space-y-4">
            <div>
                <label for="point-void-reason" class="mb-1 block text-sm font-medium">{{ __('workflow.points.void.form.reason') }}</label>
                <textarea id="point-void-reason" wire:model="void_reason" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('void_reason')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="voidSelected" class="pill-link pill-link--danger">
                    {{ __('crud.common.actions.delete') }}
                </button>
                <button type="button" wire:click="closeVoidModal" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>
