<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $configuredPrefix = DB::table('app_settings')
            ->where('group', 'finance')
            ->where('key', 'expense_request_prefix')
            ->value('value');
        $prefix = strtoupper(trim((string) preg_replace('/[\s-]+/u', '', (string) ($configuredPrefix ?: 'EXP')))) ?: 'EXP';

        DB::table('finance_requests')
            ->whereIn('type', ['pull', 'expense'])
            ->where('status', 'accepted')
            ->update([
                'status' => 'settled',
                'settled_at' => DB::raw('COALESCE(settled_at, accepted_at, updated_at, created_at)'),
                'settled_by' => DB::raw('COALESCE(settled_by, reviewed_by)'),
            ]);

        $sequence = DB::table('finance_requests')
            ->where('expense_no', 'like', $prefix.'-%')
            ->pluck('expense_no')
            ->map(function (?string $number): int {
                return preg_match('/(\d+)$/', (string) $number, $matches) === 1 ? (int) $matches[1] : 0;
            })
            ->max() ?? 0;

        DB::table('finance_requests')
            ->whereIn('type', ['pull', 'expense'])
            ->whereIn('status', ['accepted', 'settled'])
            ->where(function ($query) use ($prefix): void {
                $query->whereNull('expense_no')->orWhere('expense_no', 'not like', $prefix.'-%');
            })
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $request) use (&$sequence, $prefix): void {
                $expenseNumber = sprintf('%s-%06d', $prefix, ++$sequence);

                DB::table('finance_requests')->where('id', $request->id)->update([
                    'expense_no' => $expenseNumber,
                ]);
                DB::table('finance_transactions')
                    ->where('finance_request_id', $request->id)
                    ->where('direction', 'out')
                    ->update(['special_transaction_no' => $expenseNumber]);
            });
    }

    public function down(): void
    {
        // Finalisation and workflow identifiers are retained as audit corrections.
    }
};
