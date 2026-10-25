<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use RuntimeException;

class TenantImportDatabase
{
    public function __construct(private TenantResources $resources) {}

    public function create(Tenant $tenant): string
    {
        if (! in_array(config('database.connections.tenant.driver'), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('unsupported_database');
        }

        return $this->resources->createDatabase($tenant);
    }

    public function delete(Tenant $tenant): void
    {
        $this->resources->delete($tenant, deleteStorage: false);
    }
}
