<?php

namespace App\Console\Commands;

use App\Services\Landlord\PlatformManagementMetrics;
use Illuminate\Console\Command;

class RefreshPlatformMetricsCommand extends Command
{
    protected $signature = 'saas:refresh-platform-metrics';

    protected $description = 'Refresh cached tenant, storage, backup, and Hostinger VPS management metrics.';

    public function handle(PlatformManagementMetrics $metrics): int
    {
        $snapshot = $metrics->refresh();
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
