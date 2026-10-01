<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function processDueSubscriptions(): array
    {
        $result = ['renewed' => 0, 'grace_started' => 0, 'suspended' => 0];
        TenantSubscription::query()->with(['tenant', 'plan'])->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])->whereNotNull('ends_at')->where('ends_at', '<=', now())->orderBy('id')->each(function (TenantSubscription $subscription) use (&$result): void {
            $outcome = DB::connection('landlord')->transaction(function () use ($subscription): string {
                $locked = TenantSubscription::query()->with(['tenant', 'plan'])->lockForUpdate()->findOrFail($subscription->id);
                if ($locked->ends_at?->isFuture()) return 'none';
                $tenant = $locked->tenant;
                $plan = $locked->plan;
                if (! $tenant || ! $plan) return 'none';
                $price = $plan->price_syp;
                if ($locked->renews_automatically && $this->balance($tenant) >= $price) {
                    $start = max(now(), $locked->ends_at);
                    $end = $start->copy()->addDays($plan->billing_period_days);
                    PlatformSubscriptionLedgerEntry::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tenant_subscription_id' => $locked->id, 'debit_syp' => $price, 'type' => PlatformSubscriptionLedgerEntry::TYPE_RENEWAL, 'metadata' => ['plan_code' => $plan->code, 'price_syp' => $price, 'billing_period_days' => $plan->billing_period_days, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]]);
                    $locked->update(['status' => TenantSubscription::STATUS_ACTIVE, 'starts_at' => $start, 'ends_at' => $end, 'grace_ends_at' => null]);
                    $tenant->update(['status' => Tenant::STATUS_ACTIVE]);
                    $this->audit(null, $tenant, 'subscription_automatically_renewed', ['subscription_id' => $locked->id, 'price_syp' => $price]);
                    return 'renewed';
                }
                if ($locked->grace_ends_at === null) {
                    $locked->update(['grace_ends_at' => now()->addDays(7)]);
                    $this->audit(null, $tenant, 'subscription_grace_started', ['subscription_id' => $locked->id, 'grace_ends_at' => $locked->fresh()->grace_ends_at?->toIso8601String()]);
                    return 'grace_started';
                }
                if ($locked->grace_ends_at->isPast()) {
                    $locked->update(['status' => TenantSubscription::STATUS_SUSPENDED]);
                    if ($tenant->status === Tenant::STATUS_ACTIVE) $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
                    $this->audit(null, $tenant, 'subscription_suspended_for_nonpayment', ['subscription_id' => $locked->id]);
                    return 'suspended';
                }
                return 'none';
            });
            if ($outcome !== 'none') $result[$outcome]++;
        });

        return $result;
    }

    private function audit(?PlatformAdministrator $actor, Tenant $tenant, string $event, array $properties, ?string $ipAddress = null): void
    {
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $actor?->id, 'tenant_id' => $tenant->id, 'event' => $event, 'properties' => $properties, 'ip_address' => $ipAddress]);
    }
}