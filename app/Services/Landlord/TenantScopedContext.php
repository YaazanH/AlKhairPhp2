<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantScopedContext
{
    public function __construct(private readonly TenantStorage $storage) {}

    /** Execute a callback with one tenant database and storage roots selected. */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        if (blank($tenant->database_name)) {
            throw new InvalidArgumentException('The tenant database has not been provisioned.');
        }

        $previousDefault = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        $previousPublicRoot = config('filesystems.disks.public.root');
        $previousPrivateRoot = config('filesystems.disks.local.root');

        try {
            $root = $this->storage->root($tenant);
            config()->set('database.connections.tenant.database', $tenant->database_name);
            config()->set('filesystems.disks.public.root', storage_path('app/public/'.$root));
            config()->set('filesystems.disks.local.root', storage_path('app/private/'.$root));
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
            app('filesystem')->forgetDisk('public');
            app('filesystem')->forgetDisk('local');
        }
    }
}
