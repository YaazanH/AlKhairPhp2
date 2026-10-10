<?php

namespace App\Console\Commands;

use App\Models\Landlord\SaasBackupSetting;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantBackupCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class CreateTenantBackupsCommand extends Command
{
    protected $signature = 'saas:backup-tenants {--tenant= : Back up one tenant by slug}';

    protected $description = 'Create complete encrypted backups for operational SaaS tenants when the Platform schedule is due.';

    public function handle(TenantBackupCoordinator $backups): int
    {
        $setting = SaasBackupSetting::current();
        $slug = $this->option('tenant');

        if (! $slug && (! $setting->is_enabled || ! $this->isDue($setting->run_at))) {
            return self::SUCCESS;
        }

        $query = Tenant::query()->whereIn('status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE]);
        if ($slug) {
            $query->where('slug', $slug);
        }

        $failed = false;
        $query->orderBy('id')->each(function (Tenant $tenant) use ($backups, &$failed): void {
            try {
                $backup = $backups->create($tenant);
                $this->line("Backed up [{$tenant->slug}] as {$backup->filename}.");
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Backup failed for [{$tenant->slug}]: {$exception->getMessage()}");
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function isDue(string $runAt): bool
    {
        $now = now();
        $scheduled = Carbon::parse($runAt, $now->getTimezone());

        return $now->format('H:i') === $scheduled->format('H:i');
    }
}
