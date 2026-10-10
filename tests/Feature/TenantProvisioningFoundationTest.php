<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantDatabaseName;
use App\Services\Landlord\TenantResources;
use App\Services\Landlord\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantProvisioningFoundationTest extends TestCase
{
    public function test_database_names_and_storage_paths_are_derived_only_from_the_tenant_uuid(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $tenant = new Tenant([
            'uuid' => (string) Str::uuid(),
            'slug' => 'al-noor',
        ]);

        $databaseName = app(TenantDatabaseName::class)->for($tenant);
        $paths = app(TenantStorage::class)->initialise($tenant);

        $this->assertMatchesRegularExpression('/^alkhair_tenant_[a-f0-9]{32}$/', $databaseName);
        $this->assertSame('tenants/'.strtolower($tenant->uuid), $paths['public']);
        Storage::disk('public')->assertExists($paths['public'].'/logo');
        Storage::disk('public')->assertExists($paths['public'].'/templates');
        Storage::disk('local')->assertExists($paths['private'].'/student-files');
        Storage::disk('local')->assertExists($paths['private'].'/finance');
    }

    public function test_cleanup_removes_tenant_storage_from_both_disks(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $tenant = new Tenant([
            'uuid' => (string) Str::uuid(),
            'slug' => 'failed-centre',
        ]);
        $paths = app(TenantStorage::class)->initialise($tenant);

        DB::shouldReceive('purge')->times(2)->with('tenant');
        DB::shouldReceive('connection')->once()->with('tenant')->andReturnSelf();
        DB::shouldReceive('statement')->once()->with(
            'DROP DATABASE IF EXISTS `'.app(TenantDatabaseName::class)->for($tenant).'`'
        );

        app(TenantResources::class)->delete($tenant);

        Storage::disk('public')->assertMissing($paths['public']);
        Storage::disk('local')->assertMissing($paths['private']);
    }

    public function test_cleanup_does_not_delete_a_database_the_attempt_did_not_create(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $tenant = new Tenant([
            'uuid' => (string) Str::uuid(),
            'slug' => 'existing-database',
        ]);
        $paths = app(TenantStorage::class)->initialise($tenant);

        DB::shouldReceive('purge')->never();
        DB::shouldReceive('connection')->never();

        app(TenantResources::class)->delete($tenant, deleteDatabase: false);

        Storage::disk('public')->assertMissing($paths['public']);
        Storage::disk('local')->assertMissing($paths['private']);
    }
}
