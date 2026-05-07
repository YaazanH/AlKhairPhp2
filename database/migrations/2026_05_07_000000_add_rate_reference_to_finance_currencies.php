<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_currencies', function (Blueprint $table) {
            if (! Schema::hasColumn('finance_currencies', 'rate_reference_currency_id')) {
                $table->foreignId('rate_reference_currency_id')
                    ->nullable()
                    ->after('rate_to_base')
                    ->constrained('finance_currencies')
                    ->nullOnDelete();
            }
        });

        $baseCurrencyId = DB::table('finance_currencies')
            ->where('is_base', true)
            ->value('id');

        if ($baseCurrencyId) {
            DB::table('finance_currencies')
                ->where('id', '!=', $baseCurrencyId)
                ->whereNull('rate_reference_currency_id')
                ->update(['rate_reference_currency_id' => $baseCurrencyId]);
        }
    }

    public function down(): void
    {
        Schema::table('finance_currencies', function (Blueprint $table) {
            if (Schema::hasColumn('finance_currencies', 'rate_reference_currency_id')) {
                $table->dropConstrainedForeignId('rate_reference_currency_id');
            }
        });
    }
};
