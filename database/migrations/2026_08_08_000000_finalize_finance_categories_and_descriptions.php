<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $categoryIds = [];

        foreach ([
            'fund-transfer' => ['name' => 'تحويل بين الصناديق', 'type' => 'expense', 'mode' => 'count'],
            'currency-exchange' => ['name' => 'تصريف عملات', 'type' => 'expense', 'mode' => 'count'],
        ] as $code => $attributes) {
            $existing = DB::table('finance_categories')->where('code', $code)->first();
            if ($existing) {
                DB::table('finance_categories')->where('id', $existing->id)->update($attributes + ['is_active' => true, 'updated_at' => $now]);
                $categoryIds[$code] = $existing->id;
            } else {
                $categoryIds[$code] = DB::table('finance_categories')->insertGetId($attributes + [
                    'code' => $code,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('finance_transactions')->where('type', 'transfer')->update(['finance_category_id' => $categoryIds['fund-transfer']]);
        DB::table('finance_transactions')->where('type', 'exchange')->update(['finance_category_id' => $categoryIds['currency-exchange']]);

        DB::table('finance_transactions')->orderBy('id')->get(['id', 'type', 'description', 'source_type', 'source_id'])->each(function (object $transaction): void {
            $description = trim((string) $transaction->description);
            $description = trim((string) preg_replace('/\b(?:FIN|EXP|REV|RET|PUL|INV|TRSF|EXCH|TXN)[-_]?\d+\b/iu', '', $description));
            $description = trim((string) preg_replace('/\s{2,}/u', ' ', $description), " -–—|\t\n\r\0\x0B");

            if ($transaction->type === 'return') {
                $description = 'إرجاع متبق من فاتورة';
            } elseif ($description === '' || preg_match('/^\d+$/', $description)) {
                $description = $transaction->source_type === 'App\\Models\\FinanceRequest' && $transaction->source_id
                    ? trim((string) DB::table('finance_requests')->where('id', $transaction->source_id)->value('requested_reason'))
                    : '';
            }

            DB::table('finance_transactions')->where('id', $transaction->id)->update([
                'description' => $description !== '' ? $description : null,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
    }
};
