<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantFeatureOverride;
use App\Services\Landlord\LegacyModuleConversionPlanner;
use App\Services\Landlord\ReleaseRehearsal;
use App\Services\Landlord\TenantStorage;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleaseRehearsalTest extends TestCase
{
    private string $tenantDatabase;

    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(LandlordCatalogSeeder::class);

        $this->tenantDatabase = storage_path('framework/testing/rehearsal-'.Str::uuid().'.sqlite');
        File::ensureDirectoryExists(dirname($this->tenantDatabase));
        File::put($this->tenantDatabase, '');
        config()->set('database.connections.tenant', [
            'driver' => 'sqlite', 'database' => $this->tenantDatabase, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->tenant) {
            $root = app(TenantStorage::class)->root($this->tenant);
            File::deleteDirectory(storage_path('app/public/'.$root));
            File::deleteDirectory(storage_path('app/private/'.$root));
        }
        DB::purge('tenant');
        DB::purge('landlord');
        File::delete($this->tenantDatabase);
        parent::tearDown();
    }

    public function test_rehearsal_is_read_only_and_ready_for_a_consistent_sqlite_tenant(): void
    {
        $tenant = $this->tenant('ready');
        app(TenantStorage::class)->initialise($tenant);
        $before = $this->landlordSnapshot();

        $report = app(ReleaseRehearsal::class)->run('ready');

        $this->assertTrue($report['read_only']);
        $this->assertTrue($report['ready'], implode('; ', $report['blockers']));
        $this->assertSame('ok', $report['tenants'][0]['database']['integrity']['status']);
        $this->assertSame([], $report['tenants'][0]['database']['pending_migrations']);
        $this->assertTrue($report['tenants'][0]['conversion']['preserves_effective_access']);
        $this->assertSame($before, $this->landlordSnapshot());
    }

    public function test_rehearsal_reports_negative_overrides_without_changing_them(): void
    {
        $tenant = $this->tenant('blocked');
        app(TenantStorage::class)->initialise($tenant);
        $override = TenantFeatureOverride::create([
            'tenant_id' => $tenant->id,
            'feature_id' => Feature::where('code', 'finance')->sole()->id,
            'is_enabled' => false,
        ]);
        $before = DB::connection('landlord')->table('tenant_feature_overrides')->where('id', $override->id)->first();

        $report = app(ReleaseRehearsal::class)->run('blocked');

        $this->assertFalse($report['ready']);
        $this->assertStringContainsString('Negative overrides require a manual decision', implode('; ', $report['blockers']));
        $this->assertEquals($before, DB::connection('landlord')->table('tenant_feature_overrides')->where('id', $override->id)->first());
    }

    public function test_conversion_planner_expands_legacy_package_and_positive_extras(): void
    {
        $tenant = $this->tenant('conversion', 'core_finance');
        TenantFeatureOverride::create([
            'tenant_id' => $tenant->id,
            'feature_id' => Feature::where('code', 'custom_printing')->sole()->id,
            'is_enabled' => true,
        ]);

        $plan = app(LegacyModuleConversionPlanner::class)->tenant($tenant);

        $this->assertContains('student_billing', $plan['target_package_modules']);
        $this->assertContains('custom_templates', $plan['target_extra_modules']);
        $this->assertContains('id_cards', $plan['target_extra_modules']);
        $this->assertTrue($plan['preserves_effective_access']);
    }

    public function test_command_fails_for_unknown_tenant_and_states_that_it_is_read_only(): void
    {
        $this->artisan('saas:rehearse-release', ['--tenant' => 'missing'])
            ->assertFailed()
            ->expectsOutputToContain('Tenant not found: missing')
            ->expectsOutputToContain('No database, package, override, invoice, or storage changes were made.');
    }

    public function test_tenant_migration_command_can_target_and_preview_without_writes(): void
    {
        $this->tenant('preview');
        $before = DB::connection('tenant')->table('migrations')->orderBy('id')->get()->toJson();

        $this->artisan('saas:migrate-tenants', ['--tenant' => 'preview', '--pretend' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Previewed preview.')
            ->expectsOutputToContain('Previewed 1 tenant database(s).');

        $this->assertSame($before, DB::connection('tenant')->table('migrations')->orderBy('id')->get()->toJson());
        $this->artisan('saas:migrate-tenants', ['--tenant' => 'missing', '--pretend' => true])->assertFailed();
    }

    private function tenant(string $slug, string $plan = 'core'): Tenant
    {
        $this->tenant = Tenant::create([
            'uuid' => (string) Str::uuid(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'database_name' => $this->tenantDatabase,
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $this->tenant->subscription()->create([
            'plan_id' => Plan::where('code', $plan)->sole()->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
        ]);

        return $this->tenant;
    }

    private function landlordSnapshot(): array
    {
        return [
            'plans' => DB::connection('landlord')->table('plans')->orderBy('id')->get()->toJson(),
            'plan_features' => DB::connection('landlord')->table('plan_feature')->orderBy('plan_id')->orderBy('feature_id')->get()->toJson(),
            'overrides' => DB::connection('landlord')->table('tenant_feature_overrides')->orderBy('id')->get()->toJson(),
            'audits' => DB::connection('landlord')->table('platform_audit_events')->orderBy('id')->get()->toJson(),
        ];
    }
}
