<?php

namespace App\Support;

use App\Models\AppSetting;
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

    public function currentLogoUrl(): ?string
    {
        if (! $this->tenantContext->hasTenant()) {
            return null;
        }

        $path = AppSetting::groupValues('general')->get('tenant_logo_path')
            ?: $this->tenantContext->tenant()->logo_path;

        return filled($path) ? asset('storage/'.ltrim((string) $path, '/')) : null;
    }
}
