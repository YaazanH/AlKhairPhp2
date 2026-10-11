<?php

namespace Tests\Unit;

use App\Services\Landlord\HostingerVpsMetrics;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostingerVpsMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.hostinger.base_url', 'https://developers.hostinger.test/api');
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_it_stays_disabled_without_server_credentials(): void
    {
        config()->set('services.hostinger.token');
        config()->set('services.hostinger.virtual_machine_id');
        Http::fake();

        $this->assertSame(['status' => 'unconfigured'], app(HostingerVpsMetrics::class)->snapshot());
        Http::assertNothingSent();
    }

    public function test_it_fetches_and_normalizes_read_only_vps_metrics(): void
    {
        config()->set('services.hostinger.token', 'test-token');
        config()->set('services.hostinger.virtual_machine_id', 12345);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/metrics?')) {
                return Http::response([
                    'cpu_usage' => ['unit' => '%', 'usage' => ['1' => 20, '2' => 40]],
                    'ram_usage' => ['unit' => '%', 'usage' => ['1' => 50, '2' => 70]],
                    'disk_space' => ['unit' => 'MB', 'usage' => ['1' => 1000, '2' => 1100]],
                    'incoming_traffic' => ['unit' => 'MB', 'usage' => ['1' => 5, '2' => 7]],
                    'outgoing_traffic' => ['unit' => 'MB', 'usage' => ['1' => 3, '2' => 4]],
                ]);
            }

            return Http::response([
                'state' => 'running',
                'hostname' => 'srv12345.hstgr.cloud',
                'plan' => 'KVM 2',
                'cpus' => 2,
                'memory' => 8192,
                'disk' => 102400,
                'bandwidth' => 8388608,
            ]);
        });

        $snapshot = app(HostingerVpsMetrics::class)->snapshot();

        $this->assertSame('available', $snapshot['status']);
        $this->assertSame('running', $snapshot['state']);
        $this->assertSame(30.0, $snapshot['cpu']['average']);
        $this->assertSame(30.0, $snapshot['cpu']['percentage']);
        $this->assertSame(60.0, $snapshot['ram']['average']);
        $this->assertSame(60.0, $snapshot['ram']['percentage']);
        $this->assertSame(12.0, $snapshot['incoming']['total']);
        $this->assertSame(7.0, $snapshot['outgoing']['total']);
        $this->assertSame(2, $snapshot['capacity']['cpus']);
        $this->assertSame(8192 * 1024 * 1024, $snapshot['capacity']['memory_bytes']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_normalizes_byte_based_ram_usage_to_a_percentage(): void
    {
        config()->set('services.hostinger.token', 'test-token');
        config()->set('services.hostinger.virtual_machine_id', 12345);

        Http::fakeSequence()
            ->push(['state' => 'running', 'memory' => 4096])
            ->push(['cpu_usage' => ['unit' => '%', 'usage' => [10]], 'ram_usage' => ['unit' => 'bytes', 'usage' => [2 * 1024 * 1024 * 1024]]])
            ->push([]);

        $snapshot = app(HostingerVpsMetrics::class)->snapshot();

        $this->assertSame(50.0, $snapshot['ram']['percentage']);
        $this->assertSame(2 * 1024 * 1024 * 1024.0, $snapshot['ram']['average']);
    }

    public function test_an_api_failure_never_breaks_the_platform_dashboard(): void
    {
        config()->set('services.hostinger.token', 'test-token');
        config()->set('services.hostinger.virtual_machine_id', 12345);
        Http::fake(['*' => Http::response([], 503)]);

        $this->assertSame(['status' => 'unavailable'], app(HostingerVpsMetrics::class)->snapshot());
    }

    public function test_it_preserves_the_last_successful_snapshot_when_a_refresh_fails(): void
    {
        config()->set('services.hostinger.token', 'test-token');
        config()->set('services.hostinger.virtual_machine_id', 12345);

        $metrics = [
            'cpu_usage' => ['unit' => '%', 'usage' => ['1' => 25]],
            'ram_usage' => ['unit' => '%', 'usage' => ['1' => 40]],
        ];
        Http::fakeSequence()
            ->push(['state' => 'running', 'hostname' => 'srv12345.hstgr.cloud'])
            ->push($metrics)
            ->push($metrics)
            ->push([], 503);

        $service = app(HostingerVpsMetrics::class);
        $this->assertSame('available', $service->snapshot()['status']);

        $service->forgetCache();
        $stale = $service->snapshot();

        $this->assertSame('stale', $stale['status']);
        $this->assertSame('srv12345.hstgr.cloud', $stale['hostname']);
        $this->assertSame(25.0, $stale['cpu']['average']);
    }
}
