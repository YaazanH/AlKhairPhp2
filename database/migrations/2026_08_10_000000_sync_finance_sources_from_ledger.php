<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $requestSource = 'App\\Models\\FinanceRequest';
            $exchangeSource = 'App\\Models\\FinanceCurrencyExchange';
            $transferSource = 'App\\Models\\FinanceCashBoxTransfer';

            DB::table('finance_transactions')
                ->whereNull('deleted_at')
                ->whereNull('finance_request_id')
                ->whereNotNull('special_transaction_no')
                ->orderBy('id')
                ->get()
                ->each(function (object $transaction) use ($requestSource): void {
                    $requestId = DB::table('finance_requests')
                        ->where('request_no', $transaction->special_transaction_no)
                        ->orWhere('expense_no', $transaction->special_transaction_no)
                        ->value('id');
                    if ($requestId) {
                        DB::table('finance_transactions')->where('id', $transaction->id)->update([
                            'finance_request_id' => $requestId,
                            'source_id' => $requestId,
                            'source_type' => $requestSource,
                            'updated_at' => now(),
                        ]);
                    }
                });

            foreach ([
                'exchange' => ['table' => 'finance_currency_exchanges', 'number' => 'exchange_no', 'source' => $exchangeSource],
                'transfer' => ['table' => 'finance_cash_box_transfers', 'number' => 'transfer_no', 'source' => $transferSource],
            ] as $type => $source) {
                DB::table('finance_transactions')
                    ->whereNull('deleted_at')
                    ->where('type', $type)
                    ->whereNull('source_id')
                    ->whereNotNull('special_transaction_no')
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $transaction) use ($source): void {
                        $sourceRecord = DB::table($source['table'])->where($source['number'], $transaction->special_transaction_no)->first();
                        if ($sourceRecord) {
                            DB::table('finance_transactions')->where('id', $transaction->id)->update([
                                'pair_uuid' => $sourceRecord->pair_uuid,
                                'source_id' => $sourceRecord->id,
                                'source_type' => $source['source'],
                                'updated_at' => now(),
                            ]);
                        }
                    });
            }

            DB::table('finance_transactions')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($requestSource) {
                    $query->where('source_type', $requestSource)->orWhereNotNull('finance_request_id');
                })
                ->orderBy('id')
                ->get()
                ->each(function (object $transaction) use ($requestSource): void {
                    $requestId = $transaction->finance_request_id
                        ?: ($transaction->source_type === $requestSource ? $transaction->source_id : null);
                    if (! $requestId) {
                        return;
                    }

                    $request = DB::table('finance_requests')->where('id', $requestId)->first();
                    if (! $request) {
                        return;
                    }

                    $updates = [
                        'cash_box_id' => $transaction->cash_box_id,
                        'finance_category_id' => $transaction->finance_category_id,
                        'posted_transaction_id' => $request->posted_transaction_id ?: $transaction->id,
                        'requested_amount' => $transaction->amount,
                        'requested_currency_id' => $transaction->currency_id,
                        'requested_reason' => $transaction->description,
                        'updated_at' => now(),
                    ];

                    if ($request->accepted_amount !== null) {
                        $updates['accepted_amount'] = $transaction->amount;
                    }
                    if ($request->accepted_currency_id) {
                        $updates['accepted_currency_id'] = $transaction->currency_id;
                    }

                    if (in_array($request->type, ['pull', 'expense'], true)) {
                        if ($transaction->special_transaction_no) {
                            $updates['expense_no'] = $transaction->special_transaction_no;
                        }
                    } elseif ($transaction->special_transaction_no) {
                        $updates['request_no'] = $transaction->special_transaction_no;
                    }

                    DB::table('finance_requests')->where('id', $requestId)->update($updates);
                });

            DB::table('finance_currency_exchanges')->orderBy('id')->get()->each(function (object $exchange) use ($exchangeSource): void {
                $transactions = DB::table('finance_transactions')
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($exchange, $exchangeSource) {
                        $query->where(function ($sourceQuery) use ($exchange, $exchangeSource) {
                            $sourceQuery->where('source_type', $exchangeSource)->where('source_id', $exchange->id);
                        });
                        if ($exchange->pair_uuid) {
                            $query->orWhere('pair_uuid', $exchange->pair_uuid);
                        }
                    })
                    ->orderBy('id')
                    ->get();
                $out = $transactions->firstWhere('direction', 'out');
                $in = $transactions->firstWhere('direction', 'in');
                if (! $out && ! $in) {
                    return;
                }

                $reference = $out?->special_transaction_no ?: $in?->special_transaction_no ?: $exchange->exchange_no;
                $notes = trim((string) ($out?->description ?: $in?->description ?: $exchange->notes));
                $notes = trim((string) preg_replace('/^\[(?:داخل|خارج)\]\s*/u', '', $notes));

                DB::table('finance_currency_exchanges')->where('id', $exchange->id)->update([
                    'exchange_no' => $reference,
                    'exchange_date' => $out?->transaction_date ?: $in?->transaction_date ?: $exchange->exchange_date,
                    'from_amount' => $out?->amount ?: $exchange->from_amount,
                    'from_cash_box_id' => $out?->cash_box_id ?: $exchange->from_cash_box_id,
                    'from_currency_id' => $out?->currency_id ?: $exchange->from_currency_id,
                    'from_rate_to_base' => $out?->rate_to_base ?: $exchange->from_rate_to_base,
                    'to_amount' => $in?->amount ?: $exchange->to_amount,
                    'to_cash_box_id' => $in?->cash_box_id ?: $exchange->to_cash_box_id,
                    'to_currency_id' => $in?->currency_id ?: $exchange->to_currency_id,
                    'to_rate_to_base' => $in?->rate_to_base ?: $exchange->to_rate_to_base,
                    'notes' => $notes !== '' ? $notes : null,
                    'updated_at' => now(),
                ]);

                foreach ($transactions as $transaction) {
                    $metadata = json_decode((string) $transaction->metadata, true);
                    $metadata = is_array($metadata) ? $metadata : [];
                    $metadata['exchange_no'] = $reference;
                    $metadata['reference'] = $reference;
                    DB::table('finance_transactions')->where('id', $transaction->id)->update([
                        'description' => ($transaction->direction === 'out' ? '[خارج]' : '[داخل]').($notes !== '' ? ' '.$notes : ''),
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'special_transaction_no' => $reference,
                        'updated_at' => now(),
                    ]);
                }
            });

            DB::table('finance_cash_box_transfers')->orderBy('id')->get()->each(function (object $transfer) use ($transferSource): void {
                $transactions = DB::table('finance_transactions')
                    ->whereNull('deleted_at')
                    ->where(function ($query) use ($transfer, $transferSource) {
                        $query->where(function ($sourceQuery) use ($transfer, $transferSource) {
                            $sourceQuery->where('source_type', $transferSource)->where('source_id', $transfer->id);
                        });
                        if ($transfer->pair_uuid) {
                            $query->orWhere('pair_uuid', $transfer->pair_uuid);
                        }
                    })
                    ->orderBy('id')
                    ->get();
                $out = $transactions->firstWhere('direction', 'out');
                $in = $transactions->firstWhere('direction', 'in');
                if (! $out && ! $in) {
                    return;
                }

                $base = $out ?: $in;
                DB::table('finance_cash_box_transfers')->where('id', $transfer->id)->update([
                    'amount' => $base->amount,
                    'currency_id' => $base->currency_id,
                    'from_cash_box_id' => $out?->cash_box_id ?: $transfer->from_cash_box_id,
                    'notes' => $base->description,
                    'to_cash_box_id' => $in?->cash_box_id ?: $transfer->to_cash_box_id,
                    'transfer_date' => $base->transaction_date,
                    'transfer_no' => $base->special_transaction_no ?: $transfer->transfer_no,
                    'updated_at' => now(),
                ]);
            });
        });
    }

    public function down(): void
    {
        // The ledger is the source of truth, so synchronized historical values are not reversible.
    }
};
