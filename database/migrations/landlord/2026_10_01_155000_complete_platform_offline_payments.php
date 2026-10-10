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
        Schema::table('platform_subscription_ledger_entries', function (Blueprint $table): void {
            $table->string('receipt_number', 40)->nullable()->unique()->after('type');
            $table->string('payment_method', 30)->nullable()->after('receipt_number');
            $table->string('currency', 3)->default('SYP')->after('payment_method');
            $table->timestamp('paid_at')->nullable()->after('currency');
            $table->text('note')->nullable()->after('reference');
        });

        Schema::create('platform_subscription_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_entry_id');
            $table->unsignedBigInteger('charge_entry_id');
            $table->unsignedBigInteger('amount_syp');
            $table->timestamps();
            $table->foreign('payment_entry_id', 'subscription_alloc_payment_fk')
                ->references('id')->on('platform_subscription_ledger_entries')->cascadeOnDelete();
            $table->foreign('charge_entry_id', 'subscription_alloc_charge_fk')
                ->references('id')->on('platform_subscription_ledger_entries')->cascadeOnDelete();
            $table->unique(['payment_entry_id', 'charge_entry_id'], 'subscription_alloc_payment_charge_uq');
            $table->index('charge_entry_id', 'subscription_alloc_charge_idx');
        });

        $connection = DB::connection('landlord');
        $connection->table('platform_subscription_ledger_entries')
            ->where('type', 'offline_payment')
            ->orderBy('id')
            ->each(function (object $entry) use ($connection): void {
                $date = $entry->created_at ? date('Ymd', strtotime($entry->created_at)) : 'legacy';
                $connection->table('platform_subscription_ledger_entries')
                    ->where('id', $entry->id)
                    ->update([
                        'receipt_number' => 'SYP-'.$date.'-'.str_pad((string) $entry->id, 8, '0', STR_PAD_LEFT),
                        'payment_method' => 'other',
                        'paid_at' => $entry->created_at,
                    ]);
            });

        $entries = $connection->table('platform_subscription_ledger_entries')
            ->orderBy('tenant_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $availablePayments = [];

        foreach ($entries as $entry) {
            $availablePayments[$entry->tenant_id] ??= [];

            if ((int) $entry->credit_syp > 0) {
                $availablePayments[$entry->tenant_id][] = [
                    'id' => $entry->id,
                    'remaining' => (int) $entry->credit_syp,
                ];
            }

            $remainingCharge = (int) $entry->debit_syp;
            foreach ($availablePayments[$entry->tenant_id] as &$payment) {
                if ($remainingCharge <= 0) {
                    break;
                }

                $allocated = min($payment['remaining'], $remainingCharge);
                if ($allocated <= 0) {
                    continue;
                }

                $connection->table('platform_subscription_allocations')->insert([
                    'payment_entry_id' => $payment['id'],
                    'charge_entry_id' => $entry->id,
                    'amount_syp' => $allocated,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $payment['remaining'] -= $allocated;
                $remainingCharge -= $allocated;
            }
            unset($payment);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_allocations');

        Schema::table('platform_subscription_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn(['receipt_number', 'payment_method', 'currency', 'paid_at', 'note']);
        });
    }
};
