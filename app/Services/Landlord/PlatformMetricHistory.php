<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformMetricSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PlatformMetricHistory
{
    public const ALLOWED_RANGES = ['24h', '7d', '30d'];

    public function isRefreshDue(int $minutes): bool
    {
        $latest = PlatformMetricSnapshot::query()
            ->where('resolution', PlatformMetricSnapshot::RESOLUTION_INTERVAL)
            ->latest('captured_at')
            ->value('captured_at');

        return ! $latest || Carbon::parse($latest)->lte(now()->subMinutes(max(1, $minutes)));
    }

    /** @param array<string, mixed> $snapshot */
    public function record(array $snapshot, ?Carbon $capturedAt = null): PlatformMetricSnapshot
    {
        $capturedAt ??= now();
        $record = PlatformMetricSnapshot::query()->create($this->attributes($snapshot, $capturedAt));

        $this->rollUpDay($capturedAt);
        PlatformMetricSnapshot::query()
            ->where('resolution', PlatformMetricSnapshot::RESOLUTION_INTERVAL)
            ->where('captured_at', '<', now()->subDays(30))
            ->delete();
        PlatformMetricSnapshot::query()
            ->where('resolution', PlatformMetricSnapshot::RESOLUTION_DAILY)
            ->where('captured_at', '<', now()->subYear())
            ->delete();

        return $record;
    }

    /** @return array{range: string, points: array<int, array<string, mixed>>, network_unit: ?string} */
    public function chart(string $range): array
    {
        $range = in_array($range, self::ALLOWED_RANGES, true) ? $range : '24h';
        $since = match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        $rows = PlatformMetricSnapshot::query()
            ->where('resolution', PlatformMetricSnapshot::RESOLUTION_INTERVAL)
            ->where('captured_at', '>=', $since)
            ->orderBy('captured_at')
            ->get();
        $rows = $this->sample($rows, 96);

        return [
            'range' => $range,
            'points' => $rows->map(fn (PlatformMetricSnapshot $row): array => [
                'label' => $row->captured_at->format($range === '24h' ? 'H:i' : 'M j H:i'),
                'captured_at' => $row->captured_at->toIso8601String(),
                'cpu_average' => $row->cpu_average,
                'ram_average' => $row->ram_average,
                'server_used_percent' => $row->server_used_percent,
                'tenant_storage_bytes' => $row->tenant_storage_bytes,
                'incoming_total' => $row->incoming_total,
                'outgoing_total' => $row->outgoing_total,
                'tenant_active' => $row->tenant_active,
                'tenant_trial' => $row->tenant_trial,
                'tenant_suspended' => $row->tenant_suspended,
                'tenant_failed' => $row->tenant_failed,
            ])->all(),
            'network_unit' => $rows->last()?->network_unit,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function attributes(array $snapshot, Carbon $capturedAt): array
    {
        $infrastructure = $snapshot['infrastructure'] ?? [];

        return [
            'resolution' => PlatformMetricSnapshot::RESOLUTION_INTERVAL,
            'captured_at' => $capturedAt,
            'tenant_total' => $snapshot['tenants']['total'] ?? 0,
            'tenant_active' => $snapshot['tenants']['active'] ?? 0,
            'tenant_trial' => $snapshot['tenants']['trial'] ?? 0,
            'tenant_suspended' => $snapshot['tenants']['suspended'] ?? 0,
            'tenant_failed' => $snapshot['tenants']['provisioning_failed'] ?? 0,
            'tenant_storage_bytes' => $snapshot['storage']['tenant_bytes'] ?? 0,
            'server_used_bytes' => $snapshot['storage']['server_used_bytes'] ?? null,
            'server_used_percent' => $snapshot['storage']['server_used_percent'] ?? null,
            'cpu_average' => $infrastructure['cpu']['average'] ?? null,
            'ram_average' => $infrastructure['ram']['average'] ?? null,
            'incoming_total' => $infrastructure['incoming']['total'] ?? null,
            'outgoing_total' => $infrastructure['outgoing']['total'] ?? null,
            'network_unit' => $infrastructure['incoming']['unit'] ?? $infrastructure['outgoing']['unit'] ?? null,
            'latest_backup_at' => $snapshot['backups']['latest_success_at'] ?? null,
            'failed_backups' => $snapshot['backups']['failed_last_24_hours'] ?? 0,
            'infrastructure_status' => $infrastructure['status'] ?? null,
            'vps_state' => $infrastructure['state'] ?? null,
        ];
    }

    private function rollUpDay(Carbon $day): void
    {
        $rows = PlatformMetricSnapshot::query()
            ->where('resolution', PlatformMetricSnapshot::RESOLUTION_INTERVAL)
            ->whereBetween('captured_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('captured_at')
            ->get();
        $latest = $rows->last();

        if (! $latest) {
            return;
        }

        $attributes = $latest->only([
            'tenant_total', 'tenant_active', 'tenant_trial', 'tenant_suspended', 'tenant_failed',
            'tenant_storage_bytes', 'server_used_bytes', 'server_used_percent', 'incoming_total',
            'outgoing_total', 'network_unit', 'latest_backup_at', 'failed_backups',
            'infrastructure_status', 'vps_state',
        ]);
        $attributes['cpu_average'] = $rows->whereNotNull('cpu_average')->avg('cpu_average');
        $attributes['ram_average'] = $rows->whereNotNull('ram_average')->avg('ram_average');

        PlatformMetricSnapshot::query()->updateOrCreate([
            'resolution' => PlatformMetricSnapshot::RESOLUTION_DAILY,
            'captured_at' => $day->copy()->startOfDay(),
        ], $attributes);
    }

    /** @param Collection<int, PlatformMetricSnapshot> $rows */
    private function sample(Collection $rows, int $limit): Collection
    {
        if ($rows->count() <= $limit) {
            return $rows;
        }

        $size = (int) ceil($rows->count() / $limit);

        return $rows->chunk($size)->map(fn (Collection $chunk) => $chunk->last())->values();
    }
}
