<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantFeatureOverride;

class TenantFeatureAccess
{
    public function isEnabled(Tenant $tenant, string $featureCode): bool
    {
        // Keep the three deployed gates compatible until their vertical slices migrate.
        // All new module codes use the dependency-aware engine, including unknown-code denial.
        if (! in_array($featureCode, [Feature::CORE, Feature::FINANCE, Feature::CUSTOM_PRINTING], true)) {
            return app(TenantModuleAccess::class)->isEnabled($tenant, $featureCode);
        }
        $tenant = $tenant->fresh();
        if ($tenant === null) {
            return false;
        }
        if (! $tenant->isOperational()) {
            return false;
        }

        $feature = Feature::query()->where('code', $featureCode)->first();

        if ($feature === null || ! $feature->is_active) {
            return false;
        }

        if ($feature->is_core) {
            return true;
        }

        $subscription = $tenant->subscription()->with('plan.features')->first();
        if ($subscription === null || ! $subscription->isCurrent()) {
            return false;
        }

        $override = TenantFeatureOverride::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('feature_id', $feature->getKey())
            ->first();

        if ($override !== null) {
            return $override->is_enabled;
        }

        $plan = $subscription->relationLoaded('plan') ? $subscription->plan : $subscription->plan()->with('features')->first();

        return $plan?->features->contains('id', $feature->getKey()) ?? false;
    }
}
