<?php

namespace Tests\Feature;

use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use App\Models\Landlord\SubscriptionVoucher;
use App\Services\Landlord\SubscriptionBillingService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]); DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        Carbon::setTestNow('2026-10-01 12:00:00');
    }
    protected function tearDown(): void { Carbon::setTestNow(); DB::purge('landlord'); parent::tearDown(); }

    public function test_offline_payment_creates_prepaid_syp_credit_and_automatic_renewal_debits_it(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-10-01 11:00:00'); $admin = $this->admin(); $billing = app(SubscriptionBillingService::class);
        $billing->recordOfflinePayment($tenant, 50000, 'Cash receipt 17', $admin, '127.0.0.1');
        $this->assertSame(50000, $billing->balance($tenant));
        $result = $billing->processDueSubscriptions();
        $this->assertSame(1, $result['renewed']);
        $this->assertSame(0, $billing->balance($tenant));
        $this->assertTrue($subscription->fresh()->ends_at->equalTo(now()->addDays(30)));
        $this->assertDatabaseHas('platform_subscription_ledger_entries', ['tenant_id' => $tenant->id, 'debit_syp' => 50000, 'type' => 'renewal'], 'landlord');
    }

    public function test_voucher_reduces_one_subscription_renewal_and_records_its_snapshot(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-10-01 11:00:00');
        $voucher = SubscriptionVoucher::query()->create(['code' => 'WELCOME20', 'name' => 'Welcome', 'discount_type' => 'percent', 'discount_value' => 20, 'max_redemptions' => 1]);
        $subscription->update(['subscription_voucher_id' => $voucher->id]);
        app(SubscriptionBillingService::class)->recordOfflinePayment($tenant, 40000, 'Cash receipt 18', $this->admin(), null);
        app(SubscriptionBillingService::class)->processDueSubscriptions();
        $this->assertSame(0, app(SubscriptionBillingService::class)->balance($tenant));
        $this->assertSame(1, $voucher->fresh()->redemptions);
        $this->assertDatabaseHas('platform_subscription_ledger_entries', ['tenant_id' => $tenant->id, 'debit_syp' => 40000], 'landlord');
    }
    public function test_insufficient_balance_starts_seven_day_grace_then_suspends(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-10-01 11:00:00'); $billing = app(SubscriptionBillingService::class);
        $this->assertSame(1, $billing->processDueSubscriptions()['grace_started']);
        $this->assertTrue($subscription->fresh()->grace_ends_at->equalTo(now()->addDays(7)));
        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame(1, $billing->processDueSubscriptions()['suspended']);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
    }

    private function subscription(string $endsAt): array
    {
        $plan = Plan::query()->create(['code' => 'paid', 'name' => 'Paid', 'price_syp' => 50000, 'billing_period_days' => 30]);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Centre', 'slug' => 'centre', 'status' => Tenant::STATUS_ACTIVE]);
        $subscription = TenantSubscription::query()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => TenantSubscription::STATUS_ACTIVE, 'starts_at' => now()->subDays(30), 'ends_at' => $endsAt]);
        return [$tenant, $subscription];
    }
    private function admin(): PlatformAdministrator { return PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-password']); }
}