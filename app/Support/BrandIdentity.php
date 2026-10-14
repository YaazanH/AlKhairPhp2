<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\Landlord\TenantContext;

class BrandIdentity
{
    private const PLATFORM_LOGO_PATH = 'images/platform-brand/alkhair-platform-logo.png';

    private const PLATFORM_ICON_PATH = 'images/platform-brand/alkhair-platform-icon-v2.png';

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

    public function platformLogoUrl(): string
    {
        return asset(self::PLATFORM_LOGO_PATH);
    }

    public function platformIconUrl(): string
    {
        return asset(self::PLATFORM_ICON_PATH);
    }

    public function mobileLogoUrl(): ?string
    {
        if (! $this->tenantContext->hasTenant()) {
            return null;
        }

        $path = AppSetting::groupValues('general')->get('tenant_mobile_logo_path');

        return filled($path) ? asset('storage/'.ltrim((string) $path, '/')) : null;
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
