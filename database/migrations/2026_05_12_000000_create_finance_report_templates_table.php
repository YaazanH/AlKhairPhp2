<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_report_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->text('subtitle')->nullable();
            $table->string('language', 20)->default('both');
            $table->boolean('is_default')->default(false);
            $table->boolean('include_exported_at')->default(true);
            $table->boolean('include_opening_balance')->default(true);
            $table->boolean('include_closing_balance')->default(true);
            $table->json('columns');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('finance_report_templates')->insert([
            'name' => 'Default ledger report',
            'title' => 'Finance Ledger Report',
            'subtitle' => 'Cash box ledger by selected currency and date range.',
            'language' => 'both',
            'is_default' => true,
            'include_exported_at' => true,
            'include_opening_balance' => true,
            'include_closing_balance' => true,
            'columns' => json_encode([
                'transaction_date',
                'transaction_no',
                'description',
                'type',
                'category',
                'income',
                'expense',
                'running_balance',
                'entered_by',
                'reference',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_report_templates');
    }
};
