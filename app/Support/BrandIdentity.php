<?php

namespace App\Support;

use App\Services\Landlord\TenantContext;

class BrandIdentity
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    public function currentName(): string
    {
        if ($this->tenantContext->hasTenant()) {
            return $this->tenantContext->tenant()->name;
        }

        return $this->platformName();
    }

    public function platformName(): string
    {
        return __('platform.brand.name');
    }
}
