<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use LogicException;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        if ($this->tenant === null) {
            throw new LogicException('A tenant database has not been selected for this request.');
        }

        return $this->tenant;
    }
}
