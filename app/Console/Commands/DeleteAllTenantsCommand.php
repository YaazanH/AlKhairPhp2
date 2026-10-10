<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantDatabaseName;
use App\Services\Landlord\TenantResources;
use Illuminate\Console\Command;
use Throwable;

class DeleteAllTenantsCommand extends Command
{
    private const CONFIRMATION = 'DELETE ALL TENANTS';

    protected $signature = 'saas:delete-all-tenants
        {--dry-run : List every tenant and database without deleting anything}
        {--force : Allow the destructive operation to run}
        {--confirm= : Must exactly equal "DELETE ALL TENANTS"}';

    protected $description = 'Permanently delete every tenant, its UUID-owned database, and tenant storage.';

    public function handle(TenantDatabaseName $databaseNames, TenantResources $resources): int
    {
        $tenants = Tenant::query()->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants exist. Nothing was deleted.');

            return self::SUCCESS;
        }

        $targets = [];
        $landlordDatabase = (string) config('database.connections.landlord.database');

        foreach ($tenants as $tenant) {
            $expectedDatabase = $databaseNames->for($tenant);
            $database = filled($tenant->database_name) ? (string) $tenant->database_name : $expectedDatabase;

            if ($database !== $expectedDatabase) {
                $this->error("Refusing to continue: tenant {$tenant->slug} has a database name that is not owned by its UUID ({$database}).");

                return self::FAILURE;
            }

            if ($landlordDatabase !== '' && $database === $landlordDatabase) {
                $this->error("Refusing to continue: tenant {$tenant->slug} resolves to the landlord database.");

                return self::FAILURE;
            }

            $targets[] = [$tenant->slug, $database, $tenant->status];
        }

        $this->table(['Tenant', 'Database', 'Status'], $targets);

        if ($this->option('dry-run')) {
            $this->info("Dry run complete. {$tenants->count()} tenant(s) would be permanently deleted.");

            return self::SUCCESS;
        }

        if (! $this->option('force') || $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Nothing was deleted. Re-run with --force --confirm="DELETE ALL TENANTS" after reviewing --dry-run.');

            return self::FAILURE;
        }

        $deleted = 0;

        foreach ($tenants as $tenant) {
            try {
                $resources->delete($tenant);
                $tenant->delete();
                $deleted++;
                $this->info("Deleted {$tenant->slug} and {$targets[$deleted - 1][1]}.");
            } catch (Throwable $exception) {
                $this->error("Stopped after deleting {$deleted} tenant(s). Failed on {$tenant->slug}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info("Permanently deleted {$deleted} tenant(s), their databases, and tenant storage.");

        return self::SUCCESS;
    }
}
