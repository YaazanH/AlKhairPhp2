<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categoryId = DB::table('finance_categories')->where('type', 'exchange')
            ->where('is_active', true)->orderBy('name')->value('id');

        if ($categoryId) {
            DB::table('finance_transactions')->whereIn('type', ['exchange', 'currency_exchange'])
                ->whereNull('finance_category_id')->update(['finance_category_id' => $categoryId]);
        }
    }

    public function down(): void
    {
        // Keep valid category links; they cannot be distinguished from later edits.
    }
};
