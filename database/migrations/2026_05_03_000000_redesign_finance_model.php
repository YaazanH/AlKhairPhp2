<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            if (! Schema::hasColumn('activities', 'status')) {
                $table->string('status', 20)->default('active')->after('is_active');
            }
        });

        DB::table('activities')
            ->where('is_active', true)
            ->update(['status' => 'active']);

        DB::table('activities')
            ->where('is_active', false)
            ->update(['status' => 'finished']);

        Schema::create('finance_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('symbol', 20)->nullable();
            $table->decimal('rate_to_base', 18, 8)->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_local')->default(false);
            $table->boolean('is_base')->default(false);
            $table->foreignId('rate_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rate_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_local']);
            $table->index(['is_active', 'is_base']);
        });

        Schema::create('finance_cash_boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_cash_box_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_cash_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['finance_cash_box_id', 'user_id'], 'finance_cash_box_user_unique');
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('type', 20)->default('expense');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('finance_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no')->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_currency_id')->nullable()->constrained('finance_currencies')->nullOnDelete();
            $table->decimal('requested_amount', 14, 2);
            $table->foreignId('accepted_currency_id')->nullable()->constrained('finance_currencies')->nullOnDelete();
            $table->decimal('accepted_amount', 14, 2)->nullable();
            $table->foreignId('cash_box_id')->nullable()->constrained('finance_cash_boxes')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('posted_transaction_id')->nullable();
            $table->text('requested_reason')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('terms_snapshot')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->foreignId('terms_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['activity_id', 'type']);
            $table->index(['teacher_id', 'type']);
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no')->unique();
            $table->foreignId('cash_box_id')->constrained('finance_cash_boxes')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('finance_currencies')->restrictOnDelete();
            $table->foreignId('finance_category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_request_id')->nullable()->constrained('finance_requests')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('type', 50);
            $table->string('direction', 10);
            $table->decimal('amount', 14, 2);
            $table->decimal('signed_amount', 14, 2);
            $table->decimal('rate_to_base', 18, 8);
            $table->decimal('base_amount', 14, 2);
            $table->decimal('local_amount', 14, 2);
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('pair_uuid')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['cash_box_id', 'currency_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['transaction_date', 'type']);
            $table->index('pair_uuid');
        });

        Schema::create('finance_request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('finance_currency_exchanges', function (Blueprint $table) {
            $table->id();
            $table->uuid('pair_uuid')->unique();
            $table->foreignId('from_cash_box_id')->constrained('finance_cash_boxes')->restrictOnDelete();
            $table->foreignId('to_cash_box_id')->constrained('finance_cash_boxes')->restrictOnDelete();
            $table->foreignId('from_currency_id')->constrained('finance_currencies')->restrictOnDelete();
            $table->foreignId('to_currency_id')->constrained('finance_currencies')->restrictOnDelete();
            $table->decimal('from_amount', 14, 2);
            $table->decimal('to_amount', 14, 2);
            $table->decimal('from_rate_to_base', 18, 8);
            $table->decimal('to_rate_to_base', 18, 8);
            $table->decimal('base_amount', 14, 2);
            $table->decimal('local_amount', 14, 2);
            $table->date('exchange_date');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_cash_box_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('pair_uuid')->unique();
            $table->foreignId('from_cash_box_id')->constrained('finance_cash_boxes')->restrictOnDelete();
            $table->foreignId('to_cash_box_id')->constrained('finance_cash_boxes')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('finance_currencies')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('transfer_date');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->seedDefaults();
        $this->backfillOldFinance();
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cash_box_transfers');
        Schema::dropIfExists('finance_currency_exchanges');
        Schema::dropIfExists('finance_request_attachments');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_requests');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_cash_box_user');
        Schema::dropIfExists('finance_cash_boxes');
        Schema::dropIfExists('finance_currencies');

        Schema::table('activities', function (Blueprint $table) {
            if (Schema::hasColumn('activities', 'status')) {
                $table->dropColumn('status');
            }
        });
    }

    protected function seedDefaults(): void
    {
        $now = now();

        $baseId = DB::table('finance_currencies')->insertGetId([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate_to_base' => 1,
            'is_active' => true,
            'is_local' => false,
            'is_base' => true,
            'rate_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $localId = DB::table('finance_currencies')->insertGetId([
            'code' => 'SYP',
            'name' => 'Syrian Pound',
            'symbol' => 'SYP',
            'rate_to_base' => 0.00008130,
            'is_active' => true,
            'is_local' => true,
            'is_base' => false,
            'rate_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cashBoxId = DB::table('finance_cash_boxes')->insertGetId([
            'name' => 'Main Cash Box',
            'code' => 'main',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('app_settings')->updateOrInsert(
            ['group' => 'finance', 'key' => 'default_cash_box_id'],
            ['value' => (string) $cashBoxId, 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
        );

        DB::table('app_settings')->updateOrInsert(
            ['group' => 'finance', 'key' => 'request_terms'],
            ['value' => null, 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
        );

        DB::table('finance_categories')->insert([
            ['name' => 'Activity expense', 'code' => 'activity_expense', 'type' => 'expense', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Management expense', 'code' => 'management_expense', 'type' => 'management', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Activity return', 'code' => 'activity_return', 'type' => 'return', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'General revenue', 'code' => 'general_revenue', 'type' => 'revenue', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Keep variables referenced so static analyzers do not flag seed intent.
        unset($baseId, $localId);
    }

    protected function backfillOldFinance(): void
    {
        $localCurrency = DB::table('finance_currencies')->where('is_local', true)->first();
        $cashBox = DB::table('finance_cash_boxes')->where('code', 'main')->first();

        if (! $localCurrency || ! $cashBox) {
            return;
        }

        $localRate = (float) $localCurrency->rate_to_base;

        foreach (DB::table('payments')->whereNull('voided_at')->orderBy('id')->get() as $payment) {
            $this->insertBackfillTransaction(
                cashBoxId: (int) $cashBox->id,
                currencyId: (int) $localCurrency->id,
                rateToBase: $localRate,
                type: 'invoice_payment_backfill',
                direction: 'in',
                amount: (float) $payment->amount,
                date: (string) $payment->paid_at,
                sourceType: 'App\\Models\\Payment',
                sourceId: (int) $payment->id,
                enteredBy: $payment->received_by ? (int) $payment->received_by : null,
                description: 'Backfilled invoice payment',
            );
        }

        foreach (DB::table('activity_payments')->whereNull('voided_at')->orderBy('id')->get() as $payment) {
            $activityId = DB::table('activity_registrations')->where('id', $payment->activity_registration_id)->value('activity_id');

            $this->insertBackfillTransaction(
                cashBoxId: (int) $cashBox->id,
                currencyId: (int) $localCurrency->id,
                rateToBase: $localRate,
                type: 'activity_payment_backfill',
                direction: 'in',
                amount: (float) $payment->amount,
                date: (string) $payment->paid_at,
                sourceType: 'App\\Models\\ActivityPayment',
                sourceId: (int) $payment->id,
                enteredBy: $payment->entered_by ? (int) $payment->entered_by : null,
                description: 'Backfilled activity payment',
                activityId: $activityId ? (int) $activityId : null,
            );
        }

        foreach (DB::table('activity_expenses')->orderBy('id')->get() as $expense) {
            $this->insertBackfillTransaction(
                cashBoxId: (int) $cashBox->id,
                currencyId: (int) $localCurrency->id,
                rateToBase: $localRate,
                type: 'activity_expense_backfill',
                direction: 'out',
                amount: (float) $expense->amount,
                date: (string) $expense->spent_on,
                sourceType: 'App\\Models\\ActivityExpense',
                sourceId: (int) $expense->id,
                enteredBy: $expense->entered_by ? (int) $expense->entered_by : null,
                description: 'Backfilled activity expense',
                activityId: (int) $expense->activity_id,
            );
        }
    }

    protected function insertBackfillTransaction(
        int $cashBoxId,
        int $currencyId,
        float $rateToBase,
        string $type,
        string $direction,
        float $amount,
        string $date,
        string $sourceType,
        int $sourceId,
        ?int $enteredBy,
        string $description,
        ?int $activityId = null,
    ): void {
        $signedAmount = $direction === 'out' ? -abs($amount) : abs($amount);

        DB::table('finance_transactions')->insert([
            'transaction_no' => sprintf('BF-%s-%06d', strtoupper(substr(md5($type), 0, 8)), $sourceId),
            'cash_box_id' => $cashBoxId,
            'currency_id' => $currencyId,
            'activity_id' => $activityId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'type' => $type,
            'direction' => $direction,
            'amount' => abs($amount),
            'signed_amount' => $signedAmount,
            'rate_to_base' => $rateToBase,
            'base_amount' => round($signedAmount * $rateToBase, 2),
            'local_amount' => $signedAmount,
            'transaction_date' => $date,
            'description' => $description,
            'entered_by' => $enteredBy,
            'metadata' => json_encode(['backfilled' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
