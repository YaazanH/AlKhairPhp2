<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_syp')->default(0)->after('is_active');
            $table->unsignedSmallInteger('billing_period_days')->default(30)->after('price_syp');
        });
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->timestamp('grace_ends_at')->nullable()->after('ends_at');
            $table->boolean('renews_automatically')->default(true)->after('grace_ends_at');
        });
        Schema::create('platform_subscription_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')
                ->nullable()
                ->constrained('tenant_subscriptions', 'id', 'platform_billing_subscription_foreign')
                ->nullOnDelete();
            $table->unsignedBigInteger('credit_syp')->default(0);
            $table->unsignedBigInteger('debit_syp')->default(0);
            $table->string('type', 40);
            $table->string('reference', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('recorded_by_platform_administrator_id')->nullable();
            $table->foreign('recorded_by_platform_administrator_id', 'platform_billing_recorded_by_foreign')->references('id')->on('platform_administrators')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_ledger_entries');
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['grace_ends_at', 'renews_automatically']);
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_syp', 'billing_period_days']);
        });
    }
};
