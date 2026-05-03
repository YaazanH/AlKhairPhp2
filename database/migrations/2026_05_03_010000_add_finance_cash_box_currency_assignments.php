<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cash_box_currency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_cash_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_currency_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['finance_cash_box_id', 'finance_currency_id'], 'finance_cash_box_currency_unique');
        });

        $now = now();
        $cashBoxIds = DB::table('finance_cash_boxes')->pluck('id');
        $currencyIds = DB::table('finance_currencies')->pluck('id');
        $rows = [];

        foreach ($cashBoxIds as $cashBoxId) {
            foreach ($currencyIds as $currencyId) {
                $rows[] = [
                    'finance_cash_box_id' => $cashBoxId,
                    'finance_currency_id' => $currencyId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('finance_cash_box_currency')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_box_currency');
    }
};
