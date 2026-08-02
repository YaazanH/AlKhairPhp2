<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_transactions')
            ->join('finance_requests', 'finance_requests.id', '=', 'finance_transactions.finance_request_id')
            ->whereNull('finance_transactions.special_transaction_no')
            ->whereNull('finance_transactions.deleted_at')
            ->select([
                'finance_transactions.id',
                'finance_requests.expense_no',
                'finance_requests.request_no',
            ])
            ->orderBy('finance_transactions.id')
            ->get()
            ->each(function (object $row): void {
                DB::table('finance_transactions')->where('id', $row->id)->update([
                    'special_transaction_no' => $row->expense_no ?: $row->request_no,
                ]);
            });
    }

    public function down(): void
    {
        // These values are workflow identifiers and remain useful audit data on rollback.
    }
};
