<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSubscriptionAllocation;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\SubscriptionVoucher;
use App\Models\Landlord\SubscriptionVoucherRedemption;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class SubscriptionBillingService
{
    public function quote(Tenant $tenant, Plan $plan, ?SubscriptionVoucher $voucher = null, ?TenantSubscription $subscription = null): array
    {
        $price = (int) $plan->price_syp;
        $discount = $voucher && $subscription && $voucher->isUsableFor($tenant, $subscription)
            ? $voucher->discountFor($price)
            : 0;

        return [
            'price_syp' => $price,
            'discount_syp' => $discount,
            'charge_syp' => $price - $discount,
            'balance_syp' => $this->balance($tenant),
        ];
    }

    public function activatePackage(
        Tenant $tenant,
        Plan $plan,
        ?SubscriptionVoucher $voucher,
        CarbonInterface $startsAt,
        bool $renewsAutomatically,
        PlatformAdministrator $actor,
        ?string $ipAddress,
        bool $trial = false,
        int $trialDays = 0,
    ): TenantSubscription {
        return DB::connection('landlord')->transaction(function () use ($tenant, $plan, $voucher, $startsAt, $renewsAutomatically, $actor, $ipAddress, $trial, $trialDays): TenantSubscription {
            $voucher = $voucher
                ? SubscriptionVoucher::query()->lockForUpdate()->findOrFail($voucher->id)
                : null;
            $subscription = TenantSubscription::query()->lockForUpdate()->firstOrNew(['tenant_id' => $tenant->id]);
            $subscription->fill([
                'plan_id' => $plan->id,
                'subscription_voucher_id' => $voucher?->id,
                'status' => TenantSubscription::STATUS_PENDING,
                'starts_at' => $startsAt,
                'ends_at' => $trial
                    ? Carbon::instance($startsAt)->copy()->addDays($trialDays)
                    : $this->periodEnd($plan, Carbon::instance($startsAt)),
                'grace_ends_at' => null,
                'cancelled_at' => null,
                'cancelled_by_platform_administrator_id' => null,
                'renews_automatically' => $renewsAutomatically,
                'changed_by_platform_administrator_id' => $actor->id,
            ]);
            $subscription->save();

            if ($voucher && ! $voucher->isUsableFor($tenant, $subscription)) {
                throw ValidationException::withMessages(['voucher_id' => 'This voucher is unavailable for this tenant or has reached its usage limit.']);
            }

            $quote = $this->quote($tenant, $plan, $voucher, $subscription);
            if (! $trial && $quote['balance_syp'] < $quote['charge_syp']) {
                throw ValidationException::withMessages([
                    'subscription' => 'The tenant balance is too low. Record at least '.number_format($quote['charge_syp'] - $quote['balance_syp']).' SYP more before activation.',
                ]);
            }

            if (! $trial) {
                $charge = PlatformSubscriptionLedgerEntry::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'tenant_subscription_id' => $subscription->id,
                    'debit_syp' => $quote['charge_syp'],
                    'type' => PlatformSubscriptionLedgerEntry::TYPE_ACTIVATION,
                    'currency' => 'SYP',
                    'metadata' => [
                        'plan_code' => $plan->code,
                        'price_syp' => $quote['price_syp'],
                        'discount_syp' => $quote['discount_syp'],
                        'voucher_id' => $voucher?->id,
                        'voucher_code' => $voucher?->code,
                        'billing_period_days' => $plan->billing_period_days,
                        'starts_at' => $subscription->starts_at?->toIso8601String(),
                        'ends_at' => $subscription->ends_at?->toIso8601String(),
                    ],
                    'recorded_by_platform_administrator_id' => $actor->id,
                ]);
                $this->allocatePaymentCredits($tenant, $charge);
                if ($voucher && $quote['discount_syp'] > 0) {
                    SubscriptionVoucherRedemption::query()->create([
                        'subscription_voucher_id' => $voucher->id,
                        'tenant_id' => $tenant->id,
                        'tenant_subscription_id' => $subscription->id,
                        'charge_entry_id' => $charge->id,
                        'original_price_syp' => $quote['price_syp'],
                        'discount_syp' => $quote['discount_syp'],
                        'final_charge_syp' => $quote['charge_syp'],
                    ]);
                    $voucher->increment('redemptions');
                }
            }

            $subscription->update(['status' => $trial ? TenantSubscription::STATUS_TRIAL : TenantSubscription::STATUS_ACTIVE]);
            $tenant->update(['status' => $trial ? Tenant::STATUS_TRIAL : Tenant::STATUS_ACTIVE, 'suspended_at' => null]);
            $this->audit($actor, $tenant, 'tenant_subscription_updated', [
                'subscription_id' => $subscription->id,
                'plan' => $plan->code,
                'status' => $subscription->status,
                'price_syp' => $quote['price_syp'],
                'discount_syp' => $trial ? 0 : $quote['discount_syp'],
                'charge_syp' => $trial ? 0 : $quote['charge_syp'],
                'billing_period_days' => $trial ? $trialDays : $plan->billing_period_days,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
            ], $ipAddress);

            return $subscription->fresh();
        });
    }

    public function balance(Tenant $tenant): int
    {
        return (int) PlatformSubscriptionLedgerEntry::query()->where('tenant_id', $tenant->id)->sum(DB::raw('credit_syp - debit_syp'));
    }

    public function recordOfflinePayment(
        Tenant $tenant,
        int $amountSyp,
        string $paymentMethod,
        CarbonInterface $paidAt,
        string $reference,
        ?string $note,
        PlatformAdministrator $actor,
        ?string $ipAddress,
    ): PlatformSubscriptionLedgerEntry {
        return DB::connection('landlord')->transaction(function () use ($tenant, $amountSyp, $paymentMethod, $paidAt, $reference, $note, $actor, $ipAddress): PlatformSubscriptionLedgerEntry {
            $uuid = (string) Str::uuid();
            $entry = PlatformSubscriptionLedgerEntry::query()->create([
                'uuid' => $uuid,
                'tenant_id' => $tenant->id,
                'tenant_subscription_id' => $tenant->subscription?->id,
                'credit_syp' => $amountSyp,
                'type' => PlatformSubscriptionLedgerEntry::TYPE_OFFLINE_PAYMENT,
                'receipt_number' => 'SYP-'.$paidAt->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', $uuid), 0, 12)),
                'payment_method' => $paymentMethod,
                'currency' => 'SYP',
                'paid_at' => $paidAt,
                'reference' => $reference,
                'note' => $note,
                'recorded_by_platform_administrator_id' => $actor->id,
            ]);
            $this->audit($actor, $tenant, 'subscription_offline_payment_recorded', [
                'entry_id' => $entry->id,
                'receipt_number' => $entry->receipt_number,
                'amount_syp' => $amountSyp,
                'payment_method' => $paymentMethod,
                'paid_at' => $paidAt->toIso8601String(),
                'reference' => $reference,
            ], $ipAddress);

            return $entry;
        });
    }

    public function cancel(Tenant $tenant, PlatformAdministrator $actor, ?string $ipAddress): TenantSubscription
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $actor, $ipAddress): TenantSubscription {
            $subscription = TenantSubscription::query()->lockForUpdate()->where('tenant_id', $tenant->id)->firstOrFail();

            if ($subscription->ends_at === null) {
                throw ValidationException::withMessages([
                    'subscription' => 'Set the paid subscription end date before cancelling.',
                ]);
            }

            $subscription->update([
                'status' => TenantSubscription::STATUS_CANCELLED,
                'renews_automatically' => false,
                'grace_ends_at' => $subscription->ends_at->copy()->addDays(7),
                'cancelled_at' => now(),
                'cancelled_by_platform_administrator_id' => $actor->id,
                'changed_by_platform_administrator_id' => $actor->id,
            ]);

            $this->audit($actor, $tenant, 'tenant_subscription_cancelled', [
                'subscription_id' => $subscription->id,
                'paid_access_ends_at' => $subscription->ends_at->toIso8601String(),
                'grace_ends_at' => $subscription->ends_at->copy()->addDays(7)->toIso8601String(),
            ], $ipAddress);

            return $subscription->fresh();
        });
    }

    public function reactivate(Tenant $tenant, PlatformAdministrator $actor, ?string $ipAddress): TenantSubscription
    {
        $subscription = TenantSubscription::query()->with(['plan', 'voucher'])->where('tenant_id', $tenant->id)->firstOrFail();
        if (! $subscription->ends_at?->isFuture()) {
            $voucher = $subscription->voucher?->isUsableFor($tenant, $subscription)
                ? $subscription->voucher
                : null;

            return $this->activatePackage(
                $tenant,
                $subscription->plan,
                $voucher,
                now(),
                $subscription->renews_automatically,
                $actor,
                $ipAddress,
            );
        }

        return DB::connection('landlord')->transaction(function () use ($tenant, $subscription, $actor, $ipAddress): TenantSubscription {
            $subscription = TenantSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $subscription->update([
                'status' => TenantSubscription::STATUS_ACTIVE,
                'grace_ends_at' => null,
                'cancelled_at' => null,
                'cancelled_by_platform_administrator_id' => null,
                'changed_by_platform_administrator_id' => $actor->id,
            ]);
            $tenant->update([
                'status' => Tenant::STATUS_ACTIVE,
                'suspended_at' => null,
            ]);

            $this->audit($actor, $tenant, 'tenant_subscription_reactivated', [
                'subscription_id' => $subscription->id,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
            ], $ipAddress);

            return $subscription->fresh();
        });
    }

    public function processDueSubscriptions(): array
    {
        $result = ['renewed' => 0, 'grace_started' => 0, 'suspended' => 0];
        TenantSubscription::query()->with(['tenant', 'plan'])->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL, TenantSubscription::STATUS_CANCELLED])->whereNotNull('ends_at')->where('ends_at', '<=', now())->orderBy('id')->each(function (TenantSubscription $subscription) use (&$result): void {
            $outcome = DB::connection('landlord')->transaction(function () use ($subscription): string {
                $locked = TenantSubscription::query()->with(['tenant', 'plan'])->lockForUpdate()->findOrFail($subscription->id);
                if ($locked->ends_at?->isFuture()) {
                    return 'none';
                }
                $tenant = $locked->tenant;
                $plan = $locked->plan;
                if (! $tenant || ! $plan) {
                    return 'none';
                }
                $price = $plan->price_syp;
                $voucher = $locked->subscription_voucher_id
                    ? SubscriptionVoucher::query()->lockForUpdate()->find($locked->subscription_voucher_id)
                    : null;
                $appliedVoucher = $voucher?->isUsableFor($tenant, $locked) ? $voucher : null;
                $discount = $appliedVoucher?->discountFor($price) ?? 0;
                $charge = $price - $discount;
                $canRenew = $locked->status !== TenantSubscription::STATUS_CANCELLED
                    && $locked->renews_automatically;
                if ($canRenew && $this->balance($tenant) >= $charge) {
                    $start = max(now(), $locked->ends_at);
                    $end = $this->periodEnd($plan, $start);
                    $chargeEntry = PlatformSubscriptionLedgerEntry::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tenant_subscription_id' => $locked->id, 'debit_syp' => $charge, 'type' => PlatformSubscriptionLedgerEntry::TYPE_RENEWAL, 'currency' => 'SYP', 'metadata' => ['plan_code' => $plan->code, 'price_syp' => $price, 'discount_syp' => $discount, 'voucher_id' => $appliedVoucher?->id, 'voucher_code' => $appliedVoucher?->code, 'billing_period_days' => $plan->billing_period_days, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]]);
                    $this->allocatePaymentCredits($tenant, $chargeEntry);
                    if ($appliedVoucher && $discount > 0) {
                        SubscriptionVoucherRedemption::query()->create([
                            'subscription_voucher_id' => $appliedVoucher->id,
                            'tenant_id' => $tenant->id,
                            'tenant_subscription_id' => $locked->id,
                            'charge_entry_id' => $chargeEntry->id,
                            'original_price_syp' => $price,
                            'discount_syp' => $discount,
                            'final_charge_syp' => $charge,
                        ]);
                        $appliedVoucher->increment('redemptions');
                    }
                    $locked->update(['status' => TenantSubscription::STATUS_ACTIVE, 'starts_at' => $start, 'ends_at' => $end, 'grace_ends_at' => null, 'cancelled_at' => null, 'cancelled_by_platform_administrator_id' => null]);
                    $tenant->update(['status' => Tenant::STATUS_ACTIVE, 'suspended_at' => null]);
                    $this->audit(null, $tenant, 'subscription_automatically_renewed', ['subscription_id' => $locked->id, 'price_syp' => $price, 'discount_syp' => $discount, 'voucher_code' => $appliedVoucher?->code]);

                    return 'renewed';
                }
                if ($locked->grace_ends_at === null) {
                    $locked->update(['grace_ends_at' => now()->addDays(7)]);
                    $this->audit(null, $tenant, 'subscription_grace_started', ['subscription_id' => $locked->id, 'grace_ends_at' => $locked->fresh()->grace_ends_at?->toIso8601String()]);

                    return 'grace_started';
                }
                if ($locked->grace_ends_at->isPast()) {
                    $locked->update(['status' => TenantSubscription::STATUS_SUSPENDED]);
                    if (in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL], true)) {
                        $tenant->update(['status' => Tenant::STATUS_SUSPENDED, 'suspended_at' => now()]);
                    }
                    $this->audit(null, $tenant, 'subscription_suspended_for_nonpayment', ['subscription_id' => $locked->id]);

                    return 'suspended';
                }

                return 'none';
            });
            if ($outcome !== 'none') {
                $result[$outcome]++;
            }
        });

        return $result;
    }

    private function periodEnd(Plan $plan, Carbon $start): Carbon
    {
        return $start->copy()->addDays((int) $plan->billing_period_days);
    }

    private function allocatePaymentCredits(Tenant $tenant, PlatformSubscriptionLedgerEntry $charge): void
    {
        $remaining = $charge->debit_syp;
        $payments = PlatformSubscriptionLedgerEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', PlatformSubscriptionLedgerEntry::TYPE_OFFLINE_PAYMENT)
            ->withSum('paymentAllocations as allocated_syp', 'amount_syp')
            ->orderByRaw('paid_at is null')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($payments as $payment) {
            $available = $payment->credit_syp - (int) ($payment->allocated_syp ?? 0);
            $allocated = min($available, $remaining);

            if ($allocated <= 0) {
                continue;
            }

            PlatformSubscriptionAllocation::query()->create([
                'payment_entry_id' => $payment->id,
                'charge_entry_id' => $charge->id,
                'amount_syp' => $allocated,
            ]);
            $remaining -= $allocated;

            if ($remaining === 0) {
                return;
            }
        }

        if ($remaining !== 0) {
            throw new LogicException('The subscription charge could not be fully allocated from tenant credit.');
        }
    }

    private function audit(?PlatformAdministrator $actor, Tenant $tenant, string $event, array $properties, ?string $ipAddress = null): void
    {
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $actor?->id, 'tenant_id' => $tenant->id, 'event' => $event, 'properties' => $properties, 'ip_address' => $ipAddress]);
    }
}
