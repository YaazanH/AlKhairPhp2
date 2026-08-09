<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\AppSetting;
use App\Models\FinanceCashBox;
use App\Models\FinanceCashBoxTransfer;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceCurrencyExchange;
use App\Models\FinanceInvoiceKind;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function financeRequestTypeLabel(string $type): string
    {
        $label = __('finance.request_types.'.$type);

        return $label === 'finance.request_types.'.$type ? Str::headline($type) : $label;
    }

    public function transactionTypeLabel(string $type, ?FinanceTransaction $transaction = null): string
    {
        $label = __('finance.transaction_types.'.$type);

        return $label === 'finance.transaction_types.'.$type ? Str::headline($type) : $label;
    }

    public function transactionCategoryLabel(FinanceTransaction $transaction): string
    {
        $transaction->loadMissing(['category', 'financeRequest.pullRequestKind']);

        if ($transaction->financeRequest?->pullRequestKind) {
            return $transaction->financeRequest->pullRequestKind->name;
        }

        return $transaction->category?->name ?: __('finance.options.uncategorized');
    }

    public function accessibleCashBoxes(?User $user, bool $activeOnly = true): Builder
    {
        $query = FinanceCashBox::query();

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if (! $user || $user->can('finance.cash-box.manage')) {
            return $query->orderBy('name');
        }

        return $query
            ->whereHas('assignedUsers', fn (Builder $builder) => $builder->whereKey($user->id))
            ->orderBy('name');
    }

    public function accessibleCashBoxesForCurrency(?User $user, ?int $currencyId = null, bool $activeOnly = true): Builder
    {
        return $this->accessibleCashBoxes($user, $activeOnly)
            ->when($currencyId, fn (Builder $query) => $query->whereHas('currencies', fn (Builder $currencyQuery) => $currencyQuery->whereKey($currencyId)));
    }

    public function acceptRequest(FinanceRequest $request, float $acceptedAmount, FinanceCashBox $cashBox, ?User $reviewer = null, ?string $notes = null, ?int $acceptedCount = null, ?string $transactionDate = null): FinanceRequest
    {
        return DB::transaction(function () use ($acceptedAmount, $acceptedCount, $cashBox, $notes, $request, $reviewer, $transactionDate): FinanceRequest {
            $request->loadMissing(['invoice', 'pullRequestKind']);
            $currency = $request->acceptedCurrency ?: $request->requestedCurrency ?: $this->localCurrency();
            $direction = in_array($request->type, [FinanceRequest::TYPE_PULL, FinanceRequest::TYPE_EXPENSE], true) ? 'out' : 'in';
            $reference = $this->financeRequestReference($request);
            $isPull = $request->type === FinanceRequest::TYPE_PULL;
            $expenseNo = $direction === 'out' ? ($request->expense_no ?: $this->nextExpenseNumber()) : null;
            $specialTransactionNo = $expenseNo ?: $request->request_no;

            $transaction = $this->postTransaction([
                'cash_box_id' => $cashBox->id,
                'currency_id' => $currency->id,
                'finance_category_id' => $request->finance_category_id,
                'activity_id' => $isPull ? null : $request->activity_id,
                'teacher_id' => $request->teacher_id,
                'finance_request_id' => $request->id,
                'source_type' => FinanceRequest::class,
                'source_id' => $request->id,
                'type' => $request->type.'_request',
                'special_transaction_no' => $specialTransactionNo,
                'direction' => $direction,
                'amount' => $acceptedAmount,
                'transaction_date' => $transactionDate ?: now()->toDateString(),
                'description' => $request->requested_reason,
                'entered_by' => $reviewer?->id,
                'metadata' => [
                    'reference' => $reference,
                    'pull_kind' => $request->pullRequestKind?->code,
                    'pull_mode' => $request->pullRequestKind?->mode,
                    'requested_amount' => (float) $request->requested_amount,
                    'accepted_amount' => $acceptedAmount,
                    'requested_count' => $request->requested_count,
                    'accepted_count' => $acceptedCount,
                    'review_notes' => $notes,
                ],
            ]);

            $request->update([
                'accepted_amount' => $acceptedAmount,
                'accepted_count' => $acceptedCount,
                'accepted_currency_id' => $currency->id,
                'accepted_at' => now(),
                'cash_box_id' => $cashBox->id,
                'declined_at' => null,
                'posted_transaction_id' => $transaction->id,
                'review_notes' => $notes,
                'reviewed_by' => $reviewer?->id,
                'status' => FinanceRequest::STATUS_ACCEPTED,
                'expense_no' => $expenseNo ?: $request->expense_no,
            ]);

            if ($request->activity_id) {
                $this->syncActivityTotals($request->activity()->first());
            }

            return $request->fresh();
        });
    }

    public function settleCountPullRequest(FinanceRequest $request, int $finalCount, float $remainingAmount, ?User $user = null): FinanceRequest
    {
        return DB::transaction(function () use ($finalCount, $remainingAmount, $request, $user): FinanceRequest {
            $request->loadMissing(['acceptedCurrency', 'cashBox', 'pullRequestKind']);

            if ($request->type !== FinanceRequest::TYPE_PULL || $request->status !== FinanceRequest::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'request' => __('finance.validation.pull_request_not_settleable'),
                ]);
            }

            if ($request->pullRequestKind?->mode !== 'count') {
                throw ValidationException::withMessages([
                    'request' => __('finance.validation.pull_request_wrong_mode'),
                ]);
            }

            $returnTransaction = null;
            $remainingAmount = round(max($remainingAmount, 0), 2);

            if ($remainingAmount > 0) {
                $generatedRequest = $this->createGeneratedRequestFromPull(
                    $request,
                    FinanceRequest::TYPE_RETURN,
                    $remainingAmount,
                    $request->requested_reason ?: __('finance.transaction_types.return'),
                    $user,
                );

                $returnTransaction = $this->postTransaction([
                    'cash_box_id' => $generatedRequest->cash_box_id,
                    'currency_id' => $generatedRequest->accepted_currency_id,
                    'finance_category_id' => $generatedRequest->finance_category_id,
                    'teacher_id' => $generatedRequest->teacher_id,
                    'finance_request_id' => $generatedRequest->id,
                    'source_type' => FinanceRequest::class,
                    'source_id' => $generatedRequest->id,
                    'type' => 'pull_request_return',
                    'direction' => 'in',
                    'amount' => $remainingAmount,
                    'transaction_date' => now()->toDateString(),
                    'description' => $generatedRequest->requested_reason,
                    'entered_by' => $user?->id,
                    'metadata' => [
                        'reference' => $request->request_no,
                        'final_count' => $finalCount,
                        'parent_pull_request_id' => $request->id,
                        'parent_pull_request_no' => $request->request_no,
                    ],
                ]);

                $this->markGeneratedRequestPosted($generatedRequest, $returnTransaction, $user);
            }

            $request->update([
                'final_count' => $finalCount,
                'remaining_amount' => $remainingAmount,
                'return_transaction_id' => $returnTransaction?->id,
                'settled_at' => now(),
                'settled_by' => $user?->id,
                'status' => FinanceRequest::STATUS_SETTLED,
            ]);

            return $request->fresh();
        });
    }

    public function finaliseCountExpense(FinanceRequest $request, int $finalCount, float $remainingAmount, ?User $user = null): FinanceRequest
    {
        return DB::transaction(function () use ($finalCount, $remainingAmount, $request, $user): FinanceRequest {
            $request->loadMissing(['acceptedCurrency', 'postedTransaction', 'pullRequestKind']);

            if ($request->status !== FinanceRequest::STATUS_ACCEPTED || $request->pullRequestKind?->mode !== FinancePullRequestKind::MODE_COUNT) {
                throw ValidationException::withMessages(['request' => __('finance.validation.pull_request_not_settleable')]);
            }

            $remainingAmount = round(max(0, $remainingAmount), 2);
            $acceptedAmount = (float) $request->accepted_amount;

            if ($remainingAmount > $acceptedAmount) {
                throw ValidationException::withMessages(['remaining_amount' => __('finance.validation.remaining_exceeds_accepted')]);
            }

            $finalAmount = round($acceptedAmount - $remainingAmount, 2);
            $this->replaceExpenseTransactionAmount($request, $finalAmount, $user);

            $request->update([
                'accepted_amount' => $finalAmount,
                'final_count' => $finalCount,
                'remaining_amount' => $remainingAmount,
                'settled_at' => now(),
                'settled_by' => $user?->id,
                'status' => FinanceRequest::STATUS_SETTLED,
            ]);

            return $request->fresh();
        });
    }

    public function finaliseInvoiceExpense(FinanceRequest $request, Invoice $invoice, ?User $user = null): FinanceRequest
    {
        return DB::transaction(function () use ($invoice, $request, $user): FinanceRequest {
            $request->loadMissing(['postedTransaction', 'pullRequestKind']);
            $invoice->refresh();

            if ($request->status !== FinanceRequest::STATUS_ACCEPTED || $request->pullRequestKind?->mode !== FinancePullRequestKind::MODE_INVOICE) {
                throw ValidationException::withMessages(['request' => __('finance.validation.pull_request_not_settleable')]);
            }

            if ((int) $invoice->finance_request_id !== (int) $request->id || (float) $invoice->total <= 0) {
                throw ValidationException::withMessages(['invoice' => __('finance.validation.invoice_pull_mismatch')]);
            }

            $finalAmount = round((float) $invoice->total, 2);
            $this->replaceExpenseTransactionAmount($request, $finalAmount, $user);

            $invoice->update([
                'status' => 'issued',
                'finalised_at' => now(),
                'finalised_by' => $user?->id,
            ]);

            $request->update([
                'accepted_amount' => $finalAmount,
                'invoice_id' => $invoice->id,
                'remaining_amount' => max(round((float) $request->requested_amount - $finalAmount, 2), 0),
                'settled_at' => now(),
                'settled_by' => $user?->id,
                'status' => FinanceRequest::STATUS_SETTLED,
            ]);

            return $request->fresh();
        });
    }

    public function settleInvoicePullRequest(FinanceRequest $request, Invoice $invoice, ?User $user = null): FinanceRequest
    {
        return DB::transaction(function () use ($invoice, $request, $user): FinanceRequest {
            $request->loadMissing(['acceptedCurrency', 'cashBox', 'postedTransaction', 'pullRequestKind']);
            $invoice->refresh();

            if ($request->type !== FinanceRequest::TYPE_PULL || $request->status !== FinanceRequest::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'request' => __('finance.validation.pull_request_not_settleable'),
                ]);
            }

            if ($request->pullRequestKind?->mode !== 'invoice') {
                throw ValidationException::withMessages([
                    'request' => __('finance.validation.pull_request_wrong_mode'),
                ]);
            }

            if ((int) $invoice->finance_request_id !== (int) $request->id) {
                throw ValidationException::withMessages([
                    'invoice' => __('finance.validation.invoice_pull_mismatch'),
                ]);
            }

            $reference = $this->financeRequestReference($request, $invoice);
            $acceptedAmount = (float) $request->accepted_amount;
            $invoiceTotal = (float) $invoice->total;
            $difference = round($acceptedAmount - $invoiceTotal, 2);
            $returnTransaction = null;
            $closingTransaction = null;

            if ($invoice->status === 'draft') {
                $invoice->update(['status' => 'issued']);
            }

            if ($request->postedTransaction) {
                $metadata = $request->postedTransaction->metadata ?: [];
                $metadata['reference'] = $reference;
                $metadata['invoice_total'] = $invoiceTotal;

                $request->postedTransaction->update([
                    'description' => $reference,
                    'metadata' => $metadata,
                ]);
            }

            if ($difference > 0) {
                $generatedRequest = $this->createGeneratedRequestFromPull(
                    $request,
                    FinanceRequest::TYPE_RETURN,
                    $difference,
                    __('finance.descriptions.invoice_pull_return', ['invoice' => $invoice->invoice_no, 'request' => $request->request_no]),
                    $user,
                    $invoice,
                );

                $returnTransaction = $this->postTransaction([
                    'cash_box_id' => $generatedRequest->cash_box_id,
                    'currency_id' => $generatedRequest->accepted_currency_id,
                    'finance_category_id' => $generatedRequest->finance_category_id,
                    'teacher_id' => $generatedRequest->teacher_id,
                    'finance_request_id' => $generatedRequest->id,
                    'source_type' => FinanceRequest::class,
                    'source_id' => $generatedRequest->id,
                    'type' => 'invoice_pull_return',
                    'direction' => 'in',
                    'amount' => $difference,
                    'transaction_date' => $invoice->issue_date?->toDateString() ?? now()->toDateString(),
                    'description' => $generatedRequest->requested_reason,
                    'entered_by' => $user?->id,
                    'metadata' => [
                        'reference' => $reference,
                        'invoice_total' => $invoiceTotal,
                        'parent_pull_request_id' => $request->id,
                        'parent_pull_request_no' => $request->request_no,
                    ],
                ]);

                $this->markGeneratedRequestPosted($generatedRequest, $returnTransaction, $user);
            } elseif ($difference < 0) {
                $generatedRequest = $this->createGeneratedRequestFromPull(
                    $request,
                    FinanceRequest::TYPE_EXPENSE,
                    abs($difference),
                    __('finance.descriptions.invoice_pull_closing', ['invoice' => $invoice->invoice_no, 'request' => $request->request_no]),
                    $user,
                    $invoice,
                );

                $closingTransaction = $this->postTransaction([
                    'cash_box_id' => $generatedRequest->cash_box_id,
                    'currency_id' => $generatedRequest->accepted_currency_id,
                    'finance_category_id' => $generatedRequest->finance_category_id,
                    'teacher_id' => $generatedRequest->teacher_id,
                    'finance_request_id' => $generatedRequest->id,
                    'source_type' => FinanceRequest::class,
                    'source_id' => $generatedRequest->id,
                    'type' => 'invoice_pull_closing_expense',
                    'direction' => 'out',
                    'amount' => abs($difference),
                    'transaction_date' => $invoice->issue_date?->toDateString() ?? now()->toDateString(),
                    'description' => $generatedRequest->requested_reason,
                    'entered_by' => $user?->id,
                    'metadata' => [
                        'reference' => $reference,
                        'invoice_total' => $invoiceTotal,
                        'parent_pull_request_id' => $request->id,
                        'parent_pull_request_no' => $request->request_no,
                    ],
                ]);

                $this->markGeneratedRequestPosted($generatedRequest, $closingTransaction, $user);
            }

            $request->update([
                'invoice_id' => $invoice->id,
                'remaining_amount' => max($difference, 0),
                'return_transaction_id' => $returnTransaction?->id,
                'closing_transaction_id' => $closingTransaction?->id,
                'settled_at' => now(),
                'settled_by' => $user?->id,
                'status' => FinanceRequest::STATUS_SETTLED,
            ]);

            return $request->fresh();
        });
    }

    public function baseCurrency(): FinanceCurrency
    {
        return FinanceCurrency::query()
            ->where('is_base', true)
            ->where('is_active', true)
            ->first()
            ?: FinanceCurrency::query()->where('is_base', true)->firstOrFail();
    }

    public function cashBoxBalances(?User $user = null): Collection
    {
        $cashBoxes = $this->accessibleCashBoxes($user, activeOnly: false)
            ->with(['currencies' => fn ($query) => $query
                ->where('is_active', true)
                ->orderByDesc('is_local')
                ->orderByDesc('is_base')
                ->orderBy('code')])
            ->get();
        $local = $this->localCurrency();

        $rawBalances = FinanceTransaction::query()
            ->selectRaw('cash_box_id, currency_id, SUM(signed_amount) as balance')
            ->whereIn('cash_box_id', $cashBoxes->pluck('id'))
            ->groupBy('cash_box_id', 'currency_id')
            ->get()
            ->keyBy(fn ($row) => $row->cash_box_id.'-'.$row->currency_id);

        return $cashBoxes->map(function (FinanceCashBox $cashBox) use ($local, $rawBalances) {
            $currencyRows = $cashBox->currencies->map(function (FinanceCurrency $currency) use ($cashBox, $local, $rawBalances) {
                $balance = (float) ($rawBalances->get($cashBox->id.'-'.$currency->id)?->balance ?? 0);
                $baseEquivalent = $balance * (float) $currency->rate_to_base;
                $localEquivalent = (float) $local->rate_to_base > 0 ? $baseEquivalent / (float) $local->rate_to_base : $baseEquivalent;

                return [
                    'currency' => $currency,
                    'balance' => round($balance, 2),
                    'local_equivalent' => round($localEquivalent, 2),
                ];
            });

            return [
                'cash_box' => $cashBox,
                'currencies' => $currencyRows,
                'local_total' => round($currencyRows->sum('local_equivalent'), 2),
            ];
        });
    }

    public function cashBoxForUser(int $cashBoxId, ?User $user, bool $activeOnly = true): FinanceCashBox
    {
        return $this->accessibleCashBoxes($user, $activeOnly)
            ->whereKey($cashBoxId)
            ->firstOrFail();
    }

    public function currenciesForCashBox(?int $cashBoxId = null, bool $activeOnly = true): Builder
    {
        return FinanceCurrency::query()
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->when($cashBoxId, fn (Builder $query) => $query->whereHas('cashBoxes', fn (Builder $cashBoxQuery) => $cashBoxQuery->whereKey($cashBoxId)))
            ->orderByDesc('is_local')
            ->orderByDesc('is_base')
            ->orderBy('code');
    }

    public function currencyBalance(FinanceCurrency $currency): float
    {
        return round((float) FinanceTransaction::query()
            ->where('currency_id', $currency->id)
            ->sum('signed_amount'), 2);
    }

    public function currencyIsUsed(FinanceCurrency $currency): bool
    {
        return FinanceTransaction::query()->where('currency_id', $currency->id)->exists()
            || FinanceRequest::query()->where('requested_currency_id', $currency->id)->orWhere('accepted_currency_id', $currency->id)->exists()
            || FinanceCurrencyExchange::query()->where('from_currency_id', $currency->id)->orWhere('to_currency_id', $currency->id)->exists()
            || FinanceCashBoxTransfer::query()->where('currency_id', $currency->id)->exists();
    }

    public function currencyRateInput(FinanceCurrency $currency): string
    {
        if ($currency->is_base) {
            return '1';
        }

        return $this->formatRateInputNumber($this->currencyReferenceQuoteAmount($currency), 8);
    }

    public function currencyRateLabel(FinanceCurrency $currency, ?FinanceCurrency $baseCurrency = null): string
    {
        $baseCurrency ??= $this->baseCurrency();

        if ($currency->is_base) {
            return __('finance.rate_formats.base', [
                'base' => $currency->code,
            ]);
        }

        $referenceCurrency = $currency->rateReferenceCurrency ?: $baseCurrency;

        return __('finance.rate_formats.reference_to_currency', [
            'reference' => $referenceCurrency->code,
            'amount' => $this->formatRateNumber($this->currencyReferenceQuoteAmount($currency, $referenceCurrency), 8),
            'currency' => $currency->code,
        ]);
    }

    public function formatCurrencyAmount(float|int|string|null $amount, ?FinanceCurrency $currency, bool $withCode = true): string
    {
        $decimals = max(0, min(6, (int) ($currency?->decimal_places ?? 2)));
        $numericAmount = (float) ($amount ?? 0);
        $formatted = number_format(abs($numericAmount), $decimals);

        if (! $withCode) {
            return $formatted;
        }

        $sign = $numericAmount < 0 ? '-' : '';
        $currencyLabel = $currency?->symbol ?: $currency?->code;

        return trim($sign.$formatted.($currencyLabel ? ' '.$currencyLabel : ''));
    }

    public function exchangeRateLabel(float $fromRateToBase, float $toRateToBase, ?string $fromCode = null, ?string $toCode = null): string
    {
        if ($toRateToBase <= 0) {
            return '-';
        }

        return __('finance.rate_formats.exchange', [
            'from' => $fromCode ?: __('finance.fields.from'),
            'amount' => $this->formatRateNumber($fromRateToBase / $toRateToBase, 8),
            'to' => $toCode ?: __('finance.fields.to'),
        ]);
    }

    public function declineRequest(FinanceRequest $request, ?User $reviewer = null, ?string $notes = null): FinanceRequest
    {
        $request->update([
            'declined_at' => now(),
            'review_notes' => $notes,
            'reviewed_by' => $reviewer?->id,
            'status' => FinanceRequest::STATUS_DECLINED,
        ]);

        return $request->fresh();
    }

    public function updateFinanceRequestEntry(FinanceRequest $request, array $payload, ?User $user = null): FinanceRequest
    {
        return DB::transaction(function () use ($payload, $request, $user): FinanceRequest {
            $date = $payload['request_date'] ?? null;
            $requestedReason = array_key_exists('requested_reason', $payload) ? $payload['requested_reason'] : $request->requested_reason;
            $updates = [
                'counterparty_name' => array_key_exists('counterparty_name', $payload) ? $payload['counterparty_name'] : $request->counterparty_name,
                'requested_reason' => $requestedReason,
            ];

            if ($request->type === FinanceRequest::TYPE_EXPENSE && isset($payload['amount'], $payload['cash_box_id'], $payload['currency_id'])) {
                $amount = abs((float) $payload['amount']);
                $cashBox = $this->cashBoxForUser((int) $payload['cash_box_id'], $user);
                $currency = FinanceCurrency::query()->findOrFail((int) $payload['currency_id']);
                $this->ensureCashBoxSupportsCurrency($cashBox, $currency);

                $transaction = $request->postedTransaction ?: FinanceTransaction::query()
                    ->where('source_type', FinanceRequest::class)
                    ->where('source_id', $request->id)
                    ->where('type', 'expense')
                    ->latest('id')
                    ->first();

                $updates['cash_box_id'] = $cashBox->id;
                $updates['finance_pull_request_kind_id'] = $payload['finance_pull_request_kind_id'] ?? $request->finance_pull_request_kind_id;
                $updates['finance_category_id'] = $updates['finance_pull_request_kind_id'];
                $updates['requested_amount'] = $amount;
                $updates['requested_currency_id'] = $currency->id;

                if ($request->accepted_amount !== null || $transaction) {
                    $updates['accepted_amount'] = $amount;
                    $updates['accepted_currency_id'] = $currency->id;
                }

                if ($transaction) {
                    $signedAmount = -$amount;
                    $snapshot = $this->amountSnapshot($currency, $signedAmount);
                    $this->ensureNonNegativeReplacementBalance($cashBox, $currency, $signedAmount, $transaction);

                    $transaction->update([
                        'amount' => $amount,
                        'base_amount' => $snapshot['base_amount'],
                        'cash_box_id' => $cashBox->id,
                        'currency_id' => $currency->id,
                        'description' => filled($requestedReason) ? trim((string) $requestedReason) : null,
                        'direction' => 'out',
                        'entered_by' => $user?->id ?: $transaction->entered_by,
                        'finance_category_id' => $updates['finance_category_id'],
                        'local_amount' => $snapshot['local_amount'],
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'edited_at' => now()->toISOString(),
                            'edited_by' => $user?->id,
                            'edited_from_amount' => (float) $transaction->amount,
                        ]),
                        'rate_to_base' => $snapshot['rate_to_base'],
                        'signed_amount' => $signedAmount,
                        'transaction_date' => $date ?: $transaction->transaction_date,
                    ]);

                    $updates['posted_transaction_id'] = $transaction->id;
                }
            }

            if ($date) {
                $transactions = FinanceTransaction::query()
                    ->where('source_type', FinanceRequest::class)
                    ->where('source_id', $request->id)
                    ->get();

                foreach ($transactions as $transaction) {
                    $transaction->update(['transaction_date' => $date]);
                }

                if ($request->accepted_at) {
                    $updates['accepted_at'] = Carbon::parse($date)->setTimeFrom($request->accepted_at);
                }

                if ($request->settled_at) {
                    $updates['settled_at'] = Carbon::parse($date)->setTimeFrom($request->settled_at);
                }
            }

            $request->update($updates);

            return $request->fresh();
        });
    }

    public function deleteFinanceRequestEntry(FinanceRequest $request, ?User $user = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($reason, $request, $user): void {
            $this->reverseSourceTransactions(
                FinanceRequest::class,
                $request->id,
                $user,
                $reason ?: __('finance.descriptions.request_deleted_plain'),
            );

            $request->delete();
        });
    }

    public function calculateExchangeToAmount(FinanceCurrency $fromCurrency, FinanceCurrency $toCurrency, float $fromAmount): float
    {
        if ($fromAmount <= 0 || (float) $toCurrency->rate_to_base <= 0) {
            return 0.0;
        }

        return round(($fromAmount * (float) $fromCurrency->rate_to_base) / (float) $toCurrency->rate_to_base, 2);
    }

    public function defaultCashBox(): FinanceCashBox
    {
        $configuredId = AppSetting::groupValues('finance')->get('default_cash_box_id');

        return FinanceCashBox::query()
            ->where('is_active', true)
            ->when($configuredId, fn (Builder $query) => $query->whereKey((int) $configuredId))
            ->first()
            ?: FinanceCashBox::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    public function defaultCashBoxForUser(?User $user = null, ?int $currencyId = null): ?FinanceCashBox
    {
        $configuredId = AppSetting::groupValues('finance')->get('default_cash_box_id');

        if ($configuredId) {
            $configured = $this->accessibleCashBoxesForCurrency($user, $currencyId)
                ->whereKey((int) $configuredId)
                ->first();

            if ($configured) {
                return $configured;
            }
        }

        return $this->accessibleCashBoxesForCurrency($user, $currencyId)->first();
    }

    public function defaultInvoiceKindId(): int
    {
        $kind = FinanceInvoiceKind::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->first()
            ?: FinanceInvoiceKind::query()->orderBy('name')->first();

        if ($kind) {
            return (int) $kind->id;
        }

        return (int) FinanceInvoiceKind::query()->create([
            'code' => 'general',
            'is_active' => true,
            'name' => 'General',
        ])->id;
    }

    public function defaultPullRequestKindId(): ?int
    {
        $configuredId = AppSetting::groupValues('finance')->get('default_pull_request_kind_id');

        if ($configuredId && FinancePullRequestKind::query()->where('is_active', true)->whereKey((int) $configuredId)->exists()) {
            return (int) $configuredId;
        }

        return FinancePullRequestKind::query()
            ->where('is_active', true)
            ->orderBy('mode')
            ->orderBy('name')
            ->value('id');
    }

    public function defaultRevenueCategoryId(): ?int
    {
        $configuredId = AppSetting::groupValues('finance')->get('default_revenue_category_id');

        if ($configuredId && FinanceCategory::query()
            ->where('is_active', true)
            ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])
            ->whereKey((int) $configuredId)
            ->exists()) {
            return (int) $configuredId;
        }

        return FinanceCategory::query()
            ->where('is_active', true)
            ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])
            ->orderByRaw("case when type = 'revenue' then 0 else 1 end")
            ->orderBy('name')
            ->value('id');
    }

    public function localCurrency(): FinanceCurrency
    {
        return FinanceCurrency::query()
            ->where('is_local', true)
            ->where('is_active', true)
            ->first()
            ?: FinanceCurrency::query()->where('is_local', true)->firstOrFail();
    }

    public function nextInvoiceNumber(): string
    {
        $prefix = $this->numberingPrefix('invoice_prefix', 'INV');

        $lastInvoiceNo = Invoice::query()
            ->where('invoice_no', 'like', $prefix.'-%')
            ->latest('id')
            ->value('invoice_no');

        return $this->sequencedNumber($prefix, $lastInvoiceNo, 6);
    }

    public function nextRequestNumber(string $type): string
    {
        $prefix = $this->requestNumberPrefix($type);

        $lastRequestNo = FinanceRequest::query()
            ->where('request_no', 'like', $prefix.'-%')
            ->latest('id')
            ->value('request_no');

        return $this->sequencedNumber($prefix, $lastRequestNo, 6);
    }

    public function nextExpenseNumber(): string
    {
        $prefix = $this->numberingPrefix('expense_request_prefix', 'EXP');
        $last = FinanceRequest::query()->where('expense_no', 'like', $prefix.'-%')->latest('id')->value('expense_no');

        return $this->sequencedNumber($prefix, $last, 6);
    }

    public function nextExchangeNumber(): string
    {
        $prefix = $this->numberingPrefix('exchange_prefix', 'EXC');

        $lastExchangeNo = FinanceCurrencyExchange::query()
            ->where('exchange_no', 'like', $prefix.'-%')
            ->latest('id')
            ->value('exchange_no');

        return $this->sequencedNumber($prefix, $lastExchangeNo, 6);
    }

    public function nextTransferNumber(): string
    {
        $prefix = $this->numberingPrefix('transfer_prefix', 'TRSF');

        $lastTransferNo = FinanceCashBoxTransfer::query()
            ->where('transfer_no', 'like', $prefix.'-%')
            ->latest('id')
            ->value('transfer_no');

        return $this->sequencedNumber($prefix, $lastTransferNo, 6);
    }

    public function postTransaction(array $payload): FinanceTransaction
    {
        $currency = FinanceCurrency::query()->findOrFail((int) $payload['currency_id']);
        $cashBox = FinanceCashBox::query()->findOrFail((int) $payload['cash_box_id']);
        $direction = (string) ($payload['direction'] ?? 'in');
        $amount = abs((float) ($payload['amount'] ?? 0));
        $signedAmount = $direction === 'out' ? -$amount : $amount;
        $snapshot = $this->amountSnapshot($currency, $signedAmount);
        $specialTransactionNo = $payload['special_transaction_no'] ?? null;
        $description = $this->cleanTransactionDescription($payload['description'] ?? null);

        if (! $specialTransactionNo && ! empty($payload['finance_request_id'])) {
            $financeRequest = FinanceRequest::query()->find((int) $payload['finance_request_id']);
            $specialTransactionNo = $financeRequest?->expense_no ?: $financeRequest?->request_no;
            if ($description === null) {
                $description = $this->cleanTransactionDescription($financeRequest?->requested_reason);
            }
        }

        $this->ensureCashBoxSupportsCurrency($cashBox, $currency);
        $this->ensureNonNegativeBalance($cashBox, $currency, $signedAmount);

        return FinanceTransaction::query()->create([
            'transaction_no' => $payload['transaction_no'] ?? $this->nextTransactionNumber(),
            'special_transaction_no' => $specialTransactionNo,
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $payload['finance_category_id'] ?? null,
            'activity_id' => $payload['activity_id'] ?? null,
            'teacher_id' => $payload['teacher_id'] ?? null,
            'finance_request_id' => $payload['finance_request_id'] ?? null,
            'source_type' => $payload['source_type'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'type' => $this->normalizeTransactionType((string) $payload['type'], $direction),
            'direction' => $direction,
            'amount' => $amount,
            'signed_amount' => $signedAmount,
            'rate_to_base' => $snapshot['rate_to_base'],
            'base_amount' => $snapshot['base_amount'],
            'local_amount' => $snapshot['local_amount'],
            'transaction_date' => $payload['transaction_date'] ?? now()->toDateString(),
            'description' => $description,
            'entered_by' => $payload['entered_by'] ?? auth()->id(),
            'pair_uuid' => $payload['pair_uuid'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ]);
    }

    public function updateTransaction(FinanceTransaction $transaction, array $payload, ?User $user = null): FinanceTransaction
    {
        return DB::transaction(function () use ($payload, $transaction, $user): FinanceTransaction {
            $cashBox = $this->cashBoxForUser((int) $payload['cash_box_id'], $user, false);
            $currency = FinanceCurrency::query()->findOrFail((int) $payload['currency_id']);
            $direction = (string) $payload['direction'];
            $amount = abs((float) $payload['amount']);
            $signedAmount = $direction === 'out' ? -$amount : $amount;
            $snapshot = $this->amountSnapshot($currency, $signedAmount);

            $this->ensureCashBoxSupportsCurrency($cashBox, $currency);
            $this->ensureNonNegativeReplacementBalance($cashBox, $currency, $signedAmount, $transaction);

            $transaction->update([
                'amount' => $amount,
                'base_amount' => $snapshot['base_amount'],
                'cash_box_id' => $cashBox->id,
                'currency_id' => $currency->id,
                'description' => $this->cleanTransactionDescription($payload['description'] ?? null),
                'direction' => $direction,
                'entered_by' => $payload['entered_by'] ?? $user?->id ?? $transaction->entered_by,
                'finance_category_id' => $payload['finance_category_id'] ?: null,
                'local_amount' => $snapshot['local_amount'],
                'rate_to_base' => $snapshot['rate_to_base'],
                'signed_amount' => $signedAmount,
                'transaction_date' => $payload['transaction_date'],
                'type' => $this->normalizeTransactionType((string) $payload['type'], $direction),
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'edited_at' => now()->toISOString(),
                    'edited_by' => $user?->id,
                ]),
            ]);

            return $transaction->fresh();
        });
    }

    public function deleteTransactionRecord(FinanceTransaction $transaction, ?User $user = null, ?string $reason = null): int
    {
        return DB::transaction(function () use ($reason, $transaction, $user): int {
            $query = FinanceTransaction::query();

            if ($transaction->pair_uuid) {
                $query->where('pair_uuid', $transaction->pair_uuid);
            } else {
                $query->whereKey($transaction->id);
            }

            $transactions = $query->get();

            foreach ($transactions as $row) {
                $row->update([
                    'status' => 'deleted',
                    'deleted_by' => $user?->id,
                    'deletion_reason' => $reason,
                ]);
                $row->delete();
            }

            return $transactions->count();
        });
    }

    public function recordActivityExpense(ActivityExpense $expense, ?float $previousAmount = null): void
    {
        if ($previousAmount !== null && FinanceTransaction::query()->where('source_type', ActivityExpense::class)->where('source_id', $expense->id)->exists()) {
            $difference = round((float) $expense->amount - $previousAmount, 2);

            if (abs($difference) < 0.01) {
                return;
            }

            $this->postTransaction([
                'cash_box_id' => $this->defaultCashBox()->id,
                'currency_id' => $this->localCurrency()->id,
                'activity_id' => $expense->activity_id,
                'source_type' => ActivityExpense::class,
                'source_id' => $expense->id,
                'type' => 'activity_expense_adjustment',
                'direction' => $difference > 0 ? 'out' : 'in',
                'amount' => abs($difference),
                'transaction_date' => $expense->spent_on?->toDateString() ?? now()->toDateString(),
                'description' => $expense->description,
                'entered_by' => $expense->entered_by,
                'metadata' => ['adjustment_for_previous_amount' => $previousAmount],
            ]);

            return;
        }

        if (FinanceTransaction::query()->where('source_type', ActivityExpense::class)->where('source_id', $expense->id)->where('type', 'expense')->exists()) {
            return;
        }

        $this->postTransaction([
            'cash_box_id' => $this->defaultCashBox()->id,
            'currency_id' => $this->localCurrency()->id,
            'activity_id' => $expense->activity_id,
            'source_type' => ActivityExpense::class,
            'source_id' => $expense->id,
            'type' => 'activity_expense',
            'direction' => 'out',
            'amount' => (float) $expense->amount,
            'transaction_date' => $expense->spent_on?->toDateString() ?? now()->toDateString(),
            'description' => $expense->description,
            'entered_by' => $expense->entered_by,
        ]);
    }

    public function recordActivityPayment(ActivityPayment $payment): void
    {
        if ($payment->voided_at || FinanceTransaction::query()->where('source_type', ActivityPayment::class)->where('source_id', $payment->id)->where('type', 'income')->exists()) {
            return;
        }

        $payment->loadMissing('registration');

        $this->postTransaction([
            'cash_box_id' => $this->defaultCashBox()->id,
            'currency_id' => $this->localCurrency()->id,
            'activity_id' => $payment->registration?->activity_id,
            'source_type' => ActivityPayment::class,
            'source_id' => $payment->id,
            'type' => 'activity_payment',
            'direction' => 'in',
            'amount' => (float) $payment->amount,
            'transaction_date' => $payment->paid_at?->toDateString() ?? now()->toDateString(),
            'description' => $payment->notes,
            'entered_by' => $payment->entered_by,
        ]);
    }

    public function recordCashBoxTransfer(FinanceCashBox $fromCashBox, FinanceCashBox $toCashBox, FinanceCurrency $currency, float $amount, string $date, ?User $user = null, ?string $notes = null): FinanceCashBoxTransfer
    {
        return DB::transaction(function () use ($amount, $currency, $date, $fromCashBox, $notes, $toCashBox, $user): FinanceCashBoxTransfer {
            $pairUuid = (string) Str::uuid();

            $transferNo = $this->nextTransferNumber();
            $transfer = FinanceCashBoxTransfer::query()->create([
                'pair_uuid' => $pairUuid,
                'transfer_no' => $transferNo,
                'from_cash_box_id' => $fromCashBox->id,
                'to_cash_box_id' => $toCashBox->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
                'transfer_date' => $date,
                'entered_by' => $user?->id,
                'notes' => $notes,
            ]);

            foreach ([['box' => $fromCashBox, 'direction' => 'out'], ['box' => $toCashBox, 'direction' => 'in']] as $side) {
                $this->postTransaction([
                    'cash_box_id' => $side['box']->id,
                    'currency_id' => $currency->id,
                    'source_type' => FinanceCashBoxTransfer::class,
                    'source_id' => $transfer->id,
                    'type' => 'cash_box_transfer',
                    'special_transaction_no' => $transferNo,
                    'direction' => $side['direction'],
                    'amount' => $amount,
                    'transaction_date' => $date,
                    'description' => $notes,
                    'entered_by' => $user?->id,
                    'pair_uuid' => $pairUuid,
                ]);
            }

            return $transfer;
        });
    }

    public function recordCurrencyExchange(FinanceCashBox $fromCashBox, FinanceCurrency $fromCurrency, float $fromAmount, FinanceCashBox $toCashBox, FinanceCurrency $toCurrency, float $toAmount, string $date, ?User $user = null, ?string $notes = null): FinanceCurrencyExchange
    {
        return DB::transaction(function () use ($date, $fromAmount, $fromCashBox, $fromCurrency, $notes, $toAmount, $toCashBox, $toCurrency, $user): FinanceCurrencyExchange {
            $pairUuid = (string) Str::uuid();
            $fromSnapshot = $this->amountSnapshot($fromCurrency, $fromAmount);
            $exchangeNo = $this->nextExchangeNumber();
            $description = $notes;

            $exchange = FinanceCurrencyExchange::query()->create([
                'pair_uuid' => $pairUuid,
                'exchange_no' => $exchangeNo,
                'from_cash_box_id' => $fromCashBox->id,
                'to_cash_box_id' => $toCashBox->id,
                'from_currency_id' => $fromCurrency->id,
                'to_currency_id' => $toCurrency->id,
                'from_amount' => $fromAmount,
                'to_amount' => $toAmount,
                'from_rate_to_base' => $fromCurrency->rate_to_base,
                'to_rate_to_base' => $toCurrency->rate_to_base,
                'base_amount' => abs($fromSnapshot['base_amount']),
                'local_amount' => abs($fromSnapshot['local_amount']),
                'exchange_date' => $date,
                'entered_by' => $user?->id,
                'notes' => $notes,
            ]);

            $this->postTransaction([
                'cash_box_id' => $fromCashBox->id,
                'currency_id' => $fromCurrency->id,
                'source_type' => FinanceCurrencyExchange::class,
                'source_id' => $exchange->id,
                'type' => 'currency_exchange',
                'special_transaction_no' => $exchangeNo,
                'direction' => 'out',
                'amount' => $fromAmount,
                'transaction_date' => $date,
                'description' => $description,
                'entered_by' => $user?->id,
                'pair_uuid' => $pairUuid,
                'metadata' => [
                    'exchange_no' => $exchangeNo,
                    'reference' => $exchangeNo,
                ],
            ]);

            $this->postTransaction([
                'cash_box_id' => $toCashBox->id,
                'currency_id' => $toCurrency->id,
                'source_type' => FinanceCurrencyExchange::class,
                'source_id' => $exchange->id,
                'type' => 'currency_exchange',
                'special_transaction_no' => $exchangeNo,
                'direction' => 'in',
                'amount' => $toAmount,
                'transaction_date' => $date,
                'description' => $description,
                'entered_by' => $user?->id,
                'pair_uuid' => $pairUuid,
                'metadata' => [
                    'exchange_no' => $exchangeNo,
                    'reference' => $exchangeNo,
                ],
            ]);

            return $exchange;
        });
    }

    public function recordInvoicePayment(Payment $payment): void
    {
        if ($payment->voided_at || FinanceTransaction::query()->where('source_type', Payment::class)->where('source_id', $payment->id)->where('type', 'invoice_payment')->exists()) {
            return;
        }

        $this->postTransaction([
            'cash_box_id' => $this->defaultCashBox()->id,
            'currency_id' => $this->localCurrency()->id,
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'type' => 'invoice_payment',
            'direction' => 'in',
            'amount' => (float) $payment->amount,
            'transaction_date' => $payment->paid_at?->toDateString() ?? now()->toDateString(),
            'description' => $payment->notes,
            'entered_by' => $payment->received_by,
        ]);
    }

    public function reverseSourceTransactions(string $sourceType, int $sourceId, ?User $user = null, ?string $reason = null): void
    {
        $transactions = FinanceTransaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('type', 'not like', '%_reversal')
            ->get();

        foreach ($transactions as $transaction) {
            if (FinanceTransaction::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('type', $transaction->type.'_reversal')
                ->where('metadata->reversal_of', $transaction->id)
                ->exists()) {
                continue;
            }

            $this->postTransaction([
                'cash_box_id' => $transaction->cash_box_id,
                'currency_id' => $transaction->currency_id,
                'finance_category_id' => $transaction->finance_category_id,
                'activity_id' => $transaction->activity_id,
                'teacher_id' => $transaction->teacher_id,
                'finance_request_id' => $transaction->finance_request_id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'type' => $transaction->type.'_reversal',
                'direction' => $transaction->direction === 'out' ? 'in' : 'out',
                'amount' => (float) $transaction->amount,
                'transaction_date' => now()->toDateString(),
                'description' => $reason,
                'entered_by' => $user?->id,
                'metadata' => [
                    'reversal_of' => $transaction->id,
                    'reason' => $reason,
                ],
            ]);
        }
    }

    public function syncActivityTotals(?Activity $activity): void
    {
        if (! $activity) {
            return;
        }

        $expectedRevenue = ActivityRegistration::query()
            ->where('activity_id', $activity->id)
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->sum('fee_amount');

        $collectedRevenue = ActivityPayment::query()
            ->whereHas('registration', fn ($query) => $query->where('activity_id', $activity->id))
            ->whereNull('voided_at')
            ->sum('amount');

        $expenseTotal = ActivityExpense::query()
            ->where('activity_id', $activity->id)
            ->sum('amount');

        $requestRevenue = FinanceTransaction::query()
            ->where('activity_id', $activity->id)
            ->where('source_type', FinanceRequest::class)
            ->where('signed_amount', '>', 0)
            ->sum('signed_amount');

        $requestExpenses = abs((float) FinanceTransaction::query()
            ->where('activity_id', $activity->id)
            ->where('source_type', FinanceRequest::class)
            ->where('signed_amount', '<', 0)
            ->sum('signed_amount'));

        $activity->update([
            'expected_revenue_cached' => $expectedRevenue,
            'collected_revenue_cached' => (float) $collectedRevenue + (float) $requestRevenue,
            'expense_total_cached' => (float) $expenseTotal + $requestExpenses,
        ]);
    }

    public function syncInvoiceTotals(Invoice $invoice): void
    {
        $subtotal = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount');

        $discount = (float) $invoice->discount;
        $total = max($subtotal - $discount, 0);
        $paid = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->whereNull('voided_at')
            ->sum('amount');

        $invoice->update([
            'subtotal' => $subtotal,
            'total' => $total,
            'status' => $this->determineInvoiceStatus($invoice, $paid, $total),
        ]);

        if ($invoice->invoice_type === 'finance') {
            $request = $invoice->financeRequest()->with(['acceptedCurrency', 'postedTransaction'])->first();
            $transaction = $request?->postedTransaction;

            if ($request && $transaction && $request->acceptedCurrency && $request->status === FinanceRequest::STATUS_SETTLED) {
                $signedAmount = $transaction->direction === 'out' ? -$total : $total;
                $snapshot = $this->amountSnapshot($request->acceptedCurrency, $signedAmount);
                $transaction->update([
                    'amount' => abs($total),
                    'signed_amount' => $signedAmount,
                    'rate_to_base' => $snapshot['rate_to_base'],
                    'base_amount' => $snapshot['base_amount'],
                    'local_amount' => $snapshot['local_amount'],
                ]);
                $request->update(['accepted_amount' => $total]);
            }
        }
    }

    public function updateCurrencyRate(FinanceCurrency $currency, float $rateToBase, ?User $user = null, ?FinanceCurrency $referenceCurrency = null): FinanceCurrency
    {
        $currency->update([
            'rate_to_base' => $currency->is_base ? 1 : $rateToBase,
            'rate_reference_currency_id' => $currency->is_base ? null : $referenceCurrency?->id,
            'rate_updated_by' => $user?->id,
            'rate_updated_at' => now(),
        ]);

        return $currency->fresh();
    }

    protected function currencyReferenceQuoteAmount(FinanceCurrency $currency, ?FinanceCurrency $referenceCurrency = null): float
    {
        if ((float) $currency->rate_to_base <= 0) {
            return 1.0;
        }

        $referenceCurrency ??= $currency->rateReferenceCurrency ?: $this->baseCurrency();

        return (float) $referenceCurrency->rate_to_base / (float) $currency->rate_to_base;
    }

    protected function currentBalance(FinanceCashBox $cashBox, FinanceCurrency $currency): float
    {
        return round((float) FinanceTransaction::query()
            ->where('cash_box_id', $cashBox->id)
            ->where('currency_id', $currency->id)
            ->sum('signed_amount'), 2);
    }

    protected function createGeneratedRequestFromPull(FinanceRequest $pullRequest, string $type, float $amount, string $reason, ?User $user = null, ?Invoice $invoice = null): FinanceRequest
    {
        $currencyId = $pullRequest->accepted_currency_id ?: $pullRequest->requested_currency_id;

        return FinanceRequest::query()->create([
            'request_no' => $this->nextRequestNumber($type),
            'type' => $type,
            'status' => FinanceRequest::STATUS_PENDING,
            'requested_currency_id' => $currencyId,
            'requested_amount' => $amount,
            'accepted_currency_id' => $currencyId,
            'accepted_amount' => $amount,
            'cash_box_id' => $pullRequest->cash_box_id,
            'finance_category_id' => $this->defaultFinanceCategoryId($type),
            'teacher_id' => $pullRequest->teacher_id,
            'requested_by' => $pullRequest->requested_by ?: $user?->id,
            'reviewed_by' => $user?->id,
            'invoice_id' => $invoice?->id,
            'requested_reason' => $reason,
            'review_notes' => __('finance.descriptions.generated_from_pull', ['request' => $pullRequest->request_no]),
            'accepted_at' => now(),
        ]);
    }

    protected function defaultFinanceCategoryId(string $type): ?int
    {
        if ($type === FinanceRequest::TYPE_RETURN) {
            $returnCategoryId = FinanceCategory::query()
                ->where('is_active', true)
                ->where('type', FinanceRequest::TYPE_RETURN)
                ->where('code', 'return')
                ->value('id');

            if ($returnCategoryId) {
                return (int) $returnCategoryId;
            }
        }

        return FinanceCategory::query()
            ->where('is_active', true)
            ->where('type', $type)
            ->orderBy('name')
            ->value('id');
    }

    protected function markGeneratedRequestPosted(FinanceRequest $request, FinanceTransaction $transaction, ?User $user = null): void
    {
        $request->update([
            'accepted_at' => $request->accepted_at ?: now(),
            'posted_transaction_id' => $transaction->id,
            'reviewed_by' => $user?->id ?: $request->reviewed_by,
            'status' => FinanceRequest::STATUS_ACCEPTED,
        ]);
    }

    protected function financeRequestReference(FinanceRequest $request, ?Invoice $invoice = null): string
    {
        $invoice ??= $request->invoice;

        if ($request->type === FinanceRequest::TYPE_PULL && $request->pullRequestKind?->mode === 'invoice' && $invoice?->invoice_no) {
            return $invoice->invoice_no.' - '.$request->request_no;
        }

        return $request->request_no;
    }

    protected function ensureCashBoxSupportsCurrency(FinanceCashBox $cashBox, FinanceCurrency $currency): void
    {
        if ($cashBox->currencies()->whereKey($currency->id)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'currency_id' => __('finance.validation.cash_box_currency_mismatch'),
        ]);
    }

    protected function ensureNonNegativeBalance(FinanceCashBox $cashBox, FinanceCurrency $currency, float $signedAmount): void
    {
        if ($signedAmount >= 0) {
            return;
        }

        $available = $this->currentBalance($cashBox, $currency);

        if (round($available + $signedAmount, 2) >= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => __('finance.validation.insufficient_cash_box_balance', [
                'available' => $this->formatCurrencyAmount($available, $currency, false),
                'currency' => $currency->code,
                'cash_box' => $cashBox->name,
            ]),
        ]);
    }

    protected function ensureNonNegativeReplacementBalance(FinanceCashBox $cashBox, FinanceCurrency $currency, float $signedAmount, ?FinanceTransaction $replacedTransaction = null): void
    {
        if ($signedAmount >= 0) {
            return;
        }

        $available = $this->currentBalance($cashBox, $currency);

        if ($replacedTransaction
            && (int) $replacedTransaction->cash_box_id === (int) $cashBox->id
            && (int) $replacedTransaction->currency_id === (int) $currency->id) {
            $available -= (float) $replacedTransaction->signed_amount;
        }

        if (round($available + $signedAmount, 2) >= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'amount' => __('finance.validation.insufficient_cash_box_balance', [
                'available' => $this->formatCurrencyAmount($available, $currency, false),
                'currency' => $currency->code,
                'cash_box' => $cashBox->name,
            ]),
        ]);
    }

    protected function replaceExpenseTransactionAmount(FinanceRequest $request, float $amount, ?User $user = null): void
    {
        $transaction = $request->postedTransaction;

        if (! $transaction) {
            throw ValidationException::withMessages(['request' => __('finance.validation.missing_posted_transaction')]);
        }

        $currency = $transaction->currency()->firstOrFail();
        $cashBox = $transaction->cashBox()->firstOrFail();
        $signedAmount = -abs($amount);
        $snapshot = $this->amountSnapshot($currency, $signedAmount);

        $this->ensureNonNegativeReplacementBalance($cashBox, $currency, $signedAmount, $transaction);

        $transaction->update([
            'amount' => abs($amount),
            'signed_amount' => $signedAmount,
            'base_amount' => $snapshot['base_amount'],
            'local_amount' => $snapshot['local_amount'],
            'rate_to_base' => $snapshot['rate_to_base'],
            'entered_by' => $user?->id ?: $transaction->entered_by,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'finalised_at' => now()->toISOString(),
                'finalised_by' => $user?->id,
            ]),
        ]);
    }

    protected function amountSnapshot(FinanceCurrency $currency, float $signedAmount): array
    {
        $rateToBase = (float) $currency->rate_to_base;
        $baseAmount = round($signedAmount * $rateToBase, 2);
        $localRate = (float) $this->localCurrency()->rate_to_base;
        $localAmount = $localRate > 0 ? round($baseAmount / $localRate, 2) : $baseAmount;

        return [
            'rate_to_base' => $rateToBase,
            'base_amount' => $baseAmount,
            'local_amount' => $localAmount,
        ];
    }

    protected function determineInvoiceStatus(Invoice $invoice, float $paidAmount, float $invoiceTotal): string
    {
        if ($invoice->status === 'cancelled') {
            return 'cancelled';
        }

        if ($paidAmount <= 0) {
            return $invoice->status === 'draft' ? 'draft' : 'issued';
        }

        if ($paidAmount < $invoiceTotal) {
            return 'partial';
        }

        return 'paid';
    }

    protected function formatRateNumber(float $rate, int $maxDecimals): string
    {
        $decimals = $rate >= 1 ? min(4, $maxDecimals) : $maxDecimals;

        return rtrim(rtrim(number_format($rate, $decimals, '.', ','), '0'), '.');
    }

    protected function formatRateInputNumber(float $rate, int $maxDecimals): string
    {
        $decimals = $rate >= 1 ? min(4, $maxDecimals) : $maxDecimals;

        return rtrim(rtrim(number_format($rate, $decimals, '.', ','), '0'), '.');
    }

    protected function nextTransactionNumber(): string
    {
        $prefix = $this->numberingPrefix('transaction_prefix', 'TX');

        $lastTransactionNo = FinanceTransaction::query()
            ->where('transaction_no', 'like', $prefix.'-%')
            ->latest('id')
            ->value('transaction_no');

        return $this->sequencedNumber($prefix, $lastTransactionNo, 8);
    }

    protected function normalizeTransactionType(string $type, string $direction): string
    {
        if (str_contains($type, 'exchange')) {
            return 'exchange';
        }
        if (str_contains($type, 'transfer')) {
            return 'transfer';
        }
        if (str_contains($type, 'return')) {
            return 'return';
        }

        return $direction === 'out' ? 'expense' : 'income';
    }

    protected function cleanTransactionDescription(?string $description): ?string
    {
        $description = trim((string) $description);
        $description = (string) preg_replace('/\b(?:FIN|EXP|REV|RET|PUL|INV|TRSF|EXCH|EXC|TX)[-_]?\d+\b/iu', '', $description);
        $description = trim((string) preg_replace('/\s{2,}/u', ' ', $description), " -|,.;:\t\n\r\0\x0B");

        return $description !== '' ? $description : null;
    }

    protected function requestNumberPrefix(string $type): string
    {
        return match ($type) {
            FinanceRequest::TYPE_EXPENSE => $this->numberingPrefix('expense_request_prefix', 'EXP'),
            FinanceRequest::TYPE_REVENUE => $this->numberingPrefix('revenue_request_prefix', 'REV'),
            FinanceRequest::TYPE_RETURN => $this->numberingPrefix('return_request_prefix', 'RET'),
            default => $this->numberingPrefix('pull_request_prefix', 'PUL'),
        };
    }

    protected function numberingPrefix(string $settingKey, string $default): string
    {
        return $this->normalizeNumberPrefix(
            DB::table('app_settings')
                ->where('group', 'finance')
                ->where('key', $settingKey)
                ->value('value'),
            $default,
        );
    }

    protected function normalizeNumberPrefix(?string $value, string $default): string
    {
        $normalized = Str::upper(trim((string) preg_replace('/[\s-]+/u', '', (string) $value)));

        return $normalized !== '' ? $normalized : $default;
    }

    protected function sequencedNumber(string $prefix, ?string $lastValue, int $padding): string
    {
        $nextSequence = 1;

        if ($lastValue && preg_match('/(\d+)$/', $lastValue, $matches) === 1) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%0'.$padding.'d', $prefix, $nextSequence);
    }
}
