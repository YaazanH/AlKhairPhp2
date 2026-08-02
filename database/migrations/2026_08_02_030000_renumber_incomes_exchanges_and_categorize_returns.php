<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->categorizeReturns();
            $this->renumberIncomes();
            $this->renumberExchanges();
        });
    }

    public function down(): void
    {
        // Financial identifiers and category corrections are retained for audit continuity.
    }

    protected function categorizeReturns(): void
    {
        $now = now();
        $returnCategoryId = DB::table('finance_categories')
            ->where('code', 'return')
            ->value('id');

        if ($returnCategoryId) {
            DB::table('finance_categories')
                ->where('id', $returnCategoryId)
                ->update([
                    'name' => 'إرجاع',
                    'type' => 'return',
                    'is_active' => true,
                    'is_donation' => false,
                    'updated_at' => $now,
                ]);
        } else {
            $returnCategoryId = DB::table('finance_categories')->insertGetId([
                'name' => 'إرجاع',
                'code' => 'return',
                'type' => 'return',
                'is_active' => true,
                'is_donation' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $uncategorizedReturnRequestIds = DB::table('finance_requests')
            ->where('type', 'return')
            ->whereNull('finance_category_id')
            ->pluck('id');

        DB::table('finance_requests')
            ->whereIn('id', $uncategorizedReturnRequestIds)
            ->update(['finance_category_id' => $returnCategoryId]);

        DB::table('finance_transactions')
            ->whereNull('finance_category_id')
            ->where(function ($query) use ($uncategorizedReturnRequestIds): void {
                $query
                    ->whereIn('finance_request_id', $uncategorizedReturnRequestIds)
                    ->orWhereIn('type', ['return_request', 'pull_request_return', 'invoice_pull_return']);
            })
            ->update(['finance_category_id' => $returnCategoryId]);
    }

    protected function renumberIncomes(): void
    {
        $prefixes = [
            'revenue' => $this->configuredPrefix('revenue_request_prefix', 'REV'),
            'return' => $this->configuredPrefix('return_request_prefix', 'RET'),
        ];
        $requests = DB::table('finance_requests')
            ->whereIn('type', array_keys($prefixes))
            ->orderBy('id')
            ->get(['id', 'type']);

        foreach ($requests as $request) {
            DB::table('finance_requests')
                ->where('id', $request->id)
                ->update(['request_no' => '__TMP_INCOME_20260802_'.$request->id]);
        }

        $sequences = [];

        foreach ($requests as $request) {
            $prefix = $prefixes[$request->type];
            $sequences[$prefix] = $sequences[$prefix] ?? 0;

            do {
                $incomeNumber = sprintf('%s-%06d', $prefix, ++$sequences[$prefix]);
            } while (DB::table('finance_requests')->where('request_no', $incomeNumber)->exists());

            DB::table('finance_requests')
                ->where('id', $request->id)
                ->update(['request_no' => $incomeNumber]);

            DB::table('finance_transactions')
                ->where('finance_request_id', $request->id)
                ->update(['special_transaction_no' => $incomeNumber]);
        }
    }

    protected function renumberExchanges(): void
    {
        $prefix = $this->configuredPrefix('exchange_prefix', 'EXC');
        $exchanges = DB::table('finance_currency_exchanges')
            ->orderBy('id')
            ->get(['id', 'pair_uuid']);

        foreach ($exchanges as $exchange) {
            DB::table('finance_currency_exchanges')
                ->where('id', $exchange->id)
                ->update(['exchange_no' => '__TMP_EXCHANGE_20260802_'.$exchange->id]);
        }

        foreach ($exchanges as $index => $exchange) {
            $exchangeNumber = sprintf('%s-%06d', $prefix, $index + 1);

            DB::table('finance_currency_exchanges')
                ->where('id', $exchange->id)
                ->update(['exchange_no' => $exchangeNumber]);

            DB::table('finance_transactions')
                ->where('pair_uuid', $exchange->pair_uuid)
                ->where('type', 'currency_exchange')
                ->get(['id', 'metadata'])
                ->each(function (object $transaction) use ($exchangeNumber): void {
                    $metadata = json_decode((string) $transaction->metadata, true);

                    if (is_array($metadata)) {
                        $metadata['exchange_no'] = $exchangeNumber;
                        $metadata['reference'] = $exchangeNumber;
                    }

                    DB::table('finance_transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'special_transaction_no' => $exchangeNumber,
                            'metadata' => is_array($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $transaction->metadata,
                        ]);
                });
        }
    }

    protected function configuredPrefix(string $key, string $default): string
    {
        $configured = DB::table('app_settings')
            ->where('group', 'finance')
            ->where('key', $key)
            ->value('value');
        $normalized = strtoupper(trim((string) preg_replace('/[\s-]+/u', '', (string) ($configured ?: $default))));

        return $normalized !== '' ? $normalized : $default;
    }
};
