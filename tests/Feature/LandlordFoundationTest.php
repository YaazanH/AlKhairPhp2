<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantFeatureOverride;
use App\Models\Landlord\TenantSubscription;
use App\Http\Middleware\EnsureTenantFeature;
use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantFeatureAccess;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LandlordFoundationTest extends TestCase
{
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

    public function test_landlord_models_use_the_dedicated_connection_and_persist_tenant_metadata(): void
    {
        $platformAdministrator = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'secret-password',
        ]);

        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Al Noor Centre',
            'slug' => 'al-noor',
            'status' => Tenant::STATUS_DRAFT,
        ]);

        $tenant->domains()->create([
            'host' => 'al-noor.alkhair.test',
            'is_primary' => true,
        ]);

        $this->assertSame('landlord', $tenant->getConnectionName());
        $this->assertSame('landlord', $platformAdministrator->getConnectionName());
        $this->assertDatabaseHas('tenants', ['slug' => 'al-noor'], 'landlord');
        $this->assertDatabaseHas('tenant_domains', ['host' => 'al-noor.alkhair.test'], 'landlord');
    }

    public function test_catalog_seeder_creates_the_three_agreed_manual_packages(): void
    {
        $this->seed(LandlordCatalogSeeder::class);

        $this->assertSame(3, Feature::query()->count());
        $this->assertSame(3, Plan::query()->count());
        $this->assertTrue(Feature::query()->where('code', Feature::CORE)->value('is_core'));

        $completePlan = Plan::query()
            ->with('features')
            ->where('code', 'core_finance_printing')
            ->sole();

        $this->assertEqualsCanonicalizing(
            [Feature::CORE, Feature::FINANCE, Feature::CUSTOM_PRINTING],
            $completePlan->features->pluck('code')->all(),
        );
    }

    public function test_feature_access_requires_an_operational_tenant_and_current_subscription_unless_core(): void
    {
        $this->seed(LandlordCatalogSeeder::class);

        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Al Noor Centre',
            'slug' => 'al-noor',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $financePlan = Plan::query()->where('code', 'core_finance')->sole();

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $financePlan->getKey(),
            'status' => TenantSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
        ]);

        $access = app(TenantFeatureAccess::class);

        $this->assertTrue($access->isEnabled($tenant, Feature::CORE));
        $this->assertTrue($access->isEnabled($tenant, Feature::FINANCE));
        $this->assertFalse($access->isEnabled($tenant, Feature::CUSTOM_PRINTING));

        $customPrinting = Feature::query()->where('code', Feature::CUSTOM_PRINTING)->sole();
        TenantFeatureOverride::query()->create([
            'tenant_id' => $tenant->getKey(),
            'feature_id' => $customPrinting->getKey(),
            'is_enabled' => true,
        ]);

        $this->assertTrue($access->isEnabled($tenant, Feature::CUSTOM_PRINTING));

        $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();

        $this->assertFalse($access->isEnabled($tenant->fresh(), Feature::CORE));
        $this->assertFalse($access->isEnabled($tenant->fresh(), Feature::FINANCE));
    }

    public function test_platform_guard_uses_the_landlord_platform_administrator_model(): void
    {
        $this->assertSame('platform_administrators', config('auth.guards.platform.provider'));
        $this->assertSame(PlatformAdministrator::class, config('auth.providers.platform_administrators.model'));
    }

    public function test_tenant_feature_middleware_enforces_the_current_manual_package(): void
    {
        $this->seed(LandlordCatalogSeeder::class);

        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Al Noor Centre',
            'slug' => 'al-noor',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::query()->where('code', 'core_finance')->sole()->id,
            'status' => TenantSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);
        app(TenantContext::class)->set($tenant);

        $response = app(EnsureTenantFeature::class)->handle(request(), fn () => response()->noContent(), Feature::FINANCE);
        $this->assertSame(204, $response->getStatusCode());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(EnsureTenantFeature::class)->handle(request(), fn () => response()->noContent(), Feature::CUSTOM_PRINTING);
    }
}
