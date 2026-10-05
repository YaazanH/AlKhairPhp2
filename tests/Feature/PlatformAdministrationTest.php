<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\SaasPlatformSetting;
use App\Models\Landlord\SubscriptionVoucher;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use App\Services\Landlord\TenantModuleAccess;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.landlord', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('landlord');

        Artisan::call('migrate', [
            '--database' => 'landlord',
            '--path' => database_path('migrations/landlord'),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');

        parent::tearDown();
    }

    public function test_platform_administrator_can_sign_in_and_view_the_landlord_dashboard(): void
    {
        $this->seed(LandlordCatalogSeeder::class);

        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);

        Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Al Noor Centre',
            'slug' => 'al-noor',
            'status' => Tenant::STATUS_DRAFT,
        ]);
        $openTenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Open Centre',
            'slug' => 'open-centre',
            'database_name' => 'alkhair_tenant_'.str_repeat('a', 32),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $openTenant->domains()->create(['host' => 'open-centre.localhost', 'is_primary' => true]);

        $this->post(route('platform.login.store'), [
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('platform.dashboard'));

        $this->assertAuthenticatedAs($administrator, 'platform');

        $this->get(route('platform.dashboard'))
            ->assertOk()
            ->assertSee('Tenant overview')
            ->assertSee('Al Noor Centre')
            ->assertSee('Manage')
            ->assertSee('href="http://open-centre.localhost:8000"', false)
            ->assertSee('Open Open Centre website')
            ->assertSee(route('platform.tenants.support-access.store', $openTenant), false)
            ->assertSee('Open tenant');
    }

    public function test_inactive_platform_administrator_cannot_sign_in(): void
    {
        PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Inactive Administrator',
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
            'is_active' => false,
        ]);

        $this->from(route('platform.login'))
            ->post(route('platform.login.store'), [
                'email' => 'inactive@example.test',
                'password' => 'secret-password',
            ])
            ->assertRedirect(route('platform.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('platform');
    }

    public function test_platform_dashboard_redirects_guests_to_the_platform_login(): void
    {
        app()->setLocale('en');

        $this->get(route('platform.login'))
            ->assertOk()
            ->assertSee('<title>Platform Administration | AlKhair Platform</title>', false);

        $this->get(route('platform.dashboard'))
            ->assertRedirect(route('platform.login'));
    }

    public function test_platform_administrator_can_start_tenant_provisioning_from_the_dashboard(): void
    {
        $this->seed(LandlordCatalogSeeder::class);

        Plan::query()->create([
            'code' => 'custom_learning',
            'name' => 'Custom Learning',
            'is_active' => true,
        ]);

        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);
        $voucher = SubscriptionVoucher::query()->create([
            'code' => 'START10',
            'name' => 'Signup discount',
            'discount_type' => SubscriptionVoucher::PERCENT,
            'discount_value' => 10,
            'application_type' => SubscriptionVoucher::APPLICATION_FIRST_PERIOD,
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('saas:provision-tenant', \Mockery::on(fn (array $arguments) => $arguments['--platform-email'] === $administrator->email
                && $arguments['--plan'] === 'custom_learning'
                && $arguments['--voucher'] === 'START10'
                && $arguments['--timezone'] === 'Asia/Damascus'
                && $arguments['--locale'] === 'ar'))
            ->andReturn(Command::SUCCESS);

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.tenants.store'), [
                'name' => 'Al Noor Centre',
                'slug' => 'al-noor',
                'owner_name' => 'Tenant Owner',
                'owner_email' => 'owner@alnoor.test',
                'owner_password' => 'temporary-password',
                'plan' => 'custom_learning',
                'voucher_id' => $voucher->id,
                'timezone' => 'Asia/Damascus',
                'locale' => 'ar',
            ])
            ->assertRedirect(route('platform.dashboard'))
            ->assertSessionHas('status', __('platform.provisioning.success'));
    }

    public function test_platform_administrator_cannot_assign_a_reserved_subdomain_to_a_tenant(): void
    {
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);

        $this->actingAs($administrator, 'platform')
            ->from(route('platform.dashboard'))
            ->post(route('platform.tenants.store'), [
                'name' => 'Reserved Host Centre',
                'slug' => 'api',
                'owner_name' => 'Tenant Owner',
                'owner_email' => 'owner@example.test',
                'owner_password' => 'temporary-password',
                'plan' => 'core',
            ])
            ->assertRedirect(route('platform.dashboard'))
            ->assertSessionHasErrors('slug');
    }

    public function test_tenant_creation_reports_duplicate_subdomains_and_inactive_packages_clearly(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);
        Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Existing Centre',
            'slug' => 'existing-centre',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        Plan::query()->create(['code' => 'inactive_custom', 'name' => 'Inactive Custom', 'is_active' => false]);
        $payload = [
            'name' => 'New Centre',
            'owner_name' => 'Tenant Owner',
            'owner_email' => 'owner@example.test',
            'owner_password' => 'temporary-password',
            'plan' => 'core',
        ];

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.tenants.store'), $payload + ['slug' => 'existing-centre'])
            ->assertSessionHasErrors(['slug' => 'This subdomain is already assigned to another tenant.']);

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.tenants.store'), array_merge($payload, ['slug' => 'new-centre', 'plan' => 'inactive_custom']))
            ->assertSessionHasErrors(['plan' => 'Choose an active package from the package list.']);
    }

    public function test_package_creation_reports_a_duplicate_code_clearly(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.plans.store'), [
                'code' => 'core',
                'name' => 'Duplicate Core',
                'modules' => [],
                'is_active' => '1',
            ])
            ->assertSessionHasErrors(['code' => 'This package code is already in use. Choose a different code.']);
    }

    public function test_platform_administrator_can_change_a_tenant_package_manually(): void
    {
        $this->seed(LandlordCatalogSeeder::class);

        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Al Noor Centre',
            'slug' => 'al-noor',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->actingAs($administrator, 'platform')
            ->put(route('platform.tenants.subscription.update', $tenant), ['plan' => 'core_finance_printing'])
            ->assertRedirect(route('platform.dashboard'))
            ->assertSessionHas('status', __('platform.provisioning.subscription_updated'));

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::query()->where('code', 'core_finance_printing')->sole()->id,
            'status' => 'active',
        ], 'landlord');
        $this->assertDatabaseHas('platform_audit_events', [
            'tenant_id' => $tenant->id,
            'event' => 'tenant_subscription_updated',
        ], 'landlord');
    }

    public function test_platform_owner_can_set_an_annual_subscription_period(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'annual@example.test',
            'password' => 'secret-password',
        ]);
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Annual Centre',
            'slug' => 'annual-centre',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->actingAs($administrator, 'platform')
            ->put(route('platform.tenants.subscription.update', $tenant), [
                'plan' => 'core',
                'subscription_status' => TenantSubscription::STATUS_ACTIVE,
                'period_type' => TenantSubscription::PERIOD_ANNUAL,
                'starts_at' => '2026-10-01 12:00:00',
                'renews_automatically' => '1',
            ])
            ->assertRedirect(route('platform.dashboard'));

        $subscription = $tenant->subscription()->sole();
        $this->assertSame(TenantSubscription::PERIOD_ANNUAL, $subscription->period_type);
        $this->assertTrue($subscription->ends_at->equalTo('2027-10-01 12:00:00'));
        $this->assertTrue($subscription->renews_automatically);
    }

    public function test_custom_subscription_uses_its_selected_end_and_disables_automatic_renewal(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'custom-period@example.test',
            'password' => 'secret-password',
        ]);
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Evaluation Centre',
            'slug' => 'evaluation-centre',
            'status' => Tenant::STATUS_TRIAL,
        ]);

        $this->actingAs($administrator, 'platform')
            ->put(route('platform.tenants.subscription.update', $tenant), [
                'plan' => 'core',
                'subscription_status' => TenantSubscription::STATUS_TRIAL,
                'period_type' => TenantSubscription::PERIOD_CUSTOM,
                'starts_at' => '2026-10-01 12:00:00',
                'ends_at' => '2026-10-10 12:00:00',
                'renews_automatically' => '1',
            ])
            ->assertRedirect(route('platform.dashboard'));

        $subscription = $tenant->subscription()->sole();
        $this->assertSame(TenantSubscription::PERIOD_CUSTOM, $subscription->period_type);
        $this->assertTrue($subscription->ends_at->equalTo('2026-10-10 12:00:00'));
        $this->assertFalse($subscription->renews_automatically);
    }

    public function test_platform_owner_can_update_the_suspended_data_retention_setting(): void
    {
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'retention@example.test',
            'password' => 'secret-password',
        ]);

        $this->assertSame(12, SaasPlatformSetting::current()->suspended_data_retention_months);

        $this->actingAs($administrator, 'platform')
            ->put(route('platform.subscription-settings.update'), [
                'suspended_data_retention_months' => 18,
            ])
            ->assertSessionHas('status', 'Subscription retention settings saved.');

        $this->assertSame(18, SaasPlatformSetting::current()->fresh()->suspended_data_retention_months);
        $this->assertDatabaseHas('platform_audit_events', [
            'platform_administrator_id' => $administrator->id,
            'event' => 'subscription_retention_setting_updated',
        ], 'landlord');
    }

    public function test_platform_owner_can_record_an_offline_payment_and_view_or_download_its_receipt(): void
    {
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Payment Officer',
            'email' => 'payments@example.test',
            'password' => 'secret-password',
        ]);
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Al Noor Centre',
            'slug' => 'al-noor-payments',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.tenants.subscription.payments.store', $tenant), [
                'amount_syp' => 75000,
                'payment_method' => PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_BANK_TRANSFER,
                'paid_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'reference' => 'TRANSFER-204',
                'note' => 'Advance subscription payment.',
            ])
            ->assertSessionHas('status', 'Offline SYP payment recorded as tenant credit.');

        $payment = PlatformSubscriptionLedgerEntry::query()->sole();
        $this->assertSame(75000, $payment->credit_syp);
        $this->assertSame('SYP', $payment->currency);
        $this->assertSame(PlatformSubscriptionLedgerEntry::PAYMENT_METHOD_BANK_TRANSFER, $payment->payment_method);
        $this->assertSame('Advance subscription payment.', $payment->note);
        $this->assertNotNull($payment->receipt_number);

        $receiptHtml = view('platform.billing.receipt', [
            'entry' => $payment->load(['tenant', 'recordedBy']),
        ])->render();
        $this->assertStringContainsString($payment->receipt_number, $receiptHtml);
        $this->assertStringContainsString('Al Noor Centre', $receiptHtml);
        $this->assertStringContainsString('Bank Transfer', $receiptHtml);
        $this->assertStringContainsString('75,000 SYP', $receiptHtml);
        $this->assertStringContainsString('Advance subscription payment.', $receiptHtml);
        $this->assertStringContainsString('Payment Officer', $receiptHtml);

        $this->actingAs($administrator, 'platform')
            ->get(route('platform.subscription-payments.receipt', $payment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="subscription-receipt-'.$payment->receipt_number.'.pdf"');

        $this->actingAs($administrator, 'platform')
            ->get(route('platform.subscription-payments.receipt', ['entry' => $payment, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="subscription-receipt-'.$payment->receipt_number.'.pdf"');
    }

    public function test_platform_owner_can_create_a_tenant_scoped_limited_period_voucher(): void
    {
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'voucher-admin@example.test',
            'password' => 'secret-password',
        ]);
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Voucher Centre',
            'slug' => 'voucher-centre',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.vouchers.store'), [
                'code' => 'tenant-two',
                'name' => 'Tenant two-period discount',
                'discount_type' => SubscriptionVoucher::PERCENT,
                'discount_value' => 15,
                'usage_limit' => 'tenant',
                'tenant_id' => $tenant->id,
                'application_type' => SubscriptionVoucher::APPLICATION_LIMITED_PERIODS,
                'max_uses_per_subscription' => 2,
                'starts_at' => '2026-10-02 09:00:00',
                'ends_at' => '2026-12-31 23:59:00',
            ])
            ->assertSessionHas('status', 'Voucher created.');

        $voucher = SubscriptionVoucher::query()->sole();
        $this->assertSame('TENANT-TWO', $voucher->code);
        $this->assertSame($tenant->id, $voucher->tenant_id);
        $this->assertNull($voucher->max_redemptions);
        $this->assertSame(SubscriptionVoucher::APPLICATION_LIMITED_PERIODS, $voucher->application_type);
        $this->assertSame(2, $voucher->max_uses_per_subscription);
        $this->assertDatabaseHas('platform_audit_events', [
            'platform_administrator_id' => $administrator->id,
            'tenant_id' => $tenant->id,
            'event' => 'subscription_voucher_created',
        ], 'landlord');
    }

    public function test_tenant_cannot_be_assigned_a_voucher_scoped_to_another_tenant(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'voucher-scope@example.test',
            'password' => 'secret-password',
        ]);
        $allowedTenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Allowed', 'slug' => 'allowed-voucher', 'status' => Tenant::STATUS_ACTIVE]);
        $otherTenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Other', 'slug' => 'other-voucher', 'status' => Tenant::STATUS_ACTIVE]);
        $voucher = SubscriptionVoucher::query()->create([
            'code' => 'TENANTONLY',
            'name' => 'Tenant only',
            'tenant_id' => $allowedTenant->id,
            'discount_type' => SubscriptionVoucher::FIXED,
            'discount_value' => 10000,
            'application_type' => SubscriptionVoucher::APPLICATION_FIRST_PERIOD,
        ]);

        $this->actingAs($administrator, 'platform')
            ->put(route('platform.tenants.subscription.update', $otherTenant), [
                'plan' => 'core',
                'voucher_id' => $voucher->id,
            ])
            ->assertSessionHasErrors(['voucher_id']);

        $this->assertDatabaseMissing('tenant_subscriptions', [
            'tenant_id' => $otherTenant->id,
            'subscription_voucher_id' => $voucher->id,
        ], 'landlord');
    }

    public function test_platform_administrator_can_choose_the_features_in_a_package(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);
        $plan = Plan::query()->where('code', 'core_finance_printing')->sole();

        $this->actingAs($administrator, 'platform')
            ->put(route('platform.plans.update', $plan), [
                'name' => 'Finance only',
                'features' => [Feature::FINANCE],
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Package saved successfully.');

        $this->assertSame('Finance only', $plan->fresh()->name);
        $this->assertEqualsCanonicalizing(
            [Feature::CORE, Feature::FINANCE],
            $plan->fresh()->features()->pluck('code')->all(),
        );
    }

    public function test_platform_administrator_can_edit_and_suspend_a_tenant(): void
    {
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Platform Administrator',
            'email' => 'platform@example.test', 'password' => 'secret-password',
        ]);
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Old Name',
            'slug' => 'old-name', 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->actingAs($administrator, 'platform')
            ->put(route('platform.tenants.update', $tenant), [
                'name' => 'New Name', 'slug' => 'new-name',
                'timezone' => 'Asia/Damascus', 'locale' => 'ar',
            ])->assertRedirect(route('platform.tenants.edit', 'old-name'));

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'New Name', 'slug' => 'old-name'], 'landlord');

        $this->actingAs($administrator, 'platform')
            ->patch(route('platform.tenants.status', 'old-name'), ['status' => Tenant::STATUS_SUSPENDED])
            ->assertRedirect();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => Tenant::STATUS_SUSPENDED], 'landlord');

        $this->actingAs($administrator, 'platform')
            ->patch(route('platform.tenants.status', 'old-name'), ['status' => Tenant::STATUS_ACTIVE])
            ->assertUnprocessable();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => Tenant::STATUS_SUSPENDED], 'landlord');
    }

    public function test_platform_administrator_can_create_duplicate_deactivate_and_delete_modular_packages(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Platform Administrator',
            'email' => 'platform@example.test', 'password' => 'secret-password',
        ]);

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.plans.store'), [
                'code' => 'family_learning', 'name' => 'Family Learning', 'description' => 'Students and families',
                'modules' => ['parent_portal'], 'is_active' => '1',
            ])->assertRedirect();

        $plan = Plan::query()->where('code', 'family_learning')->sole();
        $this->assertSame(['parent_portal'], $plan->features()->pluck('code')->all());
        $this->actingAs($administrator, 'platform')->get(route('platform.plans.index'))
            ->assertOk()->assertSee('Family Learning')->assertSee('Parent Portal');
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'plan_created'], 'landlord');

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.plans.duplicate', $plan), ['name' => 'Family Learning Plus'])
            ->assertRedirect();
        $copy = Plan::query()->where('name', 'Family Learning Plus')->sole();
        $this->assertSame(['parent_portal'], $copy->features()->pluck('code')->all());

        $this->actingAs($administrator, 'platform')
            ->patch(route('platform.plans.status', $copy), ['is_active' => '0'])
            ->assertRedirect();
        $this->assertFalse($copy->fresh()->is_active);

        $this->actingAs($administrator, 'platform')
            ->delete(route('platform.plans.destroy', $copy))
            ->assertRedirect(route('platform.plans.index'));
        $this->assertDatabaseMissing('plans', ['id' => $copy->id], 'landlord');
    }

    public function test_assigned_package_requires_preview_confirmation_and_shows_each_affected_tenant(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Platform Administrator',
            'email' => 'platform@example.test', 'password' => 'secret-password',
        ]);
        $plan = Plan::query()->create(['code' => 'students_only', 'name' => 'Students only', 'is_active' => true]);
        $plan->features()->sync([Feature::query()->where('code', 'students')->sole()->id]);
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Affected Centre', 'slug' => 'affected', 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $tenant->subscription()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()]);

        $payload = ['name' => 'Finance package', 'description' => '', 'modules' => ['finance'], 'is_active' => '1'];
        $this->actingAs($administrator, 'platform')->put(route('platform.plans.update', $plan), $payload)
            ->assertSessionHasErrors('confirm_impact');
        $this->assertSame(['students'], $plan->features()->pluck('code')->all());

        $this->actingAs($administrator, 'platform')->put(route('platform.plans.preview', $plan), $payload)
            ->assertOk()->assertSee('Affected Centre')->assertSee('Finance')->assertSee('Students');

        $this->actingAs($administrator, 'platform')->put(route('platform.plans.update', $plan), $payload + ['confirm_impact' => '1'])
            ->assertRedirect(route('platform.plans.edit', $plan));
        $this->assertSame(['finance'], $plan->features()->pluck('code')->all());
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'plan_updated'], 'landlord');
    }

    public function test_tenant_extras_are_previewed_audited_and_do_not_remove_package_modules(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Platform Administrator',
            'email' => 'platform@example.test', 'password' => 'secret-password',
        ]);
        $plan = Plan::query()->create(['code' => 'finance_modular', 'name' => 'Finance', 'is_active' => true]);
        $plan->features()->sync([Feature::query()->where('code', 'finance')->sole()->id]);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'One Centre', 'slug' => 'one', 'status' => Tenant::STATUS_ACTIVE]);
        $other = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Other Centre', 'slug' => 'other', 'status' => Tenant::STATUS_ACTIVE]);
        foreach ([$tenant, $other] as $record) {
            $record->subscription()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()]);
        }
        $access = app(TenantModuleAccess::class);
        $version = $access->snapshot($tenant)['version'];

        $response = $this->actingAs($administrator, 'platform')->get(route('platform.tenants.edit', $tenant));
        $response->assertOk()
            ->assertSee('Tenant-specific extras')
            ->assertSee('Grey checked modules are already supplied by the package')
            ->assertSee('data-package-module="finance"', false)
            ->assertSee('Included by package · manage from the package settings');
        $this->assertMatchesRegularExpression('/<input[^>]+value="finance"[^>]+checked[^>]+disabled/', $response->getContent());

        $this->actingAs($administrator, 'platform')->put(route('platform.tenants.extras.preview', $tenant), [
            'modules' => ['parent_portal'], 'expected_version' => $version,
        ])->assertRedirect()->assertSessionHas('module_preview');

        $this->actingAs($administrator, 'platform')->put(route('platform.tenants.extras.update', $tenant), [
            'modules' => ['parent_portal'], 'expected_version' => $version, 'confirm_extras' => '1',
        ])->assertRedirect(route('platform.tenants.edit', $tenant));
        $snapshot = $access->snapshot($tenant);
        $this->assertContains('finance', $snapshot['enabled']);
        $this->assertContains('parent_portal', $snapshot['enabled']);
        $this->assertSame([], $access->snapshot($other)['extras']);
        $this->assertDatabaseHas('platform_audit_events', ['tenant_id' => $tenant->id, 'event' => 'tenant_module_extras_updated'], 'landlord');

        $this->actingAs($administrator, 'platform')->put(route('platform.tenants.extras.preview', $tenant), [
            'modules' => [], 'expected_version' => $snapshot['version'],
        ])->assertRedirect()->assertSessionHas('module_preview');
        $this->actingAs($administrator, 'platform')->put(route('platform.tenants.extras.update', $tenant), [
            'modules' => [], 'expected_version' => $snapshot['version'], 'confirm_extras' => '1',
        ])->assertRedirect();
        $this->assertContains('finance', $access->snapshot($tenant)['enabled']);
        $this->assertSame([], $access->snapshot($tenant)['extras']);
    }
}
