<?php

namespace Tests\Feature;

use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\SubscriptionVoucher;
use App\Models\Landlord\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformBillingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_subscriptions_are_searchable_paginated_and_filter_ending_dates(): void
    {
        $owner = $this->user();
        $plan = Plan::query()->create(['code' => 'billing-test', 'name' => 'Billing Test', 'is_active' => true]);
        for ($i = 0; $i < 22; $i++) {
            $tenant = $this->tenant('Centre '.$i);
            $tenant->subscription()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays($i === 21 ? 3 : 30)]);
        }
        $this->actingAs($owner, 'platform')->get(route('platform.billing.index'))->assertOk()->assertViewHas('subscriptions', fn ($rows) => $rows->total() === 22 && $rows->count() === 20);
        $this->get(route('platform.billing.index', ['ending' => '7d']))->assertOk()->assertSee('Centre 21')->assertViewHas('subscriptions', fn ($rows) => $rows->total() === 1);
        $this->get(route('platform.billing.index', ['search' => 'Billing Test', 'status' => 'active']))->assertOk()->assertViewHas('subscriptions', fn ($rows) => $rows->total() === 22);
    }

    public function test_payments_filter_by_paid_date_not_creation_and_exclude_charges(): void
    {
        $owner = $this->user();
        $tenant = $this->tenant('Paid centre');
        $payment = PlatformSubscriptionLedgerEntry::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'type' => 'offline_payment', 'credit_syp' => 75000, 'debit_syp' => 0, 'currency' => 'SYP', 'payment_method' => 'cash', 'paid_at' => '2026-09-15 10:00:00', 'reference' => 'Cash reference', 'receipt_number' => 'SYP-TEST']);
        PlatformSubscriptionLedgerEntry::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'type' => 'activation', 'credit_syp' => 0, 'debit_syp' => 20000, 'currency' => 'SYP']);
        $this->actingAs($owner, 'platform')->get(route('platform.billing.payments', ['from' => '2026-09-01', 'to' => '2026-09-30', 'tenant' => $tenant->id]))->assertOk()->assertViewHas('collected', fn ($amount) => $amount == 75000)->assertViewHas('payments', fn ($rows) => $rows->total() === 1)->assertSee(route('platform.subscription-payments.receipt', $payment), false)->assertDontSee('name="amount_syp"', false);
        $this->get(route('platform.billing.payments', ['to' => '2026-09-30']))->assertOk()->assertViewHas('collected', fn ($amount) => $amount == 75000);
        $this->get(route('platform.billing.payments', ['from' => '2026-10-01']))->assertOk()->assertViewHas('collected', fn ($amount) => $amount == 0);
        $this->from(route('platform.billing.payments'))->get(route('platform.billing.payments', ['from' => '2026-10-01', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
        $this->assertSame(2, PlatformSubscriptionLedgerEntry::query()->count());
    }

    public function test_billing_only_viewer_has_readonly_tenant_billing_without_forbidden_navigation(): void
    {
        $this->user();
        $viewer = $this->user(['view.subscriptions']);
        $tenant = $this->tenant('Read only');
        $this->actingAs($viewer, 'platform')->get(route('platform.tenants.billing', $tenant))->assertOk()->assertSee('Back to Billing')->assertDontSee('href="'.route('platform.tenants.edit', $tenant).'"', false)->assertDontSee('name="amount_syp"', false);
        foreach (['platform.billing.index', 'platform.billing.payments', 'platform.vouchers.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->post(route('platform.tenants.subscription.payments.store', $tenant), [])->assertForbidden();
        $this->post(route('platform.vouchers.store'), [])->assertForbidden();
        $this->get(route('platform.vouchers.index'))->assertDontSee('data-voucher-form', false);
        $unprivileged = $this->user([]);
        $this->actingAs($unprivileged, 'platform')->get(route('platform.billing.index'))->assertForbidden();
        $this->get(route('platform.billing.payments'))->assertForbidden();
    }

    public function test_voucher_filters_expose_expired_and_scoped_terms_without_changing_them(): void
    {
        $owner = $this->user();
        $tenant = $this->tenant('Voucher centre');
        SubscriptionVoucher::query()->create(['code' => 'OLD', 'name' => 'Expired offer', 'tenant_id' => $tenant->id, 'discount_type' => 'percent', 'discount_value' => 20, 'application_type' => 'limited_periods', 'max_uses_per_subscription' => 2, 'is_active' => true, 'ends_at' => now()->subDay()]);
        SubscriptionVoucher::query()->create(['code' => 'INACTIVE', 'name' => 'Inactive offer', 'discount_type' => 'fixed', 'discount_value' => 500, 'application_type' => 'recurring', 'is_active' => false]);
        $this->actingAs($owner, 'platform')->get(route('platform.vouchers.index', ['search' => 'OLD', 'status' => 'active']))->assertOk()->assertSee('Expired')->assertSee('Voucher centre')->assertSee('2 periods per subscription')->assertDontSee('Inactive offer')->assertViewHas('vouchers', fn ($rows) => $rows->total() === 1);
        $this->withSession(['locale' => 'ar', 'locale_user_selected' => true])->get(route('platform.vouchers.index'))->assertOk()->assertSee('القسائم')->assertSee('dir="rtl"', false);
        $this->assertSame(2, SubscriptionVoucher::query()->count());
    }

    public function test_tenant_payment_errors_preserve_inputs_and_receipts_are_accessible(): void
    {
        $owner = $this->user();
        $tenant = $this->tenant('Payment centre');
        $url = route('platform.tenants.billing', $tenant);
        $this->actingAs($owner, 'platform')->from($url)->post(route('platform.tenants.subscription.payments.store', $tenant), ['amount_syp' => 12500, 'payment_method' => 'bank_transfer', 'paid_at' => now()->format('Y-m-d H:i'), 'note' => 'Retained note'])
            ->assertSessionHasErrors('reference');
        $this->get($url)->assertOk()->assertSee('value="12500"', false)->assertSee('Retained note');
        $this->get(route('platform.billing.index'))->assertSee('Subscriptions')->assertSee('Payments')->assertSee('Vouchers');
    }

    public function test_billing_guests_are_redirected_to_platform_login(): void
    {
        foreach (['platform.billing.index', 'platform.billing.payments', 'platform.vouchers.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('platform.login'));
        }
    }

    private function user(?array $permissions = null): PlatformAdministrator
    {
        $user = PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Billing reviewer', 'email' => Str::uuid().'@example.test', 'password' => 'test-password', 'is_active' => true]);
        if ($permissions !== null) {
            $role = PlatformRole::query()->create(['name' => 'Billing '.Str::uuid()]);
            $role->permissions()->sync(PlatformPermission::query()->whereIn('code', $permissions)->pluck('id'));
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => $name, 'slug' => 'billing-'.Str::uuid(), 'status' => 'active']);
    }
}
