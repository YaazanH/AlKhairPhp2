<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Http\Middleware\ResolveTenantFromHost;
use App\Services\Landlord\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ResolveTenantFromHostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.base_domain', 'example.test');
        config()->set('database.connections.landlord', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('landlord');
        DB::purge('tenant');

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
        DB::purge('tenant');

        parent::tearDown();
    }

    public function test_registered_active_subdomain_selects_its_tenant_database(): void
    {
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Al Noor Centre',
            'slug' => 'al-noor',
            'database_name' => ':memory:',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'host' => 'al-noor.example.test',
            'is_primary' => true,
        ]);

        $response = app(ResolveTenantFromHost::class)->handle(
            request()->duplicate(server: ['HTTP_HOST' => 'al-noor.example.test']),
            fn () => response()->json([
                'connection' => DB::getDefaultConnection(),
                'tenant' => app(TenantContext::class)->tenant()->slug,
                'public_root' => config('filesystems.disks.public.root'),
                'private_root' => config('filesystems.disks.local.root'),
                'app_url' => config('app.url'),
            ]),
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('tenant', $data['connection']);
        $this->assertSame('al-noor', $data['tenant']);
        $this->assertStringEndsWith('/app/public/tenants/'.strtolower($tenant->uuid), str_replace('\\', '/', $data['public_root']));
        $this->assertStringEndsWith('/app/private/tenants/'.strtolower($tenant->uuid), str_replace('\\', '/', $data['private_root']));
        $this->assertSame('http://al-noor.example.test', $data['app_url']);
    }

    public function test_unknown_or_inactive_tenant_subdomains_are_not_served(): void
    {
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Suspended Centre',
            'slug' => 'suspended',
            'database_name' => ':memory:',
            'status' => Tenant::STATUS_SUSPENDED,
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'host' => 'suspended.example.test',
            'is_primary' => true,
        ]);

        foreach (['missing.example.test', 'suspended.example.test'] as $host) {
            try {
                app(ResolveTenantFromHost::class)->handle(
                    request()->duplicate(server: ['HTTP_HOST' => $host]),
                    fn () => response()->noContent(),
                );
                $this->fail('Expected an unknown or inactive tenant host to be rejected.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
