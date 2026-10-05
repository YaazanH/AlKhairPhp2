<?php

namespace App\Console\Commands;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Models\Landlord\TenantProvisioningAttempt;
use App\Services\Landlord\TenantAdministratorProvisioner;
use App\Services\Landlord\TenantDatabaseName;
use App\Services\Landlord\TenantSetupManager;
use App\Services\Landlord\TenantStorage;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\QuranJuzSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionTenantCommand extends Command
{
    protected $signature = 'saas:provision-tenant {name} {slug} {owner-email} {--owner-name=} {--owner-password=} {--platform-email=platform-admin@alkhair.test} {--storage-limit-gb=} {--timezone=} {--locale=}';

    protected $description = 'Create a new isolated tenant database, users, seed data, and storage.';

    public function handle(
        TenantDatabaseName $databaseNames,
        TenantStorage $storage,
        TenantAdministratorProvisioner $administrators,
    ): int {
        $platform = PlatformAdministrator::query()->where('email', $this->option('platform-email'))->firstOrFail();
        $ownerName = $this->option('owner-name') ?: $this->argument('owner-email');
        $ownerPassword = $this->option('owner-password') ?: $this->secret('Tenant owner password');
        $slug = Str::slug($this->argument('slug'));

        if (in_array($slug, config('tenancy.reserved_subdomains', []), true)) {
            $this->error('This subdomain is reserved by the platform.');

            return self::FAILURE;
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            $this->error('A tenant already uses this subdomain.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $this->argument('name'),
            'slug' => $slug,
            'status' => Tenant::STATUS_PROVISIONING,
            'timezone' => $this->option('timezone') ?: null,
            'locale' => $this->option('locale') ?: null,
            'storage_limit_bytes' => filled($this->option('storage-limit-gb'))
                ? (int) round((float) $this->option('storage-limit-gb') * 1024 * 1024 * 1024)
                : null,
        ]);
        $database = $databaseNames->for($tenant);
        $attempt = TenantProvisioningAttempt::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'status' => Tenant::STATUS_PROVISIONING,
            'started_at' => now(),
        ]);
        $previousConnection = DB::getDefaultConnection();
        $previousPublicRoot = config('filesystems.disks.public.root');
        $previousPrivateRoot = config('filesystems.disks.local.root');

        try {
            DB::connection('tenant')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            config()->set('database.connections.tenant.database', $database);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
            Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);
            $tenantRoot = $storage->initialise($tenant)['public'];
            config()->set('filesystems.disks.public.root', storage_path('app/public/'.$tenantRoot));
            config()->set('filesystems.disks.local.root', storage_path('app/private/'.$tenantRoot));
            app('filesystem')->forgetDisk('public');
            app('filesystem')->forgetDisk('local');
            app(TenantSetupManager::class)->initialiseNewTenant($tenant);
            foreach ([RoleSeeder::class, MasterDataSeeder::class, QuranJuzSeeder::class, WebsiteSeeder::class] as $seeder) {
                app($seeder)->run();
            }
            $administrators->provision(
                ownerName: $ownerName,
                ownerEmail: $this->argument('owner-email'),
                ownerPassword: $ownerPassword,
                platform: $platform,
            );
            $tenant->update(['database_name' => $database, 'status' => Tenant::STATUS_DRAFT]);
            TenantDomain::query()->create([
                'tenant_id' => $tenant->id,
                'host' => $slug.'.'.config('tenancy.base_domain'),
                'is_primary' => true,
            ]);
            $attempt->update(['status' => Tenant::STATUS_ACTIVE, 'finished_at' => now()]);
            $this->info("Tenant {$tenant->slug} is ready for subscription setup: {$database}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $tenant->update(['status' => Tenant::STATUS_PROVISIONING_FAILED]);
            $attempt->update([
                'status' => Tenant::STATUS_PROVISIONING_FAILED,
                'error_message' => Str::limit($e->getMessage(), 1000),
                'finished_at' => now(),
            ]);

            report($e);
            $this->error('Tenant provisioning failed. Review the application log for details.');

            return self::FAILURE;
        } finally {
            DB::setDefaultConnection($previousConnection);
            DB::purge('tenant');
            config()->set('filesystems.disks.public.root', $previousPublicRoot);
            config()->set('filesystems.disks.local.root', $previousPrivateRoot);
            app('filesystem')->forgetDisk('public');
            app('filesystem')->forgetDisk('local');
        }
    }
}
