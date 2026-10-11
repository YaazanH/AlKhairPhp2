<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformMetricSnapshot;
use App\Services\Landlord\PlatformManagementMetrics;
use App\Services\Landlord\PlatformMetricHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PlatformMetricHistoryTest extends TestCase
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
        Carbon::setTestNow();
        DB::purge('landlord');

        parent::tearDown();
    }

    public function test_it_records_graphable_snapshots_and_maintains_a_daily_rollup(): void
    {
        Carbon::setTestNow('2026-10-11 12:00:00');
        $history = app(PlatformMetricHistory::class);

        $history->record($this->snapshot(cpu: 20, storage: 1_000), now()->subMinutes(10));
        $history->record($this->snapshot(cpu: 40, storage: 1_500), now());

        $chart = $history->chart('24h');

        $this->assertCount(2, $chart['points']);
        $this->assertSame(20.0, $chart['points'][0]['cpu_average']);
        $this->assertSame(1_500, $chart['points'][1]['tenant_storage_bytes']);
        $this->assertSame('MB', $chart['network_unit']);
        $this->assertDatabaseCount('platform_metric_snapshots', 3, 'landlord');

        $daily = PlatformMetricSnapshot::query()
            ->where('resolution', PlatformMetricSnapshot::RESOLUTION_DAILY)
            ->firstOrFail();
        $this->assertSame(30.0, $daily->cpu_average);
        $this->assertSame(1_500, $daily->tenant_storage_bytes);
    }

    public function test_refresh_due_respects_the_selected_interval(): void
    {
        Carbon::setTestNow('2026-10-11 12:00:00');
        $history = app(PlatformMetricHistory::class);

        $this->assertTrue($history->isRefreshDue(5));
        $history->record($this->snapshot(), now()->subMinutes(4));
        $this->assertFalse($history->isRefreshDue(5));
        $this->assertTrue($history->isRefreshDue(1));
    }

    public function test_scheduled_command_skips_collection_until_the_interval_is_due(): void
    {
        $history = Mockery::mock(PlatformMetricHistory::class);
        $history->shouldReceive('isRefreshDue')->once()->with(5)->andReturnFalse();
        $history->shouldNotReceive('record');
        $metrics = Mockery::mock(PlatformManagementMetrics::class);
        $metrics->shouldNotReceive('refresh');
        $this->app->instance(PlatformMetricHistory::class, $history);
        $this->app->instance(PlatformManagementMetrics::class, $metrics);

        $this->artisan('saas:refresh-platform-metrics', ['--scheduled' => true])
            ->expectsOutputToContain('not due yet (5-minute interval)')
            ->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function snapshot(float $cpu = 25, int $storage = 500): array
    {
        return [
            'tenants' => ['total' => 4, 'active' => 2, 'trial' => 1, 'suspended' => 1, 'provisioning_failed' => 0],
            'storage' => ['tenant_bytes' => $storage, 'server_used_bytes' => 2_000, 'server_used_percent' => 50],
            'backups' => ['latest_success_at' => now()->subHour(), 'failed_last_24_hours' => 0],
            'infrastructure' => [
                'status' => 'available',
                'state' => 'running',
                'cpu' => ['average' => $cpu, 'unit' => '%'],
                'ram' => ['average' => 60, 'unit' => '%'],
                'incoming' => ['total' => 12, 'unit' => 'MB'],
                'outgoing' => ['total' => 7, 'unit' => 'MB'],
            ],
        ];
    }
}
