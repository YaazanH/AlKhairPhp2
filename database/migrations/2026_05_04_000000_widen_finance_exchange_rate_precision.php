<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_currencies', function (Blueprint $table) {
            $table->decimal('rate_to_base', 24, 12)->default(1)->change();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->decimal('rate_to_base', 24, 12)->change();
        });

        Schema::table('finance_currency_exchanges', function (Blueprint $table) {
            $table->decimal('from_rate_to_base', 24, 12)->change();
            $table->decimal('to_rate_to_base', 24, 12)->change();
        });
    }

    public function down(): void
    {
        Schema::table('finance_currency_exchanges', function (Blueprint $table) {
            $table->decimal('from_rate_to_base', 18, 8)->change();
            $table->decimal('to_rate_to_base', 18, 8)->change();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->decimal('rate_to_base', 18, 8)->change();
        });

        Schema::table('finance_currencies', function (Blueprint $table) {
            $table->decimal('rate_to_base', 18, 8)->default(1)->change();
        });
    }
};
