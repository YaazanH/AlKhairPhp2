<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
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

        $this->post(route('platform.login.store'), [
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('platform.dashboard'));

        $this->assertAuthenticatedAs($administrator, 'platform');

        $this->get(route('platform.dashboard'))
            ->assertOk()
            ->assertSee('Tenant overview')
            ->assertSee('Al Noor Centre')
            ->assertSee('Manage');
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
        $this->get(route('platform.dashboard'))
            ->assertRedirect(route('platform.login'));
    }

    public function test_platform_administrator_can_start_tenant_provisioning_from_the_dashboard(): void
    {
        $this->seed(LandlordCatalogSeeder::class);

        $administrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('saas:provision-tenant', \Mockery::on(fn (array $arguments) => $arguments['--platform-email'] === $administrator->email))
            ->andReturn(Command::SUCCESS);

        $this->actingAs($administrator, 'platform')
            ->post(route('platform.tenants.store'), [
                'name' => 'Al Noor Centre',
                'slug' => 'al-noor',
                'owner_name' => 'Tenant Owner',
                'owner_email' => 'owner@alnoor.test',
                'owner_password' => 'temporary-password',
                'plan' => 'core_finance',
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
            ])->assertRedirect(route('platform.tenants.edit', 'new-name'));

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'New Name', 'slug' => 'new-name'], 'landlord');
        $this->assertDatabaseHas('tenant_domains', ['tenant_id' => $tenant->id, 'host' => 'new-name.'.config('tenancy.base_domain')], 'landlord');

        $this->actingAs($administrator, 'platform')
            ->patch(route('platform.tenants.status', 'new-name'), ['status' => Tenant::STATUS_SUSPENDED])
            ->assertRedirect();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => Tenant::STATUS_SUSPENDED], 'landlord');
    }
}
