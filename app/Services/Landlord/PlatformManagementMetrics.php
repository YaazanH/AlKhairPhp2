<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PlatformManagementMetrics
{
    private const STORAGE_CACHE_KEY = 'platform:management-dashboard:storage:v1';

    public function __construct(
        private readonly TenantStorageUsage $tenantStorage,
        private readonly HostingerVpsMetrics $hostinger,
    ) {}

    /**
     * @return array{
     *     tenants: array{total: int, active: int, trial: int, suspended: int, provisioning_failed: int},
     *     storage: array{tenant_bytes: int, scanned_tenants: int, unavailable_tenants: int, server_total_bytes: ?int, server_used_bytes: ?int, server_free_bytes: ?int, server_used_percent: ?float},
     *     backups: array{latest_success_at: mixed, failed_last_24_hours: int},
     *     infrastructure: array<string, mixed>,
     *     alerts: array<int, array{code: string, severity: string}>
     * }
     */
    public function snapshot(): array
    {
        $snapshot = [
            'tenants' => $this->tenantCounts(),
            'storage' => $this->storage(),
            'backups' => [
                'latest_success_at' => TenantBackup::query()
                    ->where('status', TenantBackup::STATUS_COMPLETED)
                    ->whereNotNull('verified_at')
                    ->latest('verified_at')
                    ->value('verified_at'),
                'failed_last_24_hours' => TenantBackup::query()
                    ->where('status', TenantBackup::STATUS_FAILED)
                    ->where('updated_at', '>=', now()->subDay())
                    ->count(),
            ],
            'infrastructure' => $this->hostinger->snapshot(),
        ];

        $snapshot['alerts'] = $this->alerts($snapshot);

        return $snapshot;
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        $this->forgetStorageCache();
        $this->hostinger->forgetCache();

        return $this->snapshot();
    }

    public function forgetStorageCache(): void
    {
        Cache::forget(self::STORAGE_CACHE_KEY);
    }

    /** @return array{total: int, active: int, trial: int, suspended: int, provisioning_failed: int} */
    private function tenantCounts(): array
    {
        $counts = Tenant::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[Tenant::STATUS_ACTIVE] ?? 0),
            'trial' => (int) ($counts[Tenant::STATUS_TRIAL] ?? 0),
            'suspended' => (int) ($counts[Tenant::STATUS_SUSPENDED] ?? 0),
            'provisioning_failed' => (int) ($counts[Tenant::STATUS_PROVISIONING_FAILED] ?? 0),
        ];
    }

    /**
     * @return array{tenant_bytes: int, scanned_tenants: int, unavailable_tenants: int, server_total_bytes: ?int, server_used_bytes: ?int, server_free_bytes: ?int, server_used_percent: ?float}
     */
    private function storage(): array
    {
        return Cache::remember(self::STORAGE_CACHE_KEY, now()->addMinutes(5), function (): array {
            $tenantBytes = 0;
            $scannedTenants = 0;
            $unavailableTenants = 0;

            Tenant::query()
                ->whereNotNull('database_name')
                ->where('database_name', '!=', '')
                ->orderBy('id')
                ->each(function (Tenant $tenant) use (&$tenantBytes, &$scannedTenants, &$unavailableTenants): void {
                    try {
                        $tenantBytes += $this->tenantStorage->for($tenant)['total'];
                        $scannedTenants++;
                    } catch (Throwable) {
                        $unavailableTenants++;
                    }
                });

            $serverTotal = $this->diskBytes('total');
            $serverFree = $this->diskBytes('free');
            $serverUsed = $serverTotal !== null && $serverFree !== null
                ? max(0, $serverTotal - $serverFree)
                : null;

            return [
                'tenant_bytes' => $tenantBytes,
                'scanned_tenants' => $scannedTenants,
                'unavailable_tenants' => $unavailableTenants,
                'server_total_bytes' => $serverTotal,
                'server_used_bytes' => $serverUsed,
                'server_free_bytes' => $serverFree,
                'server_used_percent' => $serverUsed !== null && $serverTotal > 0
                    ? round(($serverUsed / $serverTotal) * 100, 1)
                    : null,
            ];
        });
    }

    private function diskBytes(string $type): ?int
    {
        $bytes = $type === 'total'
            ? @disk_total_space(storage_path())
            : @disk_free_space(storage_path());

        return $bytes === false ? null : (int) $bytes;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{code: string, severity: string}>
     */
    private function alerts(array $snapshot): array
    {
        $alerts = [];
        $add = function (string $code, string $severity = 'warning') use (&$alerts): void {
            $alerts[] = ['code' => $code, 'severity' => $severity];
        };

        if ($snapshot['tenants']['provisioning_failed'] > 0) {
            $add('provisioning_failed');
        }

        if ($snapshot['storage']['unavailable_tenants'] > 0) {
            $add('tenant_storage_unavailable');
        }

        $diskPercent = $snapshot['storage']['server_used_percent'];
        if ($diskPercent !== null && $diskPercent >= (float) config('platform-monitoring.disk_critical_percent', 90)) {
            $add('server_disk_critical', 'critical');
        } elseif ($diskPercent !== null && $diskPercent >= (float) config('platform-monitoring.disk_warning_percent', 80)) {
            $add('server_disk_warning');
        }

        if ($snapshot['backups']['failed_last_24_hours'] > 0) {
            $add('backup_failed', 'critical');
        }

        $operationalTenants = $snapshot['tenants']['active'] + $snapshot['tenants']['trial'];
        $latestBackup = $snapshot['backups']['latest_success_at'];
        if ($operationalTenants > 0 && ! $latestBackup) {
            $add('backup_missing', 'critical');
        } elseif ($latestBackup) {
            $backupAge = Carbon::parse($latestBackup)->diffInHours(now());
            if ($backupAge >= (int) config('platform-monitoring.backup_critical_hours', 48)) {
                $add('backup_stale_critical', 'critical');
            } elseif ($backupAge >= (int) config('platform-monitoring.backup_warning_hours', 26)) {
                $add('backup_stale');
            }
        }

        $infrastructure = $snapshot['infrastructure'];
        if (($infrastructure['status'] ?? null) === 'unavailable') {
            $add('hostinger_unavailable');
        } elseif (($infrastructure['status'] ?? null) === 'stale') {
            $add('hostinger_stale');
        }

        if (isset($infrastructure['state']) && $infrastructure['state'] !== 'running') {
            $add('vps_not_running', 'critical');
        }

        $this->addPercentageAlert($alerts, $infrastructure['cpu'] ?? [], 'cpu');
        $this->addPercentageAlert($alerts, $infrastructure['ram'] ?? [], 'ram');

        return $alerts;
    }

    /** @param array<int, array{code: string, severity: string}> $alerts */
    private function addPercentageAlert(array &$alerts, array $metric, string $name): void
    {
        if (! in_array(strtolower((string) ($metric['unit'] ?? '')), ['%', 'percent', 'percentage'], true)) {
            return;
        }

        $average = $metric['average'] ?? null;
        if (! is_numeric($average)) {
            return;
        }

        if ($average >= (float) config("platform-monitoring.{$name}_critical_percent", 90)) {
            $alerts[] = ['code' => "{$name}_critical", 'severity' => 'critical'];
        } elseif ($average >= (float) config("platform-monitoring.{$name}_warning_percent", 80)) {
            $alerts[] = ['code' => "{$name}_warning", 'severity' => 'warning'];
        }
    }
}
