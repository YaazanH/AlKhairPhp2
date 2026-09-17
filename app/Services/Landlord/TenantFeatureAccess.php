<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantFeatureOverride;

class TenantFeatureAccess
{
    public function isEnabled(Tenant $tenant, string $featureCode): bool
    {
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

        $override = TenantFeatureOverride::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('feature_id', $feature->getKey())
            ->first();

        if ($override !== null) {
            return $override->is_enabled;
        }

        $subscription = $tenant->relationLoaded('subscription')
            ? $tenant->subscription
            : $tenant->subscription()->with('plan.features')->first();

        if ($subscription === null || ! $subscription->isCurrent()) {
            return false;
        }

        $plan = $subscription->relationLoaded('plan') ? $subscription->plan : $subscription->plan()->with('features')->first();

        return $plan?->features->contains('id', $feature->getKey()) ?? false;
    }
}
