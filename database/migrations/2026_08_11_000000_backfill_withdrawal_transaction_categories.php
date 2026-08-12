<?php

use App\Models\FinanceRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_requests')
            ->where('type', FinanceRequest::TYPE_PULL)
            ->whereNotNull('finance_category_id')
            ->orderBy('id')
            ->chunkById(250, function ($requests): void {
                foreach ($requests as $request) {
                    DB::table('finance_transactions')
                        ->where(function ($query) use ($request): void {
                            $query->where('finance_request_id', $request->id)
                                ->orWhere(function ($sourceQuery) use ($request): void {
                                    $sourceQuery->where('source_type', FinanceRequest::class)
                                        ->where('source_id', $request->id);
                                });
                        })
                        ->update(['finance_category_id' => $request->finance_category_id]);
                }
            });
    }

    public function down(): void
    {
        // Historical category restoration is intentionally not reversible.
    }
};
