<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Services\PointLedgerService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Enrollment $currentEnrollment;
    public ?int $editingTransactionId = null;
    public ?int $manual_point_type_id = null;

    public function mount(Enrollment $enrollment): void
    {
        $this->authorizePermission('points.view');

        $this->currentEnrollment = Enrollment::query()
            ->with(['student', 'group.course'])
            ->findOrFail($enrollment->id);

        $this->authorizeTeacherEnrollmentAccess($this->currentEnrollment);
    }

    public function with(): array
    {
        $transactions = PointTransaction::query()
            ->with(['enteredBy', 'pointType', 'policy', 'voidedBy'])
            ->where('enrollment_id', $this->currentEnrollment->id)
            ->latest('entered_at')
            ->latest('id')
            ->get();

        return [
            'enrollmentRecord' => $this->currentEnrollment->fresh(['student', 'group.course']),
            'manualPointTypes' => PointType::query()
                ->where('is_active', true)
                ->where('allow_manual_entry', true)
                ->where('default_points', '!=', 0)
                ->where(fn ($query) => $query->where('allow_negative', true)->orWhere('default_points', '>', 0))
                ->orderBy('name')
                ->get(),
            'transactions' => $transactions,
            'activeTotal' => PointTransaction::query()
                ->where('enrollment_id', $this->currentEnrollment->id)
                ->whereNull('voided_at')
                ->sum('points'),
            'manualTransactionCount' => $transactions->where('source_type', 'manual')->count(),
            'transactionCount' => $transactions->count(),
        ];
    }

    public function saveManual(): void
    {
        $this->authorizePermission('points.create-manual');

        $validated = $this->validate([
            'manual_point_type_id' => ['required', 'exists:point_types,id'],
        ]);

        $pointType = PointType::query()->findOrFail($validated['manual_point_type_id']);
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
            $transaction = PointTransaction::query()
                ->where('enrollment_id', $this->currentEnrollment->id)
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
        } else {
            PointTransaction::query()->create([
                'student_id' => $this->currentEnrollment->student_id,
                'enrollment_id' => $this->currentEnrollment->id,
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

        app(PointLedgerService::class)->syncEnrollmentCaches($this->currentEnrollment->fresh(['student']));

        session()->flash(
            'status',
            $this->editingTransactionId
                ? __('workflow.points.messages.updated')
                : __('workflow.points.messages.created'),
        );

        $this->resetManualForm();
    }

    public function editManual(int $transactionId): void
    {
        $this->authorizePermission('points.create-manual');

        $transaction = PointTransaction::query()
            ->where('enrollment_id', $this->currentEnrollment->id)
            ->findOrFail($transactionId);

        if ($transaction->source_type !== 'manual' || $transaction->voided_at) {
            $this->addError('manual_point_type_id', __('workflow.points.errors.edit_manual_only'));

            return;
        }

        $this->editingTransactionId = $transaction->id;
        $this->manual_point_type_id = $transaction->point_type_id;
        $this->resetValidation();
    }

    public function resetManualForm(): void
    {
        $this->editingTransactionId = null;
        $this->manual_point_type_id = null;
        $this->resetValidation();
    }

    public function void(int $transactionId): void
    {
        $this->authorizePermission('points.void');

        $transaction = PointTransaction::query()
            ->where('enrollment_id', $this->currentEnrollment->id)
            ->findOrFail($transactionId);

        if ($transaction->voided_at) {
            return;
        }

        $transaction->update([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => __('workflow.points.messages.void_reason'),
        ]);

        app(PointLedgerService::class)->syncEnrollmentCaches($this->currentEnrollment->fresh(['student']));

        if ($this->editingTransactionId === $transaction->id) {
            $this->resetManualForm();
        }

        session()->flash('status', __('workflow.points.messages.voided'));
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('workflow.common.back_to_enrollments') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.points.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.points.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $enrollmentRecord->student?->first_name }} {{ $enrollmentRecord->student?->last_name }}</span>
            <span class="badge-soft badge-soft--emerald">{{ $enrollmentRecord->group?->name ?: __('workflow.common.no_group') }}</span>
            <span class="badge-soft">{{ $enrollmentRecord->group?->course?->name ?: __('workflow.common.no_course') }}</span>
        </div>
    </section>

    <div>
        <a href="{{ route('enrollments.index') }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.common.back_to_enrollments') }}</a>
    </div>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.common.labels.active_total') }}</div>
            <div class="metric-value mt-6">{{ number_format($activeTotal) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.points.stats.transactions') }}</div>
            <div class="metric-value mt-6">{{ number_format($transactionCount) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.points.stats.manual_entries') }}</div>
            <div class="metric-value mt-6">{{ number_format($manualTransactionCount) }}</div>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_24rem]">
        <section class="surface-panel p-5 lg:p-6">
            @if (auth()->user()->can('points.create-manual'))
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="admin-toolbar__title">
                            {{ $editingTransactionId ? __('workflow.points.form.edit_title') : __('workflow.points.form.title') }}
                        </div>
                        <p class="admin-toolbar__subtitle">{{ __('workflow.points.form.help') }}</p>
                    </div>

                    @if ($editingTransactionId)
                        <button type="button" wire:click="resetManualForm" class="pill-link">{{ __('workflow.common.actions.reset') }}</button>
                    @endif
                </div>

                <form wire:submit="saveManual" class="mt-6 space-y-5">
                    <div>
                        <label for="manual-point-type" class="mb-1 block text-sm font-medium">{{ __('workflow.points.form.point_type') }}</label>
                        <select id="manual-point-type" wire:model="manual_point_type_id" class="w-full rounded-xl px-4 py-3 text-sm">
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
                        @if ($editingTransactionId)
                            <button type="button" wire:click="resetManualForm" class="pill-link">{{ __('workflow.common.actions.cancel_edit') }}</button>
                        @endif
                    </div>
                </form>
            @else
                <div class="soft-callout px-4 py-4 text-sm leading-6">
                    <div class="font-semibold text-white">{{ __('workflow.points.read_only.title') }}</div>
                    <div class="mt-2 text-neutral-300">{{ __('workflow.points.read_only.description') }}</div>
                </div>
            @endif
        </section>

        <aside class="space-y-6">
            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('workflow.points.context.title') }}</div>
                <div class="mt-4 space-y-3 text-sm text-neutral-300">
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.points.context.student') }}</div>
                        <div class="mt-1 text-white">{{ $enrollmentRecord->student?->first_name }} {{ $enrollmentRecord->student?->last_name }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.points.context.group') }}</div>
                        <div class="mt-1 text-white">{{ $enrollmentRecord->group?->name ?: __('workflow.common.no_group') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.points.context.course') }}</div>
                        <div class="mt-1 text-white">{{ $enrollmentRecord->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.points.context.cached_points') }}</div>
                        <div class="mt-1 text-white">{{ number_format($enrollmentRecord->final_points_cached) }}</div>
                    </div>
                </div>
            </section>

            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('workflow.points.context.guidance_title') }}</div>
                <div class="mt-4 space-y-3 text-sm leading-6 text-neutral-300">
                    <p>{{ __('workflow.points.context.guidance_intro') }}</p>
                    <ul class="space-y-2">
                        <li>{{ __('workflow.points.context.guidance_manual') }}</li>
                        <li>{{ __('workflow.points.context.guidance_edit') }}</li>
                        <li>{{ __('workflow.points.context.guidance_void') }}</li>
                    </ul>
                </div>
            </section>
        </aside>
    </div>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.points.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($transactionCount)]) }}</div>
            </div>
        </div>

        @if ($transactions->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.points.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.table.headers.entered_at') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.table.headers.type') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.table.headers.source') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.table.headers.points') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.table.headers.notes') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.points.table.headers.state') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.points.table.headers.actions') }}</th>
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
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $transaction->entered_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $transaction->pointType?->name ?: __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $sourceLabel }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $transaction->points >= 0 ? 'status-chip status-chip--emerald' : 'status-chip status-chip--rose' }}">{{ $transaction->points }}</span>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $transaction->notes ?: __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $transaction->voided_at ? 'status-chip status-chip--slate' : 'status-chip status-chip--emerald' }}">
                                        {{ $transaction->voided_at ? __('workflow.common.ledger_state.voided') : __('workflow.common.ledger_state.active') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @if (auth()->user()->can('points.create-manual') && $transaction->source_type === 'manual' && ! $transaction->voided_at)
                                            <button type="button" wire:click="editManual({{ $transaction->id }})" class="pill-link pill-link--compact">{{ __('workflow.common.actions.edit') }}</button>
                                        @endif
                                        @if (auth()->user()->can('points.void') && ! $transaction->voided_at)
                                            <button type="button" wire:click="void({{ $transaction->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('workflow.common.actions.void') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
