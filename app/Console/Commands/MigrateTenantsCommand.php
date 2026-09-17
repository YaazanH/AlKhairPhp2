<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class MigrateTenantsCommand extends Command
{
    protected $signature = 'saas:migrate-tenants {--all : Include non-operational tenants that have a database}';
    protected $description = 'Run the tenant schema migrations against every selected tenant database.';

    public function handle(): int
    {
        $query = Tenant::query()->whereNotNull('database_name')->where('database_name', '!=', '');

        if (! $this->option('all')) {
            $query->whereIn('status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE]);
        }

        $tenants = $query->orderBy('name')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenant databases matched this migration run.');

            return self::SUCCESS;
        }

        $previousConnection = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        $failed = [];

        try {
            foreach ($tenants as $tenant) {
                $this->line("Migrating {$tenant->slug}...");
                config()->set('database.connections.tenant.database', $tenant->database_name);
                DB::purge('tenant');
                DB::setDefaultConnection('tenant');

                $exitCode = Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--force' => true,
                ]);

                if ($exitCode === self::SUCCESS) {
                    $this->info("Migrated {$tenant->slug}.");
                    continue;
                }

                $failed[] = $tenant->slug;
                $this->error("Migration failed for {$tenant->slug}.");
            }
        } finally {
            DB::setDefaultConnection($previousConnection);
            config()->set('database.connections.tenant.database', $previousDatabase);
            DB::purge('tenant');
        }

        if ($failed !== []) {
            $this->error('Failed tenants: '.implode(', ', $failed));

            return self::FAILURE;
        }

        $this->info("Migrated {$tenants->count()} tenant database(s).");

        return self::SUCCESS;
    }
}
