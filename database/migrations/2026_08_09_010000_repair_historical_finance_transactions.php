<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categoriesByCode = DB::table('finance_categories')->pluck('id', 'code');

        DB::table('finance_transactions')->orderBy('id')->get()->each(function (object $transaction) use ($categoriesByCode): void {
            $requestId = $transaction->finance_request_id;
            if (! $requestId && $transaction->source_type === 'App\\Models\\FinanceRequest') {
                $requestId = $transaction->source_id;
            }

            $request = $requestId
                ? DB::table('finance_requests')->where('id', $requestId)->first()
                : null;

            $metadata = json_decode((string) $transaction->metadata, true);
            $categoryId = $transaction->finance_category_id ?: $request?->finance_category_id;
            if (! $categoryId && is_array($metadata) && filled($metadata['pull_kind'] ?? null)) {
                $categoryId = $categoriesByCode[$metadata['pull_kind']] ?? null;
            }
            if (! $categoryId && $transaction->type === 'exchange') {
                $categoryId = $categoriesByCode['currency-exchange'] ?? null;
            }
            if (! $categoryId && $transaction->type === 'transfer') {
                $categoryId = $categoriesByCode['fund-transfer'] ?? null;
            }

            $references = array_filter([
                $transaction->special_transaction_no,
                $request?->expense_no,
                $request?->request_no,
            ]);
            foreach (['reference', 'exchange_no', 'transfer_no', 'parent_pull_request_no'] as $key) {
                if (is_array($metadata) && filled($metadata[$key] ?? null)) {
                    $references[] = $metadata[$key];
                }
            }

            $clean = static function (?string $value) use ($references): ?string {
                $value = trim((string) $value);
                foreach (array_unique(array_map(static fn ($reference) => trim((string) $reference), $references)) as $reference) {
                    if ($reference !== '') {
                        $value = str_ireplace($reference, '', $value);
                    }
                }
                $value = (string) preg_replace('/\b(?:FIN|EXP|REV|RET|PUL|INV|TRSF|EXCH|EXC|TX|DBIT|CRDT|RTRN|XCHG)[-_]?\d+\b/iu', '', $value);
                $value = trim((string) preg_replace('/\s{2,}/u', ' ', $value), " -|,.;:\t\n\r\0\x0B");

                return $value !== '' && ! preg_match('/^[\p{P}\p{S}\s]+$/u', $value) ? $value : null;
            };

            $description = $clean($transaction->description);
            if ($description === null && $transaction->type !== 'exchange') {
                $description = $clean($request?->requested_reason);
            }

            DB::table('finance_transactions')->where('id', $transaction->id)->update([
                'finance_category_id' => $categoryId,
                'description' => $description,
                'updated_at' => now(),
            ]);
        });

        $prefix = strtoupper((string) DB::table('app_settings')
            ->where('group', 'finance')
            ->where('key', 'transaction_prefix')
            ->value('value'));
        $prefix = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $prefix), '-');
        $prefix = $prefix !== '' ? $prefix : 'TX';

        $transactionIds = DB::table('finance_transactions')->orderBy('id')->pluck('id');
        foreach ($transactionIds as $id) {
            DB::table('finance_transactions')->where('id', $id)->update([
                'transaction_no' => '__RENUMBER__'.$id,
            ]);
        }
        foreach ($transactionIds->values() as $index => $id) {
            DB::table('finance_transactions')->where('id', $id)->update([
                'transaction_no' => sprintf('%s-%08d', $prefix, $index + 1),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Historical descriptions, category links, and public numbers cannot be
        // reconstructed reliably once normalized.
    }
};
