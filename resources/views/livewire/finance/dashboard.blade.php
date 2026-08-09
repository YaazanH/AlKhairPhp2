<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Models\FinanceCurrency;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Teacher;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use FormatsFinanceNumbers;
    use WithPagination;

    public int $year;
    public string $quarter;
    public bool $showTransferModal = false;
    public bool $showTransactionsModal = false;
    public bool $showCreateRequestModal = false;
    public bool $showRequestHistoryModal = false;
    public bool $showQuarterDetailsModal = false;
    public ?int $reviewingRequestId = null;

    public ?int $transfer_from_cash_box_id = null;
    public ?int $transfer_to_cash_box_id = null;
    public ?int $transfer_currency_id = null;
    public string $transfer_amount = '';
    public string $transfer_date = '';
    public string $transfer_notes = '';

    public string $filter_start_date = '';
    public string $filter_end_date = '';
    public ?int $filter_cash_box_id = null;
    public ?int $filter_currency_id = null;
    public string $filter_type = 'all';
    public string $filter_visibility = 'active';

    public ?int $request_teacher_id = null;
    public ?int $request_kind_id = null;
    public ?int $request_currency_id = null;
    public ?int $request_cash_box_id = null;
    public string $request_amount = '';
    public string $request_count = '';
    public string $request_reason = '';
    public string $review_amount = '';
    public ?int $review_cash_box_id = null;
    public string $review_notes = '';

    public function mount(): void
    {
        $this->authorizePermission('finance.reports.view');
        $this->year = (int) now()->year;
        $this->quarter = (string) now()->quarter;
        $this->transfer_date = now()->toDateString();
        $localCurrency = app(FinanceService::class)->localCurrency();
        $defaultFund = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $localCurrency->id);
        $this->transfer_currency_id = $localCurrency->id;
        $this->transfer_from_cash_box_id = $defaultFund?->id;
        $this->filter_cash_box_id = $defaultFund?->id;
        $this->request_currency_id = $localCurrency->id;
        $this->request_cash_box_id = $defaultFund?->id;
    }

    public function with(): array
    {
        $service = app(FinanceService::class);
        $cashBoxes = $service->accessibleCashBoxes(auth()->user())->get();
        $cashBoxIds = $cashBoxes->pluck('id');
        $reviewCurrencyId = $this->reviewingRequestId ? FinanceRequest::query()->whereKey($this->reviewingRequestId)->value('requested_currency_id') : null;
        $transactions = FinanceTransaction::query()
            ->when($this->filter_visibility !== 'active', fn (Builder $query) => $query->withTrashed())
            ->when($this->filter_visibility === 'deleted', fn (Builder $query) => $query->onlyTrashed())
            ->with(['cashBox', 'category', 'currency', 'enteredBy', 'financeRequest.pullRequestKind'])
            ->whereIn('cash_box_id', $cashBoxIds)
            ->when($this->filter_start_date !== '', fn (Builder $query) => $query->whereDate('transaction_date', '>=', $this->filter_start_date))
            ->when($this->filter_end_date !== '', fn (Builder $query) => $query->whereDate('transaction_date', '<=', $this->filter_end_date))
            ->when($this->filter_cash_box_id, fn (Builder $query) => $query->where('cash_box_id', $this->filter_cash_box_id))
            ->when($this->filter_currency_id, fn (Builder $query) => $query->where('currency_id', $this->filter_currency_id))
            ->when($this->filter_type !== 'all', fn (Builder $query) => $query->where('type', $this->filter_type))
            ->latest('transaction_date')
            ->latest('id');

        return [
            'report' => app(FinanceReportService::class)->report($this->year, (int) $this->quarter),
            'cashBoxes' => $cashBoxes,
            'currencies' => FinanceCurrency::query()->where('is_active', true)->orderByDesc('is_local')->orderBy('code')->get(),
            'transferCurrencies' => $service->currenciesForCashBox($this->transfer_from_cash_box_id)->get(),
            'transferToCashBoxes' => $service->accessibleCashBoxesForCurrency(auth()->user(), $this->transfer_currency_id)
                ->when($this->transfer_from_cash_box_id, fn (Builder $query) => $query->where('finance_cash_boxes.id', '!=', $this->transfer_from_cash_box_id))
                ->get(),
            'pendingRequests' => FinanceRequest::query()->with(['category', 'pullRequestKind', 'requestedBy', 'requestedCurrency', 'teacher'])->where('type', FinanceRequest::TYPE_PULL)->where('status', FinanceRequest::STATUS_PENDING)->latest()->limit(10)->get(),
            'requestHistory' => $this->showRequestHistoryModal ? FinanceRequest::query()->with(['category', 'pullRequestKind', 'requestedBy', 'requestedCurrency', 'acceptedCurrency', 'teacher'])->where('type', FinanceRequest::TYPE_PULL)->latest()->limit(100)->get() : collect(),
            'reviewRequest' => $this->reviewingRequestId ? FinanceRequest::query()->with(['category', 'pullRequestKind', 'requestedBy', 'requestedCurrency', 'teacher'])->where('type', FinanceRequest::TYPE_PULL)->find($this->reviewingRequestId) : null,
            'reviewCashBoxes' => $service->accessibleCashBoxesForCurrency(auth()->user(), $reviewCurrencyId)->get(),
            'teachers' => Teacher::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(),
            'requestKinds' => FinancePullRequestKind::query()->where('is_active', true)->orderBy('mode')->orderBy('name')->get(),
            'selectedRequestKind' => FinancePullRequestKind::query()->find($this->request_kind_id),
            'requestCashBoxes' => $service->accessibleCashBoxesForCurrency(auth()->user(), $this->request_currency_id)->get(),
            'transactionTypes' => collect(['income', 'expense', 'return', 'exchange', 'transfer']),
            'transactions' => $transactions->paginate(25, pageName: 'transactionsPage'),
        ];
    }

    public function openTransferModal(): void
    {
        $this->authorizePermission('finance.cash-box.transfer');
        $this->showTransferModal = true;
    }

    public function updatedTransferFromCashBoxId(): void
    {
        $this->transfer_to_cash_box_id = null;
        $currencies = app(FinanceService::class)->currenciesForCashBox($this->transfer_from_cash_box_id)->get();

        if (! $currencies->contains('id', $this->transfer_currency_id)) {
            $this->transfer_currency_id = $currencies->first()?->id;
        }
    }

    public function updatedTransferCurrencyId(): void
    {
        $this->transfer_to_cash_box_id = null;
    }

    public function updatedRequestCurrencyId(): void
    {
        $this->request_cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $this->request_currency_id)?->id;
    }

    public function transfer(): void
    {
        $this->authorizePermission('finance.cash-box.transfer');
        $this->normalizeFinanceNumberProperty('transfer_amount');
        $validated = $this->validate([
            'transfer_amount' => ['required', 'numeric', 'gt:0'],
            'transfer_currency_id' => ['required', 'exists:finance_currencies,id'],
            'transfer_date' => ['required', 'date'],
            'transfer_from_cash_box_id' => ['required', 'different:transfer_to_cash_box_id', 'exists:finance_cash_boxes,id'],
            'transfer_to_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'transfer_notes' => ['nullable', 'string', 'max:500'],
        ]);

        app(FinanceService::class)->recordCashBoxTransfer(
            app(FinanceService::class)->cashBoxForUser((int) $validated['transfer_from_cash_box_id'], auth()->user()),
            app(FinanceService::class)->cashBoxForUser((int) $validated['transfer_to_cash_box_id'], auth()->user()),
            FinanceCurrency::query()->findOrFail((int) $validated['transfer_currency_id']),
            (float) $validated['transfer_amount'],
            $validated['transfer_date'],
            auth()->user(),
            $validated['transfer_notes'] ?: null,
        );

        $this->reset(['transfer_to_cash_box_id', 'transfer_amount', 'transfer_notes', 'showTransferModal']);
        session()->flash('status', __('finance.messages.transfer_posted'));
    }

    public function createRequest(): void
    {
        $this->authorizePermission('finance.pull-requests.review');
        $this->normalizeFinanceNumberProperty('request_amount');
        $kind = FinancePullRequestKind::query()->find($this->request_kind_id);
        $validated = $this->validate([
            'request_amount' => ['required', 'numeric', 'gt:0'],
            'request_kind_id' => ['required', Rule::exists('finance_categories', 'id')->where('type', 'expense')->where('is_active', true)],
            'request_currency_id' => ['required', 'exists:finance_currencies,id'],
            'request_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'request_count' => [$kind?->mode === FinancePullRequestKind::MODE_COUNT ? 'required' : 'nullable', 'integer', 'min:1'],
            'request_reason' => ['nullable', 'string', 'max:2000'],
            'request_teacher_id' => ['required', 'exists:teachers,id'],
        ]);
        $teacher = Teacher::query()->with('user')->findOrFail((int) $validated['request_teacher_id']);
        $currency = FinanceCurrency::query()->findOrFail((int) $validated['request_currency_id']);
        $kind = FinancePullRequestKind::query()->findOrFail((int) $validated['request_kind_id']);

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $validated['request_kind_id'],
            'finance_category_id' => $validated['request_kind_id'],
            'requested_currency_id' => $currency->id,
            'requested_amount' => $validated['request_amount'],
            'requested_count' => $kind->mode === FinancePullRequestKind::MODE_COUNT ? (int) ($validated['request_count'] ?: 0) : null,
            'teacher_id' => $teacher->id,
            'requested_by' => $teacher->user?->id ?: auth()->id(),
            'requested_reason' => $validated['request_reason'] ?: null,
        ]);

        try {
            app(FinanceService::class)->acceptRequest(
                $request,
                (float) $validated['request_amount'],
                app(FinanceService::class)->cashBoxForUser((int) $validated['request_cash_box_id'], auth()->user()),
                auth()->user(),
                null,
                $kind->mode === FinancePullRequestKind::MODE_COUNT ? (int) ($validated['request_count'] ?: 0) : null,
            );
        } catch (ValidationException $exception) {
            $request->delete();
            $this->addError('request_amount', collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
            return;
        }

        $this->reset(['request_amount', 'request_count', 'request_reason', 'request_teacher_id', 'request_kind_id', 'showCreateRequestModal']);
        session()->flash('status', __('finance.messages.pull_accepted'));
    }

    public function openReviewModal(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');
        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->where('status', FinanceRequest::STATUS_PENDING)->findOrFail($requestId);
        $this->reviewingRequestId = $request->id;
        $this->review_amount = $this->formatFinanceNumberForInput($request->requested_amount);
        $this->review_cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $request->requested_currency_id)?->id;
        $this->review_notes = '';
    }

    public function acceptRequest(): void
    {
        $this->authorizePermission('finance.pull-requests.review');
        $this->normalizeFinanceNumberProperty('review_amount');
        $validated = $this->validate([
            'review_amount' => ['required', 'numeric', 'gt:0'],
            'review_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->where('status', FinanceRequest::STATUS_PENDING)->findOrFail($this->reviewingRequestId);

        try {
            app(FinanceService::class)->acceptRequest($request, (float) $validated['review_amount'], app(FinanceService::class)->cashBoxForUser((int) $validated['review_cash_box_id'], auth()->user()), auth()->user(), $validated['review_notes'] ?: null, $request->requested_count);
        } catch (ValidationException $exception) {
            $this->addError('review_amount', collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
            return;
        }

        $this->closeReviewModal();
        session()->flash('status', __('finance.messages.pull_accepted'));
    }

    public function declineRequest(): void
    {
        $this->authorizePermission('finance.pull-requests.review');
        $this->validate(['review_notes' => ['required', 'string', 'max:2000']]);
        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->where('status', FinanceRequest::STATUS_PENDING)->findOrFail($this->reviewingRequestId);
        app(FinanceService::class)->declineRequest($request, auth()->user(), $this->review_notes);
        $this->closeReviewModal();
        session()->flash('status', __('finance.messages.pull_declined'));
    }

    public function closeReviewModal(): void
    {
        $this->reset(['reviewingRequestId', 'review_amount', 'review_cash_box_id', 'review_notes']);
        $this->resetValidation();
    }

    public function resetTransactionFilters(): void
    {
        $this->reset(['filter_start_date', 'filter_end_date', 'filter_currency_id']);
        $this->filter_type = 'all';
        $this->filter_visibility = 'active';
        $this->filter_cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user())?->id;
        $this->resetPage('transactionsPage');
    }

    public function runningBalance(FinanceTransaction $transaction): float
    {
        return (float) FinanceTransaction::query()
            ->where('cash_box_id', $transaction->cash_box_id)
            ->where('currency_id', $transaction->currency_id)
            ->where(function (Builder $query) use ($transaction): void {
                $query->whereDate('transaction_date', '<', $transaction->transaction_date)
                    ->orWhere(function (Builder $sameDate) use ($transaction): void {
                        $sameDate->whereDate('transaction_date', $transaction->transaction_date)->where('id', '<=', $transaction->id);
                    });
            })
            ->sum('signed_amount');
    }
}; ?>

@php
    $pieTotal = max(0.0, (float) $report['category_totals']->sum('expense'));
    $pieColors = ['#34d399', '#60a5fa', '#fbbf24', '#f87171', '#a78bfa', '#22d3ee', '#fb7185', '#94a3b8'];
    $pieOffset = 0.0;
    $quarterExpenseMax = max(1, collect($report['quarter_totals'])->concat($report['previous_year_quarter_totals'])->max('expense'));
    $quarterX = fn (int $index) => 65 + ($index * 115);
    $quarterY = fn (float $value) => 200 - (($value / $quarterExpenseMax) * 150);
    $currentQuarterLine = collect($report['quarter_totals'])->values()->map(fn (array $row, int $index) => $quarterX($index).','.$quarterY((float) $row['expense']))->implode(' ');
    $previousQuarterLine = collect($report['previous_year_quarter_totals'])->values()->map(fn (array $row, int $index) => $quarterX($index).','.$quarterY((float) $row['expense']))->implode(' ');
@endphp

<div class="page-stack">
    <section class="page-hero relative z-20 overflow-visible p-6 lg:p-8" style="overflow: visible">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
            <div><div class="eyebrow">{{ __('ui.nav.finance') }}</div><h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('ui.nav.finance_dashboard') }}</h1><p class="mt-4 max-w-3xl text-neutral-200">{{ __('finance.dashboard.subtitle') }}</p></div>
            <div class="relative z-30 grid gap-3 sm:grid-cols-2">
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.year') }}</label><input wire:model.live="year" type="number" min="2000" max="2100" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                <div class="relative z-40"><label class="mb-1 block text-sm">{{ __('finance.fields.quarter') }}</label><select wire:model.live="quarter" class="relative z-50 w-full rounded-xl px-4 py-3 text-sm"><option value="1">Q1</option><option value="2">Q2</option><option value="3">Q3</option><option value="4">Q4</option></select></div>
            </div>
        </div>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="mb-5 flex items-center justify-between gap-3"><div><div class="eyebrow">{{ __('finance.dashboard.funds') }}</div><h2 class="font-display mt-2 text-2xl text-white">{{ __('finance.dashboard.fund_balances') }}</h2></div>@can('finance.cash-box.transfer')<button wire:click="openTransferModal" class="pill-link pill-link--accent" title="{{ __('finance.dashboard.move_money') }}" aria-label="{{ __('finance.dashboard.move_money') }}"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h11.25m0 0L15 3.75m3.75 3.75L15 11.25M16.5 16.5H5.25m0 0L9 20.25M5.25 16.5L9 12.75"/></svg></button>@endcan</div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">@foreach ($report['balances'] as $fund)<article class="stat-card"><div class="kpi-label">{{ $fund['cash_box']->name }}</div><div class="metric-value mt-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($fund['local_total'], $report['summary']['local_currency']) }}</bdi></div><div class="mt-3 space-y-1 text-sm text-neutral-300">@foreach ($fund['currencies'] as $row)<div class="flex justify-between gap-3"><span>{{ $row['currency']->code }}</span><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($row['balance'], $row['currency']) }}</bdi></div>@endforeach</div></article>@endforeach</div>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <div class="surface-panel p-5 lg:p-6"><div class="eyebrow">{{ __('finance.dashboard.expense_categories') }}</div><h2 class="font-display mt-2 text-2xl text-white">{{ __('finance.dashboard.expense_chart') }}</h2>
            @if ($pieTotal > 0)<div class="mt-6 grid items-center gap-6 sm:grid-cols-[14rem_1fr]"><svg viewBox="0 0 42 42" class="mx-auto h-56 w-56 -rotate-90 overflow-visible" role="img">@foreach ($report['category_totals'] as $index => $row)@php($portion = ((float) $row['expense'] / $pieTotal) * 100)<circle cx="21" cy="21" r="15.9155" fill="transparent" stroke="{{ $pieColors[$index % count($pieColors)] }}" stroke-width="8" stroke-dasharray="{{ $portion }} {{ 100 - $portion }}" stroke-dashoffset="{{ -$pieOffset }}" class="origin-center transition-all duration-200 hover:scale-105 hover:stroke-[10]"><title>{{ $row['category'] }}: {{ number_format($portion, 1) }}% · {{ app(FinanceService::class)->formatCurrencyAmount($row['expense'], $report['summary']['local_currency']) }}</title></circle>@php($pieOffset += $portion)@endforeach</svg><div class="space-y-3">@foreach ($report['category_totals'] as $index => $row)<div class="flex items-center justify-between gap-3 text-sm"><span class="flex items-center gap-2"><i class="h-3 w-3 rounded-full" style="background: {{ $pieColors[$index % count($pieColors)] }}"></i>{{ $row['category'] }}</span><bdi dir="ltr" class="font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($row['expense'], $report['summary']['local_currency']) }}</bdi></div>@endforeach</div></div>@else<div class="admin-empty-state mt-5">{{ __('finance.dashboard.no_expenses') }}</div>@endif
        </div>

        <div class="surface-table"><div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.dashboard.pending_withdrawals') }}</div><div class="admin-grid-meta__summary">{{ __('finance.dashboard.pending_withdrawals_help') }}</div></div><div class="flex gap-2"><button wire:click="$set('showRequestHistoryModal', true)" class="pill-link pill-link--compact">{{ __('finance.dashboard.previous_requests') }}</button><button wire:click="$set('showCreateRequestModal', true)" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.actions.add') }}</button></div></div><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('finance.common.request') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.requester') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.amount') }}</th><th></th></tr></thead><tbody>@forelse ($pendingRequests as $request)<tr><td class="px-4 py-3">{{ $request->request_no }}</td><td class="px-4 py-3">{{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : ($request->requestedBy?->name ?: '-') }}</td><td class="px-4 py-3">{{ $request->pullRequestKind?->name ?: '-' }}</td><td class="px-4 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($request->requested_amount, $request->requestedCurrency) }}</bdi></td><td class="px-4 py-3 text-right"><button wire:click="openReviewModal({{ $request->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.review') }}</button></td></tr>@empty<tr><td colspan="5" class="px-5 py-10 text-center text-neutral-500">{{ __('finance.empty.no_pending_pull_requests') }}</td></tr>@endforelse</tbody></table></div></div>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <div class="surface-table"><div class="admin-grid-meta"><div class="admin-grid-meta__title">{{ __('finance.dashboard.quarter_totals') }}</div><button wire:click="$set('showQuarterDetailsModal', true)" class="pill-link pill-link--compact">{{ __('finance.actions.details') }}</button></div><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.quarter') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.income') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.expense') }}</th></tr></thead><tbody>@foreach ($report['quarter_totals'] as $row)<tr><td class="px-5 py-3">Q{{ $row['quarter'] }}</td><td class="px-5 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($row['income'], $report['summary']['local_currency']) }}</bdi></td><td class="px-5 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($row['expense'], $report['summary']['local_currency']) }}</bdi></td></tr>@endforeach</tbody></table></div></div>
        <x-admin.modal :show="$showQuarterDetailsModal" :title="__('finance.dashboard.quarter_expense_comparison')" close-method="$set('showQuarterDetailsModal', false)" max-width="4xl"><div class="mb-4 flex flex-wrap gap-5 text-sm"><span class="flex items-center gap-2"><i class="h-2.5 w-6 rounded-full bg-emerald-400"></i>{{ $year }}</span><span class="flex items-center gap-2"><i class="h-2.5 w-6 rounded-full bg-sky-400"></i>{{ $year - 1 }}</span></div><svg viewBox="0 0 500 250" class="h-80 w-full overflow-visible" role="img" aria-label="{{ __('finance.dashboard.quarter_expense_comparison') }}"><line x1="65" y1="45" x2="65" y2="200" stroke="rgba(255,255,255,.35)" stroke-width="1.5"/><line x1="65" y1="200" x2="430" y2="200" stroke="rgba(255,255,255,.35)" stroke-width="1.5"/>@foreach ([0, .25, .5, .75, 1] as $ratio) @php($lineY = 200 - ($ratio * 150)) <line x1="65" y1="{{ $lineY }}" x2="430" y2="{{ $lineY }}" stroke="rgba(255,255,255,.08)"/><text x="55" y="{{ $lineY + 4 }}" text-anchor="end" fill="#a3a3a3" font-size="10">{{ number_format($quarterExpenseMax * $ratio) }}</text> @endforeach<polyline points="{{ $currentQuarterLine }}" fill="none" stroke="#34d399" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><polyline points="{{ $previousQuarterLine }}" fill="none" stroke="#38bdf8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>@foreach ($report['quarter_totals'] as $index => $row)<circle cx="{{ $quarterX($index) }}" cy="{{ $quarterY((float) $row['expense']) }}" r="5" fill="#34d399" class="origin-center transition-transform hover:scale-150"><title>{{ $year }} Q{{ $row['quarter'] }}: {{ app(FinanceService::class)->formatCurrencyAmount($row['expense'], $report['summary']['local_currency']) }}</title></circle>@endforeach @foreach ($report['previous_year_quarter_totals'] as $index => $row)<circle cx="{{ $quarterX($index) }}" cy="{{ $quarterY((float) $row['expense']) }}" r="5" fill="#38bdf8" class="origin-center transition-transform hover:scale-150"><title>{{ $year - 1 }} Q{{ $row['quarter'] }}: {{ app(FinanceService::class)->formatCurrencyAmount($row['expense'], $report['summary']['local_currency']) }}</title></circle>@endforeach @foreach ([1, 2, 3, 4] as $index => $quarter)<text x="{{ $quarterX($index) }}" y="224" text-anchor="middle" fill="#a3a3a3" font-size="12">Q{{ $quarter }}</text>@endforeach</svg></x-admin.modal>
        <div class="surface-table"><div class="admin-grid-meta"><div class="admin-grid-meta__title">{{ __('finance.dashboard.latest_activity') }}</div><button wire:click="$set('showTransactionsModal', true)" class="pill-link pill-link--compact">{{ __('finance.actions.view_all') }}</button></div><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.date') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.amount') }}</th></tr></thead><tbody>@forelse ($report['latest_transactions'] as $transaction)<tr><td class="px-5 py-3">{{ $transaction->transaction_date?->format('d-m-Y') }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->transactionCategoryLabel($transaction) }}</td><td class="px-5 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($transaction->signed_amount, $transaction->currency) }}</bdi></td></tr>@empty<tr><td colspan="3" class="px-5 py-10 text-center text-neutral-500">{{ __('finance.empty.no_transactions') }}</td></tr>@endforelse</tbody></table></div></div>
    </section>

    <x-admin.modal :show="$showTransferModal" :title="__('finance.dashboard.move_money')" close-method="$set('showTransferModal', false)" max-width="3xl"><form wire:submit="transfer" class="grid gap-4 md:grid-cols-2"><div><label class="mb-1 block text-sm">{{ __('finance.fields.from') }}</label><select wire:model.live="transfer_from_cash_box_id" class="w-full rounded-xl px-4 py-3">@foreach ($cashBoxes as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach</select></div><div><label class="mb-1 block text-sm">{{ __('finance.fields.to') }}</label><select wire:model="transfer_to_cash_box_id" class="w-full rounded-xl px-4 py-3"><option value="">-</option>@foreach ($transferToCashBoxes as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach</select></div><div><label class="mb-1 block text-sm">{{ __('finance.common.currency') }}</label><select wire:model.live="transfer_currency_id" class="w-full rounded-xl px-4 py-3">@foreach ($transferCurrencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div><div><label class="mb-1 block text-sm">{{ __('finance.fields.amount') }}</label><input wire:model="transfer_amount" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('transfer_amount')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div><div><label class="mb-1 block text-sm">{{ __('finance.common.date') }}</label><input wire:model="transfer_date" type="date" class="w-full rounded-xl px-4 py-3"></div><div><label class="mb-1 block text-sm">{{ __('finance.common.notes') }}</label><input wire:model="transfer_notes" class="w-full rounded-xl px-4 py-3"></div><div class="md:col-span-2 flex justify-end"><button class="pill-link pill-link--accent">{{ __('finance.actions.transfer') }}</button></div></form></x-admin.modal>

    <x-admin.modal :show="$showCreateRequestModal" :title="__('finance.pull_requests.new')" close-method="$set('showCreateRequestModal', false)" max-width="4xl"><form wire:submit="createRequest" class="grid gap-4 md:grid-cols-2"><div><label class="mb-1 block text-sm">{{ __('finance.fields.requester') }}</label><select wire:model="request_teacher_id" class="w-full rounded-xl px-4 py-3"><option value="">-</option>@foreach ($teachers as $teacher)<option value="{{ $teacher->id }}">{{ trim($teacher->first_name.' '.$teacher->last_name) }}</option>@endforeach</select></div><div><label class="mb-1 block text-sm">{{ __('finance.fields.category') }}</label><select wire:model.live="request_kind_id" class="w-full rounded-xl px-4 py-3"><option value="">-</option>@foreach ($requestKinds as $kind)<option value="{{ $kind->id }}">{{ $kind->name }} - {{ __('finance.pull_modes.'.$kind->mode) }}</option>@endforeach</select></div><div><label class="mb-1 block text-sm">{{ __('finance.common.currency') }}</label><select wire:model.live="request_currency_id" class="w-full rounded-xl px-4 py-3">@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} - {{ $currency->name }}</option>@endforeach</select></div><div><label class="mb-1 block text-sm">{{ __('finance.fields.cash_box') }}</label><select wire:model="request_cash_box_id" class="w-full rounded-xl px-4 py-3"><option value="">-</option>@foreach ($requestCashBoxes as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach</select></div><div><label class="mb-1 block text-sm">{{ __('finance.fields.amount') }}</label><input wire:model="request_amount" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('request_amount')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div>@if ($selectedRequestKind?->mode === 'count')<div><label class="mb-1 block text-sm">{{ __('finance.fields.people_count') }}</label><input wire:model="request_count" type="number" min="1" class="w-full rounded-xl px-4 py-3"></div>@endif<div class="md:col-span-2"><label class="mb-1 block text-sm">{{ __('finance.common.description') }}</label><textarea wire:model="request_reason" class="w-full rounded-xl px-4 py-3"></textarea></div><div class="md:col-span-2 flex justify-end"><button class="pill-link pill-link--accent">{{ __('finance.actions.add') }}</button></div></form></x-admin.modal>

    <x-admin.modal :show="$reviewingRequestId !== null" :title="__('finance.actions.review')" close-method="closeReviewModal" max-width="3xl">@if ($reviewRequest)<div class="mb-4 soft-callout p-4"><div class="font-semibold text-white">{{ $reviewRequest->request_no }} · {{ $reviewRequest->teacher ? trim($reviewRequest->teacher->first_name.' '.$reviewRequest->teacher->last_name) : $reviewRequest->requestedBy?->name }}</div><div class="mt-1 text-sm text-neutral-300">{{ $reviewRequest->requested_reason }}</div></div><div class="grid gap-4 md:grid-cols-2"><div><label class="mb-1 block text-sm">{{ __('finance.fields.accepted') }}</label><input wire:model="review_amount" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('review_amount')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div><div><label class="mb-1 block text-sm">{{ __('finance.fields.cash_box') }}</label><select wire:model="review_cash_box_id" class="w-full rounded-xl px-4 py-3"><option value="">-</option>@foreach ($reviewCashBoxes as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach</select></div><div class="md:col-span-2"><label class="mb-1 block text-sm">{{ __('finance.common.notes') }}</label><textarea wire:model="review_notes" class="w-full rounded-xl px-4 py-3"></textarea>@error('review_notes')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div><div class="md:col-span-2 flex justify-end gap-3"><button wire:click="declineRequest" type="button" class="pill-link pill-link--danger">{{ __('finance.actions.decline') }}</button><button wire:click="acceptRequest" type="button" class="pill-link pill-link--accent">{{ __('finance.actions.accept') }}</button></div></div>@endif</x-admin.modal>

    <x-admin.modal :show="$showRequestHistoryModal" :title="__('finance.dashboard.previous_requests')" close-method="$set('showRequestHistoryModal', false)" max-width="7xl"><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('finance.common.request') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.requester') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-4 py-3 text-left">{{ __('finance.common.description') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.amount') }}</th><th class="px-4 py-3 text-left">{{ __('finance.common.status') }}</th></tr></thead><tbody>@foreach ($requestHistory as $request)<tr><td class="px-4 py-3"><div>{{ $request->request_no }}</div><div class="text-xs text-neutral-500">{{ $request->created_at?->format('d-m-Y H:i') }}</div></td><td class="px-4 py-3">{{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : ($request->requestedBy?->name ?: '-') }}</td><td class="px-4 py-3">{{ $request->pullRequestKind?->name ?: '-' }}</td><td class="px-4 py-3">{{ $request->requested_reason ?: '-' }}</td><td class="px-4 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($request->accepted_amount ?? $request->requested_amount, $request->accepted_amount !== null ? $request->acceptedCurrency : $request->requestedCurrency) }}</bdi></td><td class="px-4 py-3"><span class="status-chip {{ $request->status === 'settled' ? 'status-chip--emerald' : ($request->status === 'declined' ? 'status-chip--rose' : 'status-chip--amber') }}">{{ __('finance.statuses.'.$request->status) }}</span></td></tr>@endforeach</tbody></table></div></x-admin.modal>

    <x-admin.modal :show="$showTransactionsModal" :title="__('finance.dashboard.financial_transactions')" close-method="$set('showTransactionsModal', false)" max-width="8xl"><div class="mb-5 grid gap-3 md:grid-cols-3 xl:grid-cols-6"><input wire:model.live="filter_start_date" type="date" class="rounded-xl px-3 py-2"><input wire:model.live="filter_end_date" type="date" class="rounded-xl px-3 py-2"><select wire:model.live="filter_cash_box_id" class="rounded-xl px-3 py-2"><option value="">{{ __('finance.options.all_cash_boxes') }}</option>@foreach ($cashBoxes as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach</select><select wire:model.live="filter_type" class="rounded-xl px-3 py-2"><option value="all">{{ __('finance.options.all_types') }}</option>@foreach ($transactionTypes as $type)<option value="{{ $type }}">{{ app(FinanceService::class)->transactionTypeLabel($type) }}</option>@endforeach</select><select wire:model.live="filter_currency_id" class="rounded-xl px-3 py-2"><option value="">{{ __('finance.options.all_currencies') }}</option>@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select><select wire:model.live="filter_visibility" class="rounded-xl px-3 py-2"><option value="active">{{ __('finance.filters.active_only') }}</option><option value="deleted">{{ __('finance.filters.deleted_only') }}</option><option value="all">{{ __('finance.filters.all_records') }}</option></select></div><div class="overflow-x-auto"><table class="financial-transactions-table text-sm"><thead><tr><th class="px-3 py-3 text-left">{{ __('finance.common.date') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.transaction_no') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.cash_box') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.transaction_type') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.credit') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.debit') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.fund_balance') }}</th><th class="px-3 py-3 text-left">{{ __('finance.fields.user') }}</th></tr></thead><tbody>@foreach ($transactions as $transaction)<tr class="{{ $transaction->trashed() ? 'opacity-50' : '' }}"><td class="px-3 py-3">{{ $transaction->transaction_date?->format('d-m-Y') }}</td><td class="px-3 py-3"><div>{{ $transaction->special_transaction_no ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $transaction->transaction_no }}</div></td><td class="px-3 py-3">{{ $transaction->cashBox?->name }}</td><td class="px-3 py-3">{{ app(FinanceService::class)->transactionTypeLabel($transaction->type, $transaction) }}</td><td class="px-3 py-3"><div>{{ app(FinanceService::class)->transactionCategoryLabel($transaction) }}</div><div class="text-xs text-neutral-500">{{ $transaction->description }}</div></td><td class="px-3 py-3"><bdi dir="ltr">{{ $transaction->direction === 'in' ? app(FinanceService::class)->formatCurrencyAmount($transaction->amount, $transaction->currency) : '-' }}</bdi></td><td class="px-3 py-3"><bdi dir="ltr">{{ $transaction->direction === 'out' ? app(FinanceService::class)->formatCurrencyAmount($transaction->amount, $transaction->currency) : '-' }}</bdi></td><td class="px-3 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($this->runningBalance($transaction), $transaction->currency) }}</bdi></td><td class="px-3 py-3">{{ $transaction->enteredBy?->name ?: '-' }}</td></tr>@endforeach</tbody></table></div><div class="mt-4">{{ $transactions->links() }}</div></x-admin.modal>
</div>
