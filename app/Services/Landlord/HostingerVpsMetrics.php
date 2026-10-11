<?php

namespace App\Services\Landlord;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HostingerVpsMetrics
{
    private const CACHE_KEY = 'platform:hostinger-vps-metrics:v1';

    private const LAST_SUCCESS_KEY = 'platform:hostinger-vps-metrics:last-success:v1';

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        if (! $this->configured()) {
            return ['status' => 'unconfigured'];
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function (): array {
            try {
                $snapshot = $this->fetch();
                Cache::put(self::LAST_SUCCESS_KEY, $snapshot, now()->addDay());

                return $snapshot;
            } catch (Throwable $exception) {
                Log::warning('Hostinger VPS metrics could not be refreshed.', [
                    'exception' => $exception::class,
                ]);

                $lastSuccess = Cache::get(self::LAST_SUCCESS_KEY);

                return is_array($lastSuccess)
                    ? array_merge($lastSuccess, ['status' => 'stale'])
                    : ['status' => 'unavailable'];
            }
        });
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function configured(): bool
    {
        $virtualMachineId = filter_var(config('services.hostinger.virtual_machine_id'), FILTER_VALIDATE_INT);

        return filled(config('services.hostinger.token'))
            && $virtualMachineId !== false
            && $virtualMachineId > 0;
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $virtualMachineId = (int) config('services.hostinger.virtual_machine_id');
        $endpoint = "vps/v1/virtual-machines/{$virtualMachineId}";
        $now = now()->utc();
        $client = $this->client();
        $details = $client->get($endpoint)->throw()->json();
        $daily = $this->metrics($client, $endpoint, $now->copy()->subDay(), $now);
        $monthly = $this->metrics($client, $endpoint, $now->copy()->startOfMonth(), $now);
        $memoryBytes = isset($details['memory']) ? (int) $details['memory'] * 1024 * 1024 : null;
        $cpu = $this->summarize($this->metric($daily, 'cpu_usage', 'cpuUsage'));
        $ram = $this->summarize($this->metric($daily, 'ram_usage', 'ramUsage'));
        $cpu['percentage'] = $this->percentage($cpu);
        $ram['percentage'] = $this->percentage($ram, $memoryBytes);

        return [
            'status' => 'available',
            'refreshed_at' => $now,
            'state' => $details['state'] ?? null,
            'hostname' => $details['hostname'] ?? null,
            'plan' => $details['plan'] ?? null,
            'capacity' => [
                'cpus' => isset($details['cpus']) ? (int) $details['cpus'] : null,
                'memory_bytes' => $memoryBytes,
                'disk_bytes' => isset($details['disk']) ? (int) $details['disk'] * 1024 * 1024 : null,
                'bandwidth_bytes' => isset($details['bandwidth']) ? (int) $details['bandwidth'] * 1024 * 1024 : null,
            ],
            'cpu' => $cpu,
            'ram' => $ram,
            'disk' => $this->summarize($this->metric($daily, 'disk_space', 'diskSpace')),
            'incoming' => $this->summarize($this->metric($monthly, 'incoming_traffic', 'incomingTraffic')),
            'outgoing' => $this->summarize($this->metric($monthly, 'outgoing_traffic', 'outgoingTraffic')),
        ];
    }

    /** @return array<string, mixed> */
    private function metrics(PendingRequest $client, string $endpoint, Carbon $from, Carbon $to): array
    {
        return $client->get($endpoint.'/metrics', [
            'date_from' => $from->format('Y-m-d\TH:i:s\Z'),
            'date_to' => $to->format('Y-m-d\TH:i:s\Z'),
        ])->throw()->json();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.hostinger.base_url'), '/'))
            ->acceptJson()
            ->withToken((string) config('services.hostinger.token'))
            ->connectTimeout(3)
            ->timeout(8);
    }

    /** @return array<string, mixed> */
    private function metric(array $metrics, string $snakeKey, string $camelKey): array
    {
        $metric = $metrics[$snakeKey] ?? $metrics[$camelKey] ?? [];

        return is_array($metric) ? $metric : [];
    }

    /** @return array{unit: string, latest: ?float, average: ?float, total: ?float, maximum: ?float} */
    private function summarize(array $metric): array
    {
        $usage = collect($metric['usage'] ?? [])
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): float => (float) $value)
            ->values();

        return [
            'unit' => (string) ($metric['unit'] ?? ''),
            'latest' => $usage->isEmpty() ? null : $usage->last(),
            'average' => $usage->isEmpty() ? null : round($usage->average(), 2),
            'total' => $usage->isEmpty() ? null : $usage->sum(),
            'maximum' => $usage->isEmpty() ? null : $usage->max(),
        ];
    }

    /** @param array<string, mixed> $metric */
    private function percentage(array $metric, ?int $capacityBytes = null): ?float
    {
        $average = $metric['average'] ?? null;
        if (! is_numeric($average)) {
            return null;
        }

        $unit = strtolower(trim((string) ($metric['unit'] ?? '')));
        if (in_array($unit, ['%', 'percent', 'percentage'], true)) {
            return round((float) $average, 2);
        }

        if (in_array($unit, ['b', 'byte', 'bytes'], true) && $capacityBytes > 0) {
            return round(min(100, ((float) $average / $capacityBytes) * 100), 2);
        }

        return null;
    }
}
