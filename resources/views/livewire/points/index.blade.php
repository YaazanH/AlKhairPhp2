<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
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
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingTransactionId = null;
    public ?int $selectedStudentId = null;
    public ?int $selectedEnrollmentId = null;
    public ?int $manual_point_type_id = null;
    public string $search = '';
    public string $stateFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showVoidModal = false;
    public ?int $voidTransactionId = null;
    public string $void_reason = '';

    public function mount(): void
    {
        $this->authorizePermission('points.view');
        $this->resetManualForm();
    }

    public function with(): array
    {
        $transactionsQuery = $this->scopePointTransactionsQuery(
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
            ->when($this->stateFilter === 'active', fn (Builder $query) => $query->whereNull('voided_at'))
            ->when($this->stateFilter === 'voided', fn (Builder $query) => $query->whereNotNull('voided_at'))
            ->latest('entered_at')
            ->latest('id');

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
            'stats' => [
                'students' => $studentOptions->count(),
                'active_total' => (int) $this->scopePointTransactionsQuery(
                    PointTransaction::query()->whereNull('voided_at')
                )->sum('points'),
                'transactions' => $this->scopePointTransactionsQuery(PointTransaction::query())->count(),
            ],
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

    public function updatedSelectedStudentId(): void
    {
        $enrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->selectedEnrollmentId = count($enrollmentIds) === 1
            ? $enrollmentIds[0]
            : null;

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

        $transaction = $this->scopePointTransactionsQuery(PointTransaction::query())
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
            $transaction = $this->scopePointTransactionsQuery(PointTransaction::query())
                ->findOrFail($this->editingTransactionId);

            if ($transaction->source_type !== 'manual' || $transaction->voided_at) {
                $this->addError('manual_point_type_id', __('workflow.points.errors.edit_manual_only'));

                return;
            }

            $transaction->update([
                'point_type_id' => $pointType->id,
                'points' => $points,
                'notes' => null,
            ]);

            $enrollment = $this->scopeEnrollmentsQuery(Enrollment::query()->with('student'))
                ->findOrFail($transaction->enrollment_id);
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

            if (! $validated['selectedEnrollmentId']) {
                if (count($availableEnrollmentIds) > 1) {
                    $this->addError('selectedEnrollmentId', __('workflow.points.errors.select_group'));

                    return;
                }

                $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
                $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];
            }

            abort_unless(in_array((int) $validated['selectedEnrollmentId'], $availableEnrollmentIds, true), 403);

            $enrollment = $this->scopeEnrollmentsQuery(Enrollment::query()->with('student'))
                ->findOrFail((int) $validated['selectedEnrollmentId']);

            PointTransaction::query()->create([
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'point_type_id' => $pointType->id,
                'policy_id' => null,
                'source_type' => 'manual',
                'source_id' => null,
                'points' => $points,
                'entered_by' => auth()->id(),
                'entered_at' => now(),
                'notes' => null,
            ]);
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

    public function openVoidModal(int $transactionId): void
    {
        $this->authorizePermission('points.void');

        $this->scopePointTransactionsQuery(PointTransaction::query())
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

        $transaction = $this->scopePointTransactionsQuery(PointTransaction::query())
            ->findOrFail($this->voidTransactionId);

        if ($transaction->voided_at) {
            $this->closeVoidModal();

            return;
        }

        $transaction->update([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => $validated['void_reason'],
        ]);

        $enrollment = $this->scopeEnrollmentsQuery(Enrollment::query()->with('student'))
            ->findOrFail($transaction->enrollment_id);

        app(PointLedgerService::class)->syncEnrollmentCaches($enrollment->fresh(['student']));

        if ($this->editingTransactionId === $transaction->id) {
            $this->resetManualForm();
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
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.points.workbench.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.points.workbench.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.points.workbench.stats.students') }}: {{ number_format($stats['students']) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.points.workbench.stats.active_total') }}: {{ number_format($stats['active_total']) }}</span>
            <span class="badge-soft">{{ __('workflow.points.workbench.stats.transactions') }}: {{ number_format($stats['transactions']) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.points.workbench.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.points.workbench.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="points-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="points-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="points-state-filter">{{ __('workflow.points.workbench.filters.state') }}</label>
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
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.points.workbench.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @if ($transactions->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.points.workbench.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.entered_at') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.type') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.source') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.points') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.state') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.workbench.table.headers.void_reason') }}</th>
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
                            @endphp
                            <tr class="{{ $transaction->voided_at ? 'opacity-60' : '' }}">
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($transaction->student)
                                        <div class="student-inline">
                                            <x-student-avatar :student="$transaction->student" size="sm" />
                                            <div class="student-inline__body">
                                                <div class="student-inline__name">{{ $transaction->student->first_name }} {{ $transaction->student->last_name }}</div>
                                                <div class="student-inline__meta">{{ $transaction->student->parentProfile?->father_name ?: __('crud.common.not_available') }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div class="font-medium text-white">{{ $transaction->enrollment?->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $transaction->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $transaction->entered_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $transaction->pointType?->name ?: __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $sourceLabel }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $transaction->points >= 0 ? 'status-chip status-chip--emerald' : 'status-chip status-chip--rose' }}">{{ $transaction->points }}</span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $transaction->voided_at ? 'status-chip status-chip--slate' : 'status-chip status-chip--emerald' }}">
                                        {{ $transaction->voided_at ? __('workflow.common.ledger_state.voided') : __('workflow.common.ledger_state.active') }}
                                    </span>
                                </td>
                                <td class="max-w-xs px-5 py-4 text-neutral-300 lg:px-6">
                                    @if ($transaction->voided_at)
                                        <div class="line-clamp-2">{{ $transaction->void_reason ?: __('crud.common.not_available') }}</div>
                                    @else
                                        <span class="text-neutral-500">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @if (auth()->user()->can('points.create-manual') && $transaction->source_type === 'manual' && ! $transaction->voided_at)
                                            <button type="button" wire:click="editManual({{ $transaction->id }})" class="pill-link pill-link--compact">{{ __('workflow.common.actions.edit') }}</button>
                                        @endif
                                        @if (auth()->user()->can('points.void') && ! $transaction->voided_at)
                                            <button type="button" wire:click="openVoidModal({{ $transaction->id }})" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('workflow.common.actions.void') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
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
        :description="__('workflow.points.workbench.form.help')"
        close-method="closeFormModal"
        max-width="5xl"
    >
        <form wire:submit="saveManual" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="points-workbench-student" class="mb-1 block text-sm font-medium">{{ __('workflow.points.workbench.form.student') }}</label>
                    <select id="points-workbench-student" wire:model.live="selectedStudentId" class="w-full rounded-xl px-4 py-3 text-sm" @disabled($editingTransactionId !== null)>
                        <option value="">{{ __('workflow.points.workbench.form.select_student') }}</option>
                        @foreach ($studentOptions as $student)
                            <option value="{{ $student->id }}">
                                {{ $student->first_name }} {{ $student->last_name }}
                                @if ($student->parentProfile?->father_name)
                                    - {{ $student->parentProfile->father_name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('selectedStudentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="points-workbench-enrollment" class="mb-1 block text-sm font-medium">{{ __('workflow.points.workbench.form.group') }}</label>
                    <select id="points-workbench-enrollment" wire:model="selectedEnrollmentId" class="w-full rounded-xl px-4 py-3 text-sm" @disabled($enrollmentOptions->isEmpty() || $editingTransactionId !== null)>
                        <option value="">{{ __('workflow.points.workbench.form.select_group') }}</option>
                        @foreach ($enrollmentOptions as $enrollment)
                            <option value="{{ $enrollment->id }}">
                                {{ $enrollment->group?->name ?: __('workflow.common.no_group') }}
                                @if ($enrollment->group?->course?->name)
                                    - {{ $enrollment->group->course->name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @if ($selectedStudentId && $enrollmentOptions->count() === 1 && ! $editingTransactionId)
                        <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.points.workbench.form.group_auto') }}</div>
                    @endif
                    @error('selectedEnrollmentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div>
                <label for="points-workbench-type" class="mb-1 block text-sm font-medium">{{ __('workflow.points.form.point_type') }}</label>
                <select id="points-workbench-type" wire:model="manual_point_type_id" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('workflow.points.form.select_point_type') }}</option>
                    @foreach ($manualPointTypes as $pointType)
                        <option value="{{ $pointType->id }}">{{ $pointType->name }} ({{ $pointType->default_points > 0 ? '+'.$pointType->default_points : $pointType->default_points }})</option>
                    @endforeach
                </select>
                @error('manual_point_type_id')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingTransactionId ? __('workflow.common.actions.update_point_entry') : __('workflow.common.actions.save_point_entry') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingTransactionId" click="saveAndNew('saveManual')" />
                <button type="button" wire:click="closeFormModal" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
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
                <button type="submit" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                    {{ __('workflow.common.actions.void') }}
                </button>
                <button type="button" wire:click="closeVoidModal" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>
