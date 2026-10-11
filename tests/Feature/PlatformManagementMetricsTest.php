<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantBackup;
use App\Services\Landlord\HostingerVpsMetrics;
use App\Services\Landlord\PlatformManagementMetrics;
use App\Services\Landlord\TenantDatabaseName;
use App\Services\Landlord\TenantStorageUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PlatformManagementMetricsTest extends TestCase
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
        Cache::flush();

        Artisan::call('migrate', [
            '--database' => 'landlord',
            '--path' => database_path('migrations/landlord'),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        DB::purge('landlord');

        parent::tearDown();
    }

    public function test_snapshot_reports_tenant_storage_and_backup_health_without_failing_for_one_unavailable_tenant(): void
    {
        $active = $this->tenant('active-centre', Tenant::STATUS_ACTIVE, provisioned: true);
        $this->tenant('trial-centre', Tenant::STATUS_TRIAL);
        $this->tenant('suspended-centre', Tenant::STATUS_SUSPENDED);
        $unavailable = $this->tenant('offline-centre', Tenant::STATUS_PROVISIONING_FAILED, provisioned: true);

        TenantBackup::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $active->id,
            'disk' => 'local',
            'file_path' => 'saas-backups/active.zip',
            'filename' => 'active.zip',
            'trigger' => TenantBackup::TRIGGER_SCHEDULED,
            'status' => TenantBackup::STATUS_COMPLETED,
            'verified_at' => now()->subHour(),
        ]);
        TenantBackup::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $unavailable->id,
            'disk' => 'local',
            'file_path' => '',
            'filename' => '',
            'trigger' => TenantBackup::TRIGGER_SCHEDULED,
            'status' => TenantBackup::STATUS_FAILED,
        ]);

        $usage = Mockery::mock(TenantStorageUsage::class);
        $usage->shouldReceive('for')->once()->with(Mockery::on(fn (Tenant $tenant): bool => $tenant->is($active)))->andReturn(['total' => 12_345]);
        $usage->shouldReceive('for')->once()->with(Mockery::on(fn (Tenant $tenant): bool => $tenant->is($unavailable)))->andThrow(new RuntimeException('database unavailable'));

        $hostinger = Mockery::mock(HostingerVpsMetrics::class);
        $hostinger->shouldReceive('snapshot')->once()->andReturn(['status' => 'unconfigured']);

        $snapshot = (new PlatformManagementMetrics($usage, $hostinger))->snapshot();

        $this->assertSame([
            'total' => 4,
            'active' => 1,
            'trial' => 1,
            'suspended' => 1,
            'provisioning_failed' => 1,
        ], $snapshot['tenants']);
        $this->assertSame(12_345, $snapshot['storage']['tenant_bytes']);
        $this->assertSame(1, $snapshot['storage']['scanned_tenants']);
        $this->assertSame(1, $snapshot['storage']['unavailable_tenants']);
        $this->assertNotNull($snapshot['backups']['latest_success_at']);
        $this->assertSame(1, $snapshot['backups']['failed_last_24_hours']);
        $this->assertSame('unconfigured', $snapshot['infrastructure']['status']);
        $this->assertContains('provisioning_failed', array_column($snapshot['alerts'], 'code'));
        $this->assertContains('tenant_storage_unavailable', array_column($snapshot['alerts'], 'code'));
        $this->assertContains('backup_failed', array_column($snapshot['alerts'], 'code'));
    }

    public function test_snapshot_turns_hostinger_thresholds_and_server_state_into_actionable_alerts(): void
    {
        $usage = Mockery::mock(TenantStorageUsage::class);
        $usage->shouldNotReceive('for');
        $hostinger = Mockery::mock(HostingerVpsMetrics::class);
        $hostinger->shouldReceive('snapshot')->once()->andReturn([
            'status' => 'available',
            'state' => 'stopped',
            'cpu' => ['unit' => '%', 'average' => 95, 'percentage' => 95],
            'ram' => ['unit' => 'bytes', 'average' => 7_300_000_000, 'percentage' => 85],
        ]);

        $snapshot = (new PlatformManagementMetrics($usage, $hostinger))->snapshot();
        $alerts = collect($snapshot['alerts'])->keyBy('code');

        $this->assertSame('critical', $alerts['vps_not_running']['severity']);
        $this->assertSame('critical', $alerts['cpu_critical']['severity']);
        $this->assertSame('warning', $alerts['ram_warning']['severity']);
    }

    private function tenant(string $slug, string $status, bool $provisioned = false): Tenant
    {
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'status' => $status,
        ]);

        if ($provisioned) {
            $tenant->forceFill([
                'database_name' => app(TenantDatabaseName::class)->for($tenant),
            ])->save();
        }

        return $tenant;
    }
}
