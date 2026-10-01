<?php

namespace Tests\Feature;

use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\SubscriptionVoucher;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use App\Services\Landlord\SubscriptionBillingService;
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
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        Carbon::setTestNow('2026-10-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_offline_payment_creates_prepaid_syp_credit_and_automatic_renewal_debits_it(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-10-01 11:00:00');
        $admin = $this->admin();
        $billing = app(SubscriptionBillingService::class);
        $payment = $billing->recordOfflinePayment($tenant, 50000, PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_CASH, now()->subDay(), 'Cash receipt 17', 'Paid at the Platform office.', $admin, '127.0.0.1');
        $this->assertStringStartsWith('SYP-20260930-', $payment->receipt_number);
        $this->assertSame('SYP', $payment->currency);
        $this->assertSame('Paid at the Platform office.', $payment->note);
        $this->assertSame(50000, $billing->balance($tenant));
        $result = $billing->processDueSubscriptions();
        $this->assertSame(1, $result['renewed']);
        $this->assertSame(0, $billing->balance($tenant));
        $this->assertTrue($subscription->fresh()->ends_at->equalTo(now()->addMonthNoOverflow()));
        $this->assertDatabaseHas('platform_subscription_ledger_entries', ['tenant_id' => $tenant->id, 'debit_syp' => 50000, 'type' => 'renewal'], 'landlord');
        $this->assertDatabaseHas('platform_subscription_allocations', ['payment_entry_id' => $payment->id, 'amount_syp' => 50000], 'landlord');
    }

    public function test_voucher_reduces_one_subscription_renewal_and_records_its_snapshot(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-10-01 11:00:00');
        $voucher = SubscriptionVoucher::query()->create(['code' => 'WELCOME20', 'name' => 'Welcome', 'discount_type' => 'percent', 'discount_value' => 20, 'max_redemptions' => 1]);
        $subscription->update(['subscription_voucher_id' => $voucher->id]);
        app(SubscriptionBillingService::class)->recordOfflinePayment($tenant, 40000, PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_CASH, now(), 'Cash receipt 18', null, $this->admin(), null);
        app(SubscriptionBillingService::class)->processDueSubscriptions();
        $this->assertSame(0, app(SubscriptionBillingService::class)->balance($tenant));
        $this->assertSame(1, $voucher->fresh()->redemptions);
        $this->assertDatabaseHas('platform_subscription_ledger_entries', ['tenant_id' => $tenant->id, 'debit_syp' => 40000], 'landlord');
    }

    public function test_insufficient_balance_starts_seven_day_grace_then_suspends(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-10-01 11:00:00');
        $billing = app(SubscriptionBillingService::class);
        $this->assertSame(1, $billing->processDueSubscriptions()['grace_started']);
        $this->assertTrue($subscription->fresh()->grace_ends_at->equalTo(now()->addDays(7)));
        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame(1, $billing->processDueSubscriptions()['suspended']);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
    }

    public function test_cancelled_subscription_keeps_access_until_grace_ends_and_never_auto_renews(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-10-05 12:00:00');
        $admin = $this->admin();
        $billing = app(SubscriptionBillingService::class);
        $billing->recordOfflinePayment($tenant, 50000, PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_CASH, now(), 'Cash receipt 19', null, $admin, null);

        $billing->cancel($tenant, $admin, '127.0.0.1');

        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->grace_ends_at->equalTo('2026-10-12 12:00:00'));
        $this->assertTrue($subscription->fresh()->isCurrent());

        Carbon::setTestNow('2026-10-06 12:00:00');
        $this->assertSame(['renewed' => 0, 'grace_started' => 0, 'suspended' => 0], $billing->processDueSubscriptions());
        $this->assertSame(50000, $billing->balance($tenant));
        $this->assertTrue($subscription->fresh()->isCurrent());

        Carbon::setTestNow('2026-10-13 12:00:00');
        $this->assertSame(1, $billing->processDueSubscriptions()['suspended']);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->fresh()->status);
        $this->assertNotNull($tenant->fresh()->suspended_at);
        $this->assertFalse($subscription->fresh()->isCurrent());
    }

    public function test_reactivation_starts_a_new_period_and_clears_suspension_state(): void
    {
        [$tenant, $subscription] = $this->subscription('2026-09-01 12:00:00');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED, 'suspended_at' => now()->subMonth()]);
        $subscription->update([
            'status' => TenantSubscription::STATUS_SUSPENDED,
            'cancelled_at' => now()->subMonths(2),
            'grace_ends_at' => now()->subMonth(),
        ]);

        app(SubscriptionBillingService::class)->reactivate($tenant, $this->admin(), null);

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
        $this->assertNull($tenant->fresh()->suspended_at);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->cancelled_at);
        $this->assertNull($subscription->fresh()->grace_ends_at);
        $this->assertTrue($subscription->fresh()->starts_at->equalTo(now()));
        $this->assertTrue($subscription->fresh()->ends_at->equalTo(now()->addMonthNoOverflow()));
    }

    public function test_partial_payments_remain_as_credit_and_are_allocated_fifo_when_the_balance_is_enough(): void
    {
        [$tenant] = $this->subscription('2026-10-01 11:00:00');
        $billing = app(SubscriptionBillingService::class);
        $admin = $this->admin();
        $first = $billing->recordOfflinePayment($tenant, 20000, PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_BANK_TRANSFER, now()->subDays(2), 'BANK-20', null, $admin, null);

        $this->assertSame(1, $billing->processDueSubscriptions()['grace_started']);
        $this->assertSame(20000, $billing->balance($tenant));

        $second = $billing->recordOfflinePayment($tenant, 30000, PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_CHEQUE, now()->subDay(), 'CHQ-30', null, $admin, null);
        $this->assertSame(1, $billing->processDueSubscriptions()['renewed']);

        $charge = PlatformSubscriptionLedgerEntry::query()->where('type', PlatformSubscriptionLedgerEntry::TYPE_RENEWAL)->sole();
        $this->assertDatabaseHas('platform_subscription_allocations', ['payment_entry_id' => $first->id, 'charge_entry_id' => $charge->id, 'amount_syp' => 20000], 'landlord');
        $this->assertDatabaseHas('platform_subscription_allocations', ['payment_entry_id' => $second->id, 'charge_entry_id' => $charge->id, 'amount_syp' => 30000], 'landlord');
        $this->assertSame(0, $billing->balance($tenant));
    }

    public function test_subscription_ledger_entries_cannot_be_edited(): void
    {
        [$tenant] = $this->subscription('2026-10-05 12:00:00');
        $payment = app(SubscriptionBillingService::class)->recordOfflinePayment($tenant, 1000, PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_OTHER, now(), 'OTHER-1', null, $this->admin(), null);

        $this->expectException(\LogicException::class);
        $payment->update(['reference' => 'changed']);
    }

    private function subscription(string $endsAt): array
    {
        $plan = Plan::query()->create(['code' => 'paid', 'name' => 'Paid', 'price_syp' => 50000, 'billing_period_days' => 30]);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Centre', 'slug' => 'centre', 'status' => Tenant::STATUS_ACTIVE]);
        $subscription = TenantSubscription::query()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => TenantSubscription::STATUS_ACTIVE, 'starts_at' => now()->subDays(30), 'ends_at' => $endsAt]);

        return [$tenant, $subscription];
    }

    private function admin(): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-password']);
    }
}
