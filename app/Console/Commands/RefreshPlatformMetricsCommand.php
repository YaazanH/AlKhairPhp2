<?php

namespace App\Console\Commands;

use App\Models\Landlord\SaasPlatformSetting;
use App\Services\Landlord\PlatformManagementMetrics;
use App\Services\Landlord\PlatformMetricHistory;
use Illuminate\Console\Command;

class RefreshPlatformMetricsCommand extends Command
{
    protected $signature = 'saas:refresh-platform-metrics {--scheduled : Refresh only when the configured interval is due}';

    protected $description = 'Refresh cached tenant, storage, backup, and Hostinger VPS management metrics.';

    public function handle(PlatformManagementMetrics $metrics, PlatformMetricHistory $history): int
    {
        if ($this->option('scheduled')) {
            $refreshMinutes = SaasPlatformSetting::current()->metrics_refresh_minutes;
            if (! $history->isRefreshDue($refreshMinutes)) {
                $this->info("Platform metrics are not due yet ({$refreshMinutes}-minute interval).");

                return self::SUCCESS;
            }
        }

        $snapshot = $metrics->refresh();
        $history->record($snapshot);
        $infrastructureStatus = $snapshot['infrastructure']['status'] ?? 'unavailable';

        $this->info("Platform metrics refreshed. {$snapshot['tenants']['total']} tenant(s); {$snapshot['storage']['unavailable_tenants']} unavailable storage scan(s).");

        if ($infrastructureStatus === 'unconfigured') {
            $this->warn('Hostinger metrics are not configured.');
        } elseif ($infrastructureStatus === 'unavailable') {
            $this->error('Hostinger metrics are unavailable.');

            return self::FAILURE;
        } elseif ($infrastructureStatus === 'stale') {
            $this->warn('Hostinger refresh failed; the last successful snapshot is being preserved.');
        }

        return self::SUCCESS;
    }
}
