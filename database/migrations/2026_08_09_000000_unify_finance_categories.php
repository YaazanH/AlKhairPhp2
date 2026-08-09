<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categoryMap = [];

        DB::table('finance_pull_request_kinds')->orderBy('id')->get()->each(function (object $kind) use (&$categoryMap): void {
            $category = DB::table('finance_categories')
                ->where('code', $kind->code)
                ->where('type', 'expense')
                ->first();

            $categoryId = $category?->id ?: DB::table('finance_categories')->insertGetId([
                'name' => $kind->name,
                'code' => DB::table('finance_categories')->where('code', $kind->code)->exists()
                    ? 'expense-'.$kind->code
                    : $kind->code,
                'type' => 'expense',
                'mode' => in_array($kind->mode, ['count', 'invoice'], true) ? $kind->mode : 'count',
                'is_donation' => false,
                'is_active' => (bool) $kind->is_active,
                'created_at' => $kind->created_at ?: now(),
                'updated_at' => now(),
            ]);

            $categoryMap[(int) $kind->id] = (int) $categoryId;
        });

        foreach ($categoryMap as $oldId => $categoryId) {
            DB::table('finance_requests')
                ->where('finance_pull_request_kind_id', $oldId)
                ->update([
                    'finance_pull_request_kind_id' => $categoryId,
                    'finance_category_id' => $categoryId,
                ]);

            DB::table('app_settings')
                ->where('group', 'finance')
                ->where('key', 'default_pull_request_kind_id')
                ->where('value', (string) $oldId)
                ->update(['value' => (string) $categoryId, 'updated_at' => now()]);
        }

        DB::table('finance_categories')->where('code', 'currency-exchange')->update(['type' => 'exchange', 'mode' => 'exchange']);
        DB::table('finance_categories')->where('code', 'fund-transfer')->update(['type' => 'transfer', 'mode' => 'transfer']);

        DB::table('finance_transactions')->orderBy('id')->get()->each(function (object $transaction): void {
            $description = trim((string) $transaction->description);
            $description = (string) preg_replace('/\b(?:FIN|EXP|REV|RET|PUL|INV|TRSF|EXCH|EXC|TX)[-_]?\d+\b/iu', '', $description);
            $description = trim((string) preg_replace('/\s{2,}/u', ' ', $description), " -|\t\n\r\0\x0B");

            if ($description === '' && $transaction->finance_request_id) {
                $description = trim((string) DB::table('finance_requests')
                    ->where('id', $transaction->finance_request_id)
                    ->value('requested_reason'));
            }

            DB::table('finance_transactions')->where('id', $transaction->id)->update([
                'description' => $description !== '' ? $description : null,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // The legacy table is intentionally retained, so rolling back application
        // code does not destroy category definitions.
    }
};
