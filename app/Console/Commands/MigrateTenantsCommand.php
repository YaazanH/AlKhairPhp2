<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class MigrateTenantsCommand extends Command
{
    protected $signature = 'saas:migrate-tenants
        {--tenant= : Limit the migration run to one tenant slug}
        {--all : Include non-operational tenants that have a database}
        {--pretend : Show the SQL that would run without changing tenant databases}';

    protected $description = 'Run the tenant schema migrations against every selected tenant database.';

    public function handle(): int
    {
        $query = Tenant::query()->whereNotNull('database_name')->where('database_name', '!=', '');

        if ($this->option('tenant')) {
            $query->where('slug', $this->option('tenant'));
        }

        if (! $this->option('all')) {
            $query->whereIn('status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE]);
        }

        $tenants = $query->orderBy('name')->get();

        if ($tenants->isEmpty()) {
            if ($this->option('tenant')) {
                $this->error('Tenant not found or does not have a provisioned database.');

                return self::FAILURE;
            }
            $this->warn('No tenant databases matched this migration run.');

            return self::SUCCESS;
        }

        $previousConnection = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        $failed = [];

        try {
            foreach ($tenants as $tenant) {
                $verb = $this->option('pretend') ? 'Previewing' : 'Migrating';
                $this->line("{$verb} {$tenant->slug}...");
                config()->set('database.connections.tenant.database', $tenant->database_name);
                DB::purge('tenant');
                DB::setDefaultConnection('tenant');

                $exitCode = Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--force' => true,
                    '--pretend' => (bool) $this->option('pretend'),
                ]);

                if ($this->option('pretend')) {
                    $output = trim(Artisan::output());
                    if ($output !== '') {
                        $this->line($output);
                    }
                }

                if ($exitCode === self::SUCCESS) {
                    $this->info(($this->option('pretend') ? 'Previewed' : 'Migrated')." {$tenant->slug}.");

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

        $this->info(($this->option('pretend') ? 'Previewed' : 'Migrated')." {$tenants->count()} tenant database(s).");

        return self::SUCCESS;
    }
}
