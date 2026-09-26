<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantFeatureOverride;
use App\Models\User;
use App\Services\Landlord\ModuleRegistry;
use App\Services\Landlord\TenantFeatureAccess;
use App\Services\Landlord\TenantModuleAccess;
use App\Services\Landlord\TenantModuleExtras;
use Database\Seeders\LandlordCatalogSeeder;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantModuleEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(LandlordCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    private function tenant(string $slug = 'noor', string $package = 'core'): Tenant
    {
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => Tenant::STATUS_ACTIVE]);
        $tenant->subscription()->create(['plan_id' => Plan::where('code', $package)->sole()->id, 'status' => 'active', 'starts_at' => now()->subDay()]);

        return $tenant;
    }

    private function actor(): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'test-password', 'is_active' => true]);
    }

    public function test_dependencies_are_transitive_and_keep_both_package_and_extra_provenance(): void
    {
        $registry = app(ModuleRegistry::class);
        $sources = $registry->resolve(['parents'], ['parent_portal', 'parents']);
        $this->assertArrayHasKey('students', $sources);
        $this->assertContains('package', $sources['parents']);
        $this->assertContains('extra', $sources['parents']);
        $this->assertContains('dependency:parent_portal', $sources['parents']);
        $this->assertArrayNotHasKey('memorization', $registry->resolve(['quran_tests']));
        $this->assertArrayNotHasKey('students', $registry->resolve(['finance']));
    }

    public function test_invalid_graphs_and_unavailable_dependencies_fail_closed(): void
    {
        $registry = app(ModuleRegistry::class);
        foreach ([[['unknown'], []], [['parents'], ['students']]] as [$roots, $unavailable]) {
            try {
                $registry->resolve($roots, [], $unavailable);
                $this->fail('Invalid module selection was accepted.');
            } catch (DomainException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $idCards = $registry->resolve(['id_cards']);
        $this->assertArrayHasKey('id_cards', $idCards);
        $this->assertArrayHasKey('students', $idCards);
        config()->set('modules.definitions.students.requires', ['parents']);
        $this->expectException(DomainException::class);
        $registry->resolve(['parents']);
    }

    public function test_extras_are_isolated_audited_and_cannot_subtract_package_modules(): void
    {
        $tenant = $this->tenant('noor', 'core_finance');
        $other = $this->tenant('rahma');
        $access = app(TenantModuleAccess::class);
        $extras = app(TenantModuleExtras::class);
        $actor = $this->actor();
        $before = $access->snapshot($tenant);
        $after = $extras->replace($tenant, ['finance', 'custom_templates'], $actor, $before['version']);
        $this->assertContains('custom_templates', $after['enabled']);
        $this->assertContains('extra', $after['sources']['finance']);
        $this->assertContains('package', $after['sources']['finance']);
        $this->assertNotSame($before['version'], $after['version']);
        $this->assertFalse($access->isEnabled($other, 'custom_templates'));
        $removed = $extras->replace($tenant, [], $actor, $after['version']);
        $this->assertContains('finance', $removed['enabled']);
        $this->assertNotContains('custom_templates', $removed['enabled']);
        $this->assertDatabaseCount('platform_audit_events', 2, 'landlord');
    }

    public function test_stale_preview_does_not_write_extras(): void
    {
        $tenant = $this->tenant();
        $access = app(TenantModuleAccess::class);
        $preview = $access->snapshot($tenant);
        $tenant->subscription()->update(['status' => 'suspended']);
        try {
            app(TenantModuleExtras::class)->replace($tenant, ['custom_templates'], $this->actor(), $preview['version']);
            $this->fail('Stale preview was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Refresh', $exception->getMessage());
        }
        $this->assertDatabaseCount('tenant_feature_overrides', 0, 'landlord');
        $this->assertDatabaseCount('platform_audit_events', 0, 'landlord');
    }

    public function test_lifecycle_is_checked_before_positive_overrides_even_with_loaded_relations(): void
    {
        $tenant = $this->tenant();
        TenantFeatureOverride::create(['tenant_id' => $tenant->id, 'feature_id' => Feature::where('code', 'finance')->sole()->id, 'is_enabled' => true]);
        $tenant->load('subscription.plan.features');
        $modules = app(TenantModuleAccess::class);
        $legacy = app(TenantFeatureAccess::class);
        $this->assertTrue($legacy->isEnabled($tenant, 'finance'));
        foreach ([['starts_at' => now()->addDay()], ['starts_at' => now()->subDay(), 'ends_at' => now()->subMinute()], ['ends_at' => null, 'status' => 'suspended']] as $state) {
            $tenant->subscription()->update($state);
            $this->assertFalse($legacy->isEnabled($tenant, 'finance'));
            $this->assertFalse($modules->isEnabled($tenant, 'finance'));
        }
        $tenant->subscription()->update(['status' => 'active', 'starts_at' => now()->subDay()]);
        $this->assertTrue($modules->isEnabled($tenant, 'finance'));
        Tenant::whereKey($tenant->id)->update(['status' => 'suspended']);
        $this->assertSame([], $modules->snapshot($tenant)['enabled']);
    }

    public function test_legacy_negative_overrides_are_reported_without_mutation(): void
    {
        $tenant = $this->tenant('noor', 'core_finance');
        TenantFeatureOverride::create(['tenant_id' => $tenant->id, 'feature_id' => Feature::where('code', 'finance')->sole()->id, 'is_enabled' => false]);
        $before = $tenant->featureOverrides()->get()->toJson();
        $this->artisan('saas:preview-modules', ['--tenant' => 'noor'])->assertExitCode(1);
        $this->assertSame($before, $tenant->featureOverrides()->get()->toJson());
        $this->assertFalse(app(TenantFeatureAccess::class)->isEnabled($tenant, 'finance'));
        $this->assertNotEmpty(app(TenantModuleAccess::class)->snapshot($tenant)['errors']);
    }

    public function test_repeat_seeding_preserves_package_and_catalog_edits(): void
    {
        $plan = Plan::where('code', 'core_finance')->sole();
        $plan->update(['name' => 'Custom package', 'is_active' => false]);
        $plan->features()->sync([Feature::where('code', 'core')->sole()->id]);
        Feature::where('code', 'custom_printing')->update(['is_active' => false]);
        $this->seed(LandlordCatalogSeeder::class);
        $this->assertSame('Custom package', $plan->fresh()->name);
        $this->assertFalse($plan->fresh()->is_active);
        $this->assertSame(['core'], $plan->fresh()->features->pluck('code')->all());
        $this->assertFalse(Feature::where('code', 'custom_printing')->sole()->is_active);
    }

    public function test_role_bypass_cannot_grant_an_unavailable_module_or_unknown_action(): void
    {
        $tenant = $this->tenant();
        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->andReturn(true);
        $access = app(TenantModuleAccess::class);
        $this->assertFalse($access->allows($tenant, $user, 'invoices.view'));
        $this->assertFalse($access->allows($tenant, $user, 'unclassified.action'));
        $this->assertTrue($access->allows($tenant, $user, 'students.view'));
    }

    public function test_legacy_preview_preserves_full_package_and_has_no_writes(): void
    {
        $tenant = $this->tenant('noor', 'core_finance_printing');
        $before = Feature::count();
        $this->artisan('saas:preview-modules', ['--tenant' => 'noor'])->assertSuccessful();
        $this->assertSame($before, Feature::count());
        $snapshot = app(TenantModuleAccess::class)->snapshot($tenant);
        foreach (['parent_portal', 'finance', 'student_billing', 'id_cards', 'custom_templates', 'public_website'] as $module) {
            $this->assertContains($module, $snapshot['enabled']);
        }
        $this->assertDatabaseCount('platform_audit_events', 0, 'landlord');
    }

    public function test_modular_packages_do_not_inherit_legacy_core_and_extras_survive_package_changes(): void
    {
        $plan = Plan::create(['code' => 'modular', 'name' => 'Modular', 'is_active' => true]);
        $plan->features()->sync([Feature::where('code', 'finance')->sole()->id]);
        $tenant = $this->tenant('noor', 'modular');
        $access = app(TenantModuleAccess::class);
        $this->assertSame(['finance', 'foundation'], $access->snapshot($tenant)['enabled']);
        $before = $access->snapshot($tenant);
        $after = app(TenantModuleExtras::class)->replace($tenant, ['parent_portal'], $this->actor(), $before['version']);
        $this->assertContains('students', $after['enabled']);
        $this->assertNotContains('student_billing', $after['enabled']);
        $plan->update(['is_active' => false]);
        $this->assertContains('finance', $access->snapshot($tenant)['enabled']);
        $tenant->subscription()->update(['plan_id' => Plan::where('code', 'core')->sole()->id]);
        $this->assertSame(['parent_portal'], $access->snapshot($tenant)['extras']);
        $this->assertContains('extra', $access->snapshot($tenant)['sources']['parent_portal']);
    }

    public function test_unavailable_dependency_prevents_extra_write_and_audit(): void
    {
        $tenant = $this->tenant();
        Feature::query()->where('code', 'students')->update(['is_active' => false]);
        $before = app(TenantModuleAccess::class)->snapshot($tenant);
        try {
            app(TenantModuleExtras::class)->replace($tenant, ['parent_portal'], $this->actor(), $before['version']);
            $this->fail('Unavailable prerequisite was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Unavailable module: students', $exception->getMessage());
        }
        $this->assertDatabaseCount('tenant_feature_overrides', 0, 'landlord');
        $this->assertDatabaseCount('platform_audit_events', 0, 'landlord');
    }

    public function test_inactive_platform_administrator_cannot_change_extras(): void
    {
        $tenant = $this->tenant();
        $actor = $this->actor();
        $actor->update(['is_active' => false]);
        $this->expectException(DomainException::class);
        app(TenantModuleExtras::class)->replace($tenant, ['custom_templates'], $actor, app(TenantModuleAccess::class)->snapshot($tenant)['version']);
    }

    public function test_existing_feature_entry_point_routes_new_codes_to_the_registry(): void
    {
        $tenant = $this->tenant();
        $gate = app(TenantFeatureAccess::class);
        $this->assertTrue($gate->isEnabled($tenant, 'parent_portal'));
        $this->assertFalse($gate->isEnabled($tenant, 'student_billing'));
        Feature::create(['code' => 'unknown', 'name' => 'Unknown', 'is_active' => true, 'is_core' => true]);
        $this->assertFalse($gate->isEnabled($tenant, 'unknown'));
    }
}
