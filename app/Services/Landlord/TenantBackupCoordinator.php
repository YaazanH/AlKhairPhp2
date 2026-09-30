<?php

namespace App\Services\Landlord;

use App\Models\Landlord\SaasBackupSetting;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantBackup;
use App\Models\SystemBackup;
use App\Services\SystemBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TenantBackupCoordinator
{
    public function __construct(
        private readonly SystemBackupService $backups,
        private readonly TenantStorage $storage,
        private readonly TenantStorageUsage $usage,
    ) {}

    /** Create one complete, verified backup for a tenant. */
    public function create(Tenant $tenant, string $trigger = TenantBackup::TRIGGER_SCHEDULED): TenantBackup
    {
        if (! $tenant->isOperational() || blank($tenant->database_name)) {
            throw new RuntimeException('Only active or trial tenants with a provisioned database can be backed up.');
        }

        $record = TenantBackup::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'disk' => (string) config('backups.disk', 'local'),
            'file_path' => '',
            'filename' => '',
            'trigger' => $trigger,
            'status' => TenantBackup::STATUS_CREATING,
        ]);

        try {
            $sourceBackup = $this->withinTenant($tenant, function () use ($trigger): SystemBackup {
                return $this->backups->create(
                    creator: null,
                    trigger: $this->systemTriggerFor($trigger),
                    scope: SystemBackup::SCOPE_FULL,
                    applyRetention: false,
                );
            });

            $record->forceFill([
                'source_backup_uuid' => $sourceBackup->uuid,
                'disk' => $sourceBackup->disk,
                'file_path' => $sourceBackup->file_path,
                'filename' => $sourceBackup->filename,
                'size_bytes' => $sourceBackup->size_bytes,
                'sha256' => $sourceBackup->sha256,
                'manifest_summary' => $sourceBackup->manifest_summary,
                'verified_at' => $sourceBackup->verified_at,
                'status' => TenantBackup::STATUS_COMPLETED,
                'error_message' => null,
            ])->save();

            $this->enforceScheduledRetention($tenant);
            $this->enforceStorageLimit($tenant, $record);

            return $record->fresh();
        } catch (Throwable $exception) {
            if ($record->exists) {
                $record->forceFill([
                    'status' => TenantBackup::STATUS_FAILED,
                    'error_message' => Str::limit($exception->getMessage(), 1000),
                ])->save();
            }

            throw $exception;
        }
    }

    /** Only scheduled archives are removed; manual and safety backups stay. */
    public function enforceScheduledRetention(Tenant $tenant): void
    {
        $count = max(1, SaasBackupSetting::current()->retention_count);
        $scheduled = TenantBackup::query()
            ->where('tenant_id', $tenant->id)
            ->where('trigger', TenantBackup::TRIGGER_SCHEDULED)
            ->where('status', TenantBackup::STATUS_COMPLETED)
            ->oldest('created_at')
            ->get();

        while ($scheduled->count() > $count) {
            $this->delete($tenant, $scheduled->shift());
        }
    }

    /**
     * Free space only from old scheduled archives. At least one verified
     * scheduled archive remains. If that cannot bring usage below the package
     * limit, discard the newly-created archive and report a failed backup.
     */
    private function enforceStorageLimit(Tenant $tenant, TenantBackup $created): void
    {
        $limit = $this->usage->limit($tenant->loadMissing('subscription.plan'));
        if ($limit === null || $this->usage->for($tenant)['total'] <= $limit) {
            return;
        }

        $scheduled = TenantBackup::query()
            ->where('tenant_id', $tenant->id)
            ->where('trigger', TenantBackup::TRIGGER_SCHEDULED)
            ->where('status', TenantBackup::STATUS_COMPLETED)
            ->oldest('created_at')
            ->get();

        while ($this->usage->for($tenant)['total'] > $limit && $scheduled->count() > 1) {
            $candidate = $scheduled->shift();
            $this->delete($tenant, $candidate);
        }

        if ($this->usage->for($tenant)['total'] > $limit) {
            $this->delete($tenant, $created);
            throw new RuntimeException('The new backup would exceed this tenant’s storage limit. Older scheduled backups could not be removed safely because at least one verified archive must remain.');
        }
    }

    public function delete(Tenant $tenant, TenantBackup $backup): void
    {
        if ($backup->tenant_id !== $tenant->id) {
            throw new RuntimeException('A tenant backup can only be deleted for its own tenant.');
        }

        $this->withinTenant($tenant, function () use ($backup): void {
            if (filled($backup->file_path)) {
                Storage::disk($backup->disk)->delete($backup->file_path);
            }
            if (filled($backup->source_backup_uuid)) {
                SystemBackup::query()->where('uuid', $backup->source_backup_uuid)->delete();
            }
        });

        $backup->delete();
    }

    /**
     * Restore a verified archive to its own tenant only. A verified safety
     * archive is made first, then the current tenant migrations are applied.
     */
    public function restore(Tenant $tenant, TenantBackup $backup): TenantBackup
    {
        if ($backup->tenant_id !== $tenant->id || ! $backup->isUsable()) {
            throw new RuntimeException('Only a completed backup for this tenant can be restored.');
        }

        $this->create($tenant, TenantBackup::TRIGGER_PRE_RESTORE);

        $this->withinTenant($tenant, function () use ($backup): void {
            $source = SystemBackup::query()
                ->where('uuid', $backup->source_backup_uuid)
                ->firstOrFail();

            $this->backups->restore(
                backup: $source,
                actor: null,
                createSafetyBackup: false,
                useMaintenanceMode: false,
                replaceFiles: true,
            );
            $exitCode = Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);
            if ($exitCode !== 0) {
                throw new RuntimeException('The restored tenant database could not be migrated to the current application version.');
            }
        });

        $backup->forceFill([
            'restored_at' => now(),
            'restore_count' => $backup->restore_count + 1,
        ])->save();

        return $backup->fresh();
    }

    /** Limit a callback to one tenant's DB and public/private storage roots. */
    private function withinTenant(Tenant $tenant, callable $callback): mixed
    {
        $previousDefault = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        $previousPublicRoot = config('filesystems.disks.public.root');
        $previousPrivateRoot = config('filesystems.disks.local.root');
        $previousDirectory = config('backups.directory');
        $previousRoots = config('backups.data_roots');
        $previousAllowsScheduledFull = config('backups.allow_scheduled_full');

        try {
            $root = $this->storage->root($tenant);
            config()->set('database.connections.tenant.database', $tenant->database_name);
            config()->set('filesystems.disks.public.root', storage_path('app/public/'.$root));
            config()->set('filesystems.disks.local.root', storage_path('app/private/'.$root));
            config()->set('backups.directory', 'saas-backups/'.$tenant->uuid);
            config()->set('backups.data_roots', [
                'public' => storage_path('app/public/'.$root),
                'private' => storage_path('app/private/'.$root),
            ]);
            config()->set('backups.allow_scheduled_full', true);

            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
            app('filesystem')->forgetDisk('public');
            app('filesystem')->forgetDisk('local');

            return $callback();
        } finally {
            DB::setDefaultConnection($previousDefault);
            DB::purge('tenant');
            config()->set('database.connections.tenant.database', $previousDatabase);
            config()->set('filesystems.disks.public.root', $previousPublicRoot);
            config()->set('filesystems.disks.local.root', $previousPrivateRoot);
            config()->set('backups.directory', $previousDirectory);
            config()->set('backups.data_roots', $previousRoots);
            config()->set('backups.allow_scheduled_full', $previousAllowsScheduledFull);
            app('filesystem')->forgetDisk('public');
            app('filesystem')->forgetDisk('local');
        }
    }

    private function systemTriggerFor(string $trigger): string
    {
        return match ($trigger) {
            TenantBackup::TRIGGER_MANUAL => SystemBackup::TRIGGER_MANUAL,
            TenantBackup::TRIGGER_PRE_RESTORE => SystemBackup::TRIGGER_PRE_RESTORE,
            default => SystemBackup::TRIGGER_SCHEDULED,
        };
    }
}
