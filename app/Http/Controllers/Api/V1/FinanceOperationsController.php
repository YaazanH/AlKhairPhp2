<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\FinanceCashBox;
use App\Models\FinanceCashBoxTransfer;
use App\Models\FinanceCurrency;
use App\Models\FinanceCurrencyExchange;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinanceOperationsController extends Controller
{
    public function summary(Request $request, FinanceService $financeService)
    {
        $this->authorizeAnyPermission($request, ['finance.reports.view', 'finance.cash-box.view']);
        $cashBoxIds = $this->accessibleCashBoxIds($request, $financeService);
        $allowedRequestTypes = $this->allowedRequestTypes($request, 'view');

        return response()->json(['data' => [
            'cash_boxes' => $this->balancePayload($financeService, $request),
            'pending_requests' => $allowedRequestTypes === [] ? [] : $this->scopeFinanceRequests(FinanceRequest::query(), $request, $allowedRequestTypes)
                ->where('status', FinanceRequest::STATUS_PENDING)
                ->selectRaw('type, COUNT(*) as aggregate')
                ->groupBy('type')
                ->pluck('aggregate', 'type')
                ->map(fn ($count) => (int) $count),
            'totals' => FinanceTransaction::query()
                ->whereIn('cash_box_id', $cashBoxIds)
                ->selectRaw('currency_id, SUM(signed_amount) as balance')
                ->with('currency:id,code,name,symbol')
                ->groupBy('currency_id')
                ->get()
                ->map(fn ($row) => [
                    'balance' => round((float) $row->balance, 2),
                    'currency' => $this->currencyPayload($row->currency),
                ])->values(),
        ]]);
    }

    public function cashBoxes(Request $request, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.cash-box.view');

        return response()->json(['data' => $this->balancePayload($financeService, $request)]);
    }

    public function transactions(Request $request, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.cash-box.view');
        $validated = $request->validate([
            'cash_box_id' => ['nullable', 'integer', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['nullable', 'integer', 'exists:finance_currencies,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'direction' => ['nullable', 'in:in,out'],
            'type' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return FinanceTransaction::query()
            ->whereIn('cash_box_id', $this->accessibleCashBoxIds($request, $financeService))
            ->with(['cashBox:id,name,code', 'category:id,name', 'currency:id,code,name,symbol', 'enteredBy:id,name'])
            ->when($validated['cash_box_id'] ?? null, fn (Builder $query, int $id) => $query->where('cash_box_id', $id))
            ->when($validated['currency_id'] ?? null, fn (Builder $query, int $id) => $query->where('currency_id', $id))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transaction_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transaction_date', '<=', $date))
            ->when($validated['direction'] ?? null, fn (Builder $query, string $direction) => $query->where('direction', $direction))
            ->when($validated['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->latest('transaction_date')->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (FinanceTransaction $transaction) => $this->transactionPayload($transaction));
    }

    public function transaction(Request $request, FinanceTransaction $transaction, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.cash-box.view');
        abort_unless(in_array($transaction->cash_box_id, $this->accessibleCashBoxIds($request, $financeService), true), 403);
        $transaction->load(['cashBox:id,name,code', 'category:id,name', 'currency:id,code,name,symbol', 'enteredBy:id,name']);

        return response()->json(['data' => $this->transactionPayload($transaction)]);
    }

    public function storeAdjustment(Request $request, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.cash-box.adjust');
        abort_unless($this->financeSettingEnabled('cash_box_manual_adjustment_enabled'), 404);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_box_id' => ['required', 'integer', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['required', 'integer', 'exists:finance_currencies,id'],
            'description' => ['required', 'string', 'max:500'],
            'direction' => ['required', 'in:in,out'],
            'transaction_date' => ['nullable', 'date'],
        ]);
        $cashBox = $financeService->cashBoxForUser($validated['cash_box_id'], $request->user());
        $this->ensureCashBoxCurrency($financeService, $cashBox, $validated['currency_id']);

        $transaction = $financeService->postTransaction([
            ...$validated,
            'type' => 'manual_adjustment',
            'transaction_date' => $validated['transaction_date'] ?? now()->toDateString(),
            'entered_by' => $request->user()->id,
        ])->load(['cashBox:id,name,code', 'category:id,name', 'currency:id,code,name,symbol', 'enteredBy:id,name']);

        return response()->json(['data' => $this->transactionPayload($transaction)], 201);
    }

    public function transfers(Request $request, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.cash-box.view');
        $validated = $this->validateHistoryFilters($request);
        $ids = $this->accessibleCashBoxIds($request, $financeService);

        return FinanceCashBoxTransfer::query()
            ->whereIn('from_cash_box_id', $ids)->whereIn('to_cash_box_id', $ids)
            ->with(['currency:id,code,name,symbol', 'enteredBy:id,name', 'fromCashBox:id,name,code', 'toCashBox:id,name,code'])
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transfer_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('transfer_date', '<=', $date))
            ->latest('transfer_date')->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (FinanceCashBoxTransfer $transfer) => $this->transferPayload($transfer));
    }

    public function storeTransfer(Request $request, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.cash-box.transfer');
        abort_unless($this->financeSettingEnabled('cash_box_transfer_enabled'), 404);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_id' => ['required', 'integer', 'exists:finance_currencies,id'],
            'from_cash_box_id' => ['required', 'integer', 'different:to_cash_box_id', 'exists:finance_cash_boxes,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'to_cash_box_id' => ['required', 'integer', 'exists:finance_cash_boxes,id'],
            'transfer_date' => ['required', 'date'],
        ]);
        $from = $financeService->cashBoxForUser($validated['from_cash_box_id'], $request->user());
        $to = $financeService->cashBoxForUser($validated['to_cash_box_id'], $request->user());
        $this->ensureCashBoxCurrency($financeService, $from, $validated['currency_id']);
        $this->ensureCashBoxCurrency($financeService, $to, $validated['currency_id']);
        $currency = FinanceCurrency::query()->findOrFail($validated['currency_id']);

        $transfer = $financeService->recordCashBoxTransfer($from, $to, $currency, (float) $validated['amount'], $validated['transfer_date'], $request->user(), $validated['notes'] ?? null)
            ->load(['currency:id,code,name,symbol', 'enteredBy:id,name', 'fromCashBox:id,name,code', 'toCashBox:id,name,code']);

        return response()->json(['data' => $this->transferPayload($transfer)], 201);
    }

    public function exchanges(Request $request, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.exchange.view');
        $validated = $this->validateHistoryFilters($request);
        $ids = $this->accessibleCashBoxIds($request, $financeService);

        return FinanceCurrencyExchange::query()
            ->whereIn('from_cash_box_id', $ids)->whereIn('to_cash_box_id', $ids)
            ->with(['enteredBy:id,name', 'fromCashBox:id,name,code', 'fromCurrency:id,code,name,symbol', 'toCashBox:id,name,code', 'toCurrency:id,code,name,symbol'])
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('exchange_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('exchange_date', '<=', $date))
            ->latest('exchange_date')->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (FinanceCurrencyExchange $exchange) => $this->exchangePayload($exchange));
    }

    public function storeExchange(Request $request, FinanceService $financeService)
    {
        $this->authorizePermission($request, 'finance.exchange.create');
        $validated = $request->validate([
            'exchange_date' => ['required', 'date'],
            'from_amount' => ['required', 'numeric', 'gt:0'],
            'from_cash_box_id' => ['required', 'integer', 'exists:finance_cash_boxes,id'],
            'from_currency_id' => ['required', 'integer', 'different:to_currency_id', 'exists:finance_currencies,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'to_amount' => ['nullable', 'numeric', 'gt:0'],
            'to_cash_box_id' => ['required', 'integer', 'exists:finance_cash_boxes,id'],
            'to_currency_id' => ['required', 'integer', 'exists:finance_currencies,id'],
        ]);
        $fromBox = $financeService->cashBoxForUser($validated['from_cash_box_id'], $request->user());
        $toBox = $financeService->cashBoxForUser($validated['to_cash_box_id'], $request->user());
        $fromCurrency = FinanceCurrency::query()->findOrFail($validated['from_currency_id']);
        $toCurrency = FinanceCurrency::query()->findOrFail($validated['to_currency_id']);
        $this->ensureCashBoxCurrency($financeService, $fromBox, $fromCurrency->id);
        $this->ensureCashBoxCurrency($financeService, $toBox, $toCurrency->id);
        $toAmount = (float) ($validated['to_amount'] ?? $financeService->calculateExchangeToAmount($fromCurrency, $toCurrency, (float) $validated['from_amount']));
        if ($toAmount <= 0) {
            throw ValidationException::withMessages(['to_amount' => __('finance.validation.cash_box_currency_mismatch')]);
        }

        $exchange = $financeService->recordCurrencyExchange($fromBox, $fromCurrency, (float) $validated['from_amount'], $toBox, $toCurrency, $toAmount, $validated['exchange_date'], $request->user(), $validated['notes'] ?? null)
            ->load(['enteredBy:id,name', 'fromCashBox:id,name,code', 'fromCurrency:id,code,name,symbol', 'toCashBox:id,name,code', 'toCurrency:id,code,name,symbol']);

        return response()->json(['data' => $this->exchangePayload($exchange)], 201);
    }

    public function financeRequests(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:pending,accepted,declined,settled'],
            'type' => ['nullable', 'in:pull,expense,revenue,return'],
        ]);
        $allowedTypes = $this->allowedRequestTypes($request, 'view');
        abort_if($allowedTypes === [], 403);
        if (isset($validated['type']) && ! in_array($validated['type'], $allowedTypes, true)) {
            abort(403);
        }

        return $this->scopeFinanceRequests(FinanceRequest::query(), $request, $allowedTypes)
            ->with(['acceptedCurrency:id,code,name,symbol', 'cashBox:id,name,code', 'category:id,name', 'pullRequestKind:id,name,code,mode', 'requestedBy:id,name', 'requestedCurrency:id,code,name,symbol', 'reviewedBy:id,name'])
            ->when($validated['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (FinanceRequest $financeRequest) => $this->requestPayload($financeRequest));
    }

    public function financeRequest(Request $request, FinanceRequest $financeRequest)
    {
        abort_unless(in_array($financeRequest->type, $this->allowedRequestTypes($request, 'view'), true), 403);
        abort_unless($this->canReviewRequestType($request, $financeRequest->type) || $financeRequest->requested_by === $request->user()->id, 403);
        $financeRequest->load(['acceptedCurrency:id,code,name,symbol', 'cashBox:id,name,code', 'category:id,name', 'pullRequestKind:id,name,code,mode', 'requestedBy:id,name', 'requestedCurrency:id,code,name,symbol', 'reviewedBy:id,name']);

        return response()->json(['data' => $this->requestPayload($financeRequest)]);
    }

    protected function accessibleCashBoxIds(Request $request, FinanceService $financeService): array
    {
        return $financeService->accessibleCashBoxes($request->user(), activeOnly: false)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function allowedRequestTypes(Request $request, string $ability): array
    {
        $permissions = [
            FinanceRequest::TYPE_PULL => "finance.pull-requests.$ability",
            FinanceRequest::TYPE_EXPENSE => "finance.expense-requests.$ability",
            FinanceRequest::TYPE_REVENUE => "finance.revenue-requests.$ability",
            FinanceRequest::TYPE_RETURN => "finance.revenue-requests.$ability",
        ];

        return array_keys(array_filter($permissions, fn (string $permission) => $request->user()?->can($permission)));
    }

    protected function scopeFinanceRequests(Builder $query, Request $request, array $types): Builder
    {
        return $query->where(function (Builder $scope) use ($request, $types): void {
            foreach ($types as $type) {
                $scope->orWhere(function (Builder $typeQuery) use ($request, $type): void {
                    $typeQuery->where('type', $type);

                    if (! $this->canReviewRequestType($request, $type)) {
                        $typeQuery->where('requested_by', $request->user()->id);
                    }
                });
            }
        });
    }

    protected function canReviewRequestType(Request $request, string $type): bool
    {
        $permission = match ($type) {
            FinanceRequest::TYPE_PULL => 'finance.pull-requests.review',
            FinanceRequest::TYPE_EXPENSE => 'finance.expense-requests.review',
            FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN => 'finance.revenue-requests.review',
        };

        return (bool) $request->user()?->can($permission);
    }

    protected function balancePayload(FinanceService $financeService, Request $request): array
    {
        return $financeService->cashBoxBalances($request->user())->map(fn (array $row) => [
            'currencies' => $row['currencies']->map(fn (array $currencyRow) => [
                'balance' => $currencyRow['balance'],
                'currency' => $this->currencyPayload($currencyRow['currency']),
                'local_equivalent' => $currencyRow['local_equivalent'],
            ])->values(),
            'id' => $row['cash_box']->id,
            'is_active' => $row['cash_box']->is_active,
            'local_total' => $row['local_total'],
            'name' => $row['cash_box']->name,
            'code' => $row['cash_box']->code,
        ])->values()->all();
    }

    protected function transactionPayload(FinanceTransaction $transaction): array
    {
        return [
            'amount' => (float) $transaction->amount,
            'base_amount' => (float) $transaction->base_amount,
            'cash_box' => $transaction->cashBox?->only(['id', 'name', 'code']),
            'category' => $transaction->category?->only(['id', 'name']),
            'currency' => $this->currencyPayload($transaction->currency),
            'description' => $transaction->description,
            'direction' => $transaction->direction,
            'entered_by' => $transaction->enteredBy?->only(['id', 'name']),
            'finance_request_id' => $transaction->finance_request_id,
            'id' => $transaction->id,
            'local_amount' => (float) $transaction->local_amount,
            'metadata' => $transaction->metadata,
            'signed_amount' => (float) $transaction->signed_amount,
            'special_transaction_no' => $transaction->special_transaction_no,
            'status' => $transaction->status,
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'transaction_no' => $transaction->transaction_no,
            'type' => $transaction->type,
        ];
    }

    protected function transferPayload(FinanceCashBoxTransfer $transfer): array
    {
        return ['amount' => (float) $transfer->amount, 'currency' => $this->currencyPayload($transfer->currency), 'entered_by' => $transfer->enteredBy?->only(['id', 'name']), 'from_cash_box' => $transfer->fromCashBox?->only(['id', 'name', 'code']), 'id' => $transfer->id, 'notes' => $transfer->notes, 'to_cash_box' => $transfer->toCashBox?->only(['id', 'name', 'code']), 'transfer_date' => $transfer->transfer_date?->format('Y-m-d'), 'transfer_no' => $transfer->transfer_no];
    }

    protected function exchangePayload(FinanceCurrencyExchange $exchange): array
    {
        return ['base_amount' => (float) $exchange->base_amount, 'entered_by' => $exchange->enteredBy?->only(['id', 'name']), 'exchange_date' => $exchange->exchange_date?->format('Y-m-d'), 'exchange_no' => $exchange->exchange_no, 'from_amount' => (float) $exchange->from_amount, 'from_cash_box' => $exchange->fromCashBox?->only(['id', 'name', 'code']), 'from_currency' => $this->currencyPayload($exchange->fromCurrency), 'id' => $exchange->id, 'local_amount' => (float) $exchange->local_amount, 'notes' => $exchange->notes, 'to_amount' => (float) $exchange->to_amount, 'to_cash_box' => $exchange->toCashBox?->only(['id', 'name', 'code']), 'to_currency' => $this->currencyPayload($exchange->toCurrency)];
    }

    protected function requestPayload(FinanceRequest $financeRequest): array
    {
        return ['accepted_amount' => $financeRequest->accepted_amount === null ? null : (float) $financeRequest->accepted_amount, 'accepted_count' => $financeRequest->accepted_count, 'accepted_currency' => $this->currencyPayload($financeRequest->acceptedCurrency), 'cash_box' => $financeRequest->cashBox?->only(['id', 'name', 'code']), 'category' => $financeRequest->category?->only(['id', 'name']), 'counterparty_name' => $financeRequest->counterparty_name, 'expense_no' => $financeRequest->expense_no, 'id' => $financeRequest->id, 'pull_request_kind' => $financeRequest->pullRequestKind?->only(['id', 'name', 'code', 'mode']), 'request_no' => $financeRequest->request_no, 'requested_amount' => (float) $financeRequest->requested_amount, 'requested_by' => $financeRequest->requestedBy?->only(['id', 'name']), 'requested_count' => $financeRequest->requested_count, 'requested_currency' => $this->currencyPayload($financeRequest->requestedCurrency), 'requested_reason' => $financeRequest->requested_reason, 'review_notes' => $financeRequest->review_notes, 'reviewed_by' => $financeRequest->reviewedBy?->only(['id', 'name']), 'status' => $financeRequest->status, 'type' => $financeRequest->type];
    }

    protected function currencyPayload(?FinanceCurrency $currency): ?array
    {
        return $currency ? ['id' => $currency->id, 'code' => $currency->code, 'name' => $currency->name, 'symbol' => $currency->symbol] : null;
    }

    protected function ensureCashBoxCurrency(FinanceService $financeService, FinanceCashBox $cashBox, int $currencyId): void
    {
        if (! $financeService->currenciesForCashBox($cashBox->id)->whereKey($currencyId)->exists()) {
            throw ValidationException::withMessages(['currency_id' => __('finance.validation.cash_box_currency_mismatch')]);
        }
    }

    protected function financeSettingEnabled(string $key): bool
    {
        $settings = AppSetting::groupValues('finance');

        return $settings->has($key) ? (bool) $settings->get($key) : true;
    }

    protected function validateHistoryFilters(Request $request): array
    {
        return $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function authorizeAnyPermission(Request $request, array $permissions): void
    {
        abort_unless(collect($permissions)->contains(fn (string $permission) => $request->user()?->can($permission)), 403);
    }
}
