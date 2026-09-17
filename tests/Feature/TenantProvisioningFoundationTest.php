<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantDatabaseName;
use App\Services\Landlord\TenantStorage;
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
}
