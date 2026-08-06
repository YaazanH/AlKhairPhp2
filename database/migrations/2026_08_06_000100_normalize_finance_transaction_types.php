<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('finance_transactions')->orderBy('id')->get(['id', 'type', 'direction'])->each(function (object $transaction): void {
            $type = str_contains($transaction->type, 'exchange') ? 'exchange'
                : (str_contains($transaction->type, 'transfer') ? 'transfer'
                : (str_contains($transaction->type, 'return') ? 'return'
                : ($transaction->direction === 'out' ? 'expense' : 'income')));
            DB::table('finance_transactions')->where('id', $transaction->id)->update(['type' => $type]);
        });
    }

    public function down(): void {}
};
