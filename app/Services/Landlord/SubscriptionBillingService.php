<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionBillingService
{
    public function balance(Tenant $tenant): int
    {
        return (int) PlatformSubscriptionLedgerEntry::query()->where('tenant_id', $tenant->id)->sum(DB::raw('credit_syp - debit_syp'));
    }

    public function recordOfflinePayment(Tenant $tenant, int $amountSyp, string $reference, PlatformAdministrator $actor, ?string $ipAddress): PlatformSubscriptionLedgerEntry
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $amountSyp, $reference, $actor, $ipAddress): PlatformSubscriptionLedgerEntry {
            $entry = PlatformSubscriptionLedgerEntry::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tenant_subscription_id' => $tenant->subscription?->id, 'credit_syp' => $amountSyp, 'type' => PlatformSubscriptionLedgerEntry::TYPE_OFFLINE_PAYMENT, 'reference' => $reference, 'recorded_by_platform_administrator_id' => $actor->id]);
            $this->audit($actor, $tenant, 'subscription_offline_payment_recorded', ['entry_id' => $entry->id, 'amount_syp' => $amountSyp, 'reference' => $reference], $ipAddress);

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
        return DB::connection('landlord')->transaction(function () use ($tenant, $actor, $ipAddress): TenantSubscription {
            $subscription = TenantSubscription::query()->lockForUpdate()->where('tenant_id', $tenant->id)->firstOrFail();
            $start = now();

            if ($subscription->period_type === TenantSubscription::PERIOD_CUSTOM) {
                if (! $subscription->ends_at?->isFuture()) {
                    throw ValidationException::withMessages([
                        'subscription' => 'Set a future custom end date before reactivating.',
                    ]);
                }

                $end = $subscription->ends_at;
            } else {
                $end = $this->periodEnd($subscription->period_type, $start);
            }

            $subscription->update([
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => $start,
                'ends_at' => $end,
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
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
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
                $voucher = $locked->voucher;
                $discount = ($voucher && $voucher->isUsable()) ? $voucher->discountFor($price) : 0;
                $charge = $price - $discount;
                $canRenew = $locked->status !== TenantSubscription::STATUS_CANCELLED
                    && $locked->period_type !== TenantSubscription::PERIOD_CUSTOM
                    && $locked->renews_automatically;
                if ($canRenew && $this->balance($tenant) >= $charge) {
                    $start = max(now(), $locked->ends_at);
                    $end = $this->periodEnd($locked->period_type, $start);
                    PlatformSubscriptionLedgerEntry::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tenant_subscription_id' => $locked->id, 'debit_syp' => $charge, 'type' => PlatformSubscriptionLedgerEntry::TYPE_RENEWAL, 'metadata' => ['plan_code' => $plan->code, 'price_syp' => $price, 'discount_syp' => $discount, 'voucher_code' => $voucher?->code, 'period_type' => $locked->period_type, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]]);
                    $locked->update(['status' => TenantSubscription::STATUS_ACTIVE, 'starts_at' => $start, 'ends_at' => $end, 'grace_ends_at' => null, 'cancelled_at' => null, 'cancelled_by_platform_administrator_id' => null]);
                    $tenant->update(['status' => Tenant::STATUS_ACTIVE, 'suspended_at' => null]);
                    if ($voucher) {
                        $voucher->increment('redemptions');
                    }
                    $this->audit(null, $tenant, 'subscription_automatically_renewed', ['subscription_id' => $locked->id, 'price_syp' => $price, 'discount_syp' => $discount, 'voucher_code' => $voucher?->code]);

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

    private function periodEnd(string $periodType, Carbon $start): Carbon
    {
        return match ($periodType) {
            TenantSubscription::PERIOD_ANNUAL => $start->copy()->addYearNoOverflow(),
            default => $start->copy()->addMonthNoOverflow(),
        };
    }

    private function audit(?PlatformAdministrator $actor, Tenant $tenant, string $event, array $properties, ?string $ipAddress = null): void
    {
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $actor?->id, 'tenant_id' => $tenant->id, 'event' => $event, 'properties' => $properties, 'ip_address' => $ipAddress]);
    }
}
