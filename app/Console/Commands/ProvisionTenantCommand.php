<?php

namespace App\Console\Commands;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Models\Landlord\TenantProvisioningAttempt;
use App\Models\Landlord\TenantSubscription;
use App\Models\TenantPlatformAdministratorLink;
use App\Models\User;
use App\Services\Landlord\TenantDatabaseName;
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
    protected $signature = 'saas:provision-tenant {name} {slug} {owner-email} {--owner-name=} {--owner-password=} {--platform-email=platform-admin@alkhair.test} {--plan=core}';
    protected $description = 'Create a new isolated tenant database, users, seed data, and storage.';

    public function handle(TenantDatabaseName $databaseNames, TenantStorage $storage): int
    {
        $platform = PlatformAdministrator::query()->where('email', $this->option('platform-email'))->firstOrFail();
        $plan = Plan::query()->where('code', $this->option('plan'))->firstOrFail();
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
        ]);
        $database = $databaseNames->for($tenant);
        $attempt = TenantProvisioningAttempt::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'status' => Tenant::STATUS_PROVISIONING,
            'started_at' => now(),
        ]);
        $previousConnection = DB::getDefaultConnection();

        try {
            DB::connection('tenant')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            config()->set('database.connections.tenant.database', $database);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
            Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);
            foreach ([RoleSeeder::class, MasterDataSeeder::class, QuranJuzSeeder::class, WebsiteSeeder::class] as $seeder) app($seeder)->run();
            $owner = User::query()->create(['name' => $ownerName, 'email' => $this->argument('owner-email'), 'password' => $ownerPassword, 'is_active' => true]); $owner->assignRole('admin');
            $support = User::query()->create(['name' => $platform->name, 'email' => $platform->email, 'username' => 'platform-admin', 'password' => $platform->password, 'is_active' => true]); $support->assignRole('super_admin');
            TenantPlatformAdministratorLink::query()->create(['user_id' => $support->id, 'platform_administrator_uuid' => $platform->uuid]);
            $storage->initialise($tenant);
            $tenant->update(['database_name' => $database, 'status' => Tenant::STATUS_ACTIVE]);
            TenantDomain::query()->create([
                'tenant_id' => $tenant->id,
                'host' => $slug.'.'.config('tenancy.base_domain'),
                'is_primary' => true,
            ]);
            TenantSubscription::query()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => TenantSubscription::STATUS_ACTIVE, 'starts_at' => now(), 'changed_by_platform_administrator_id' => $platform->id]);
            $attempt->update(['status' => Tenant::STATUS_ACTIVE, 'finished_at' => now()]);
            $this->info("Tenant {$tenant->slug} is active: {$database}"); return self::SUCCESS;
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
        }
    }
}
