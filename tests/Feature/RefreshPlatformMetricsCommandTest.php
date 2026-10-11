<?php

namespace Tests\Feature;

use App\Services\Landlord\PlatformManagementMetrics;
use Mockery;
use Tests\TestCase;

class RefreshPlatformMetricsCommandTest extends TestCase
{
    public function test_command_refreshes_cached_metrics_without_requiring_hostinger_configuration(): void
    {
        $metrics = Mockery::mock(PlatformManagementMetrics::class);
        $metrics->shouldReceive('refresh')->once()->andReturn([
            'tenants' => ['total' => 3],
            'storage' => ['unavailable_tenants' => 0],
            'infrastructure' => ['status' => 'unconfigured'],
        ]);
        $this->app->instance(PlatformManagementMetrics::class, $metrics);

        $this->artisan('saas:refresh-platform-metrics')
            ->expectsOutputToContain('Platform metrics refreshed. 3 tenant(s); 0 unavailable storage scan(s).')
            ->expectsOutputToContain('Hostinger metrics are not configured.')
            ->assertSuccessful();
    }

    public function test_command_fails_when_no_hostinger_snapshot_is_available(): void
    {
        $metrics = Mockery::mock(PlatformManagementMetrics::class);
        $metrics->shouldReceive('refresh')->once()->andReturn([
            'tenants' => ['total' => 1],
            'storage' => ['unavailable_tenants' => 0],
            'infrastructure' => ['status' => 'unavailable'],
        ]);
        $this->app->instance(PlatformManagementMetrics::class, $metrics);

        $this->artisan('saas:refresh-platform-metrics')
            ->expectsOutputToContain('Hostinger metrics are unavailable.')
            ->assertFailed();
    }
}
