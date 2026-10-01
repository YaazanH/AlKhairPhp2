<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::table('subscription_vouchers', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('name');
            $table->foreign('tenant_id', 'subscription_voucher_tenant_fk')
                ->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('application_type', 30)->default('first_period')->after('ends_at');
            $table->unsignedSmallInteger('max_uses_per_subscription')->nullable()->after('application_type');
        });

        Schema::create('subscription_voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_voucher_id')
                ->constrained('subscription_vouchers', 'id', 'voucher_redemption_voucher_fk')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id')
                ->constrained('tenants', 'id', 'voucher_redemption_tenant_fk')
                ->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')
                ->constrained('tenant_subscriptions', 'id', 'voucher_redemption_subscription_fk')
                ->cascadeOnDelete();
            $table->foreignId('charge_entry_id')
                ->constrained('platform_subscription_ledger_entries', 'id', 'voucher_redemption_charge_fk')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('original_price_syp');
            $table->unsignedBigInteger('discount_syp');
            $table->unsignedBigInteger('final_charge_syp');
            $table->timestamps();
            $table->unique('charge_entry_id', 'voucher_redemption_charge_uq');
            $table->index(['subscription_voucher_id', 'tenant_subscription_id'], 'voucher_redemption_usage_idx');
        });

        $connection = DB::connection('landlord');
        $entries = $connection->table('platform_subscription_ledger_entries')
            ->where('type', 'renewal')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $metadata = is_array($entry->metadata) ? $entry->metadata : json_decode($entry->metadata, true);
            $voucherCode = $metadata['voucher_code'] ?? null;
            if (! is_string($voucherCode) || $voucherCode === '') {
                continue;
            }

            $voucherId = $connection->table('subscription_vouchers')->where('code', $voucherCode)->value('id');
            if (! $voucherId || ! $entry->tenant_subscription_id) {
                continue;
            }

            $original = (int) ($metadata['price_syp'] ?? ($entry->debit_syp + ($metadata['discount_syp'] ?? 0)));
            $discount = (int) ($metadata['discount_syp'] ?? 0);
            $connection->table('subscription_voucher_redemptions')->insertOrIgnore([
                'subscription_voucher_id' => $voucherId,
                'tenant_id' => $entry->tenant_id,
                'tenant_subscription_id' => $entry->tenant_subscription_id,
                'charge_entry_id' => $entry->id,
                'original_price_syp' => $original,
                'discount_syp' => $discount,
                'final_charge_syp' => (int) $entry->debit_syp,
                'created_at' => $entry->created_at,
                'updated_at' => $entry->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_voucher_redemptions');

        Schema::table('subscription_vouchers', function (Blueprint $table): void {
            $table->dropForeign('subscription_voucher_tenant_fk');
            $table->dropColumn(['tenant_id', 'application_type', 'max_uses_per_subscription']);
        });
    }
};
