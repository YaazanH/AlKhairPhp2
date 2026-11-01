<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use Spatie\Permission\PermissionRegistrar;

class TenantPermissionCache
{
    public function keyFor(Tenant $tenant): string
    {
        return 'spatie.permission.cache.tenant.'.strtolower($tenant->uuid);
    }

    public function useTenant(Tenant $tenant): void
    {
        $this->useKey($this->keyFor($tenant));
    }

    public function useKey(string $key): void
    {
        config()->set('permission.cache.key', $key);

        $registrar = app(PermissionRegistrar::class);
        $registrar->initializeCache();
        // Changing the persistent key alone leaves the previous database's roles loaded.
        $registrar->clearPermissionsCollection();
    }
}
