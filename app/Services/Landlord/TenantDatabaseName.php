<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use InvalidArgumentException;

class TenantDatabaseName
{
    public function for(Tenant $tenant): string
    {
        $uuid = str_replace('-', '', (string) $tenant->uuid);

        if (! preg_match('/^[a-f0-9]{32}$/', $uuid)) {
            throw new InvalidArgumentException('Tenant UUID must be a valid UUID before a database name can be created.');
        }

        return 'alkhair_tenant_'.$uuid;
    }
}
