<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use DomainException;

class LegacyModuleConversionPlanner
{
    public function __construct(
        private ModuleRegistry $registry,
        private TenantModuleAccess $access,
        private TenantFeatureAccess $legacyAccess,
    ) {}

    public function plan(Plan $plan): array
    {
        $current = $plan->features()->pluck('code')->all();
        $legacy = in_array(Feature::CORE, $current, true);
        $target = $this->translatedCodes($current, $legacy);

        return [
            'code' => $plan->code,
            'current_features' => $current,
            'target_modules' => $target,
            'requires_conversion' => (bool) array_intersect($current, [Feature::CORE, Feature::CUSTOM_PRINTING]) || ($legacy && in_array(Feature::FINANCE, $current, true)),
            'assigned_tenants' => $plan->subscriptions()->count(),
        ];
    }

    public function tenant(Tenant $tenant): array
    {
        $tenant = $tenant->fresh();
        $subscription = $tenant?->subscription()->with('plan.features')->first();
        $plan = $subscription?->plan;
        $planCodes = $plan?->features->pluck('code')->all() ?? [];
        $legacyPlan = in_array(Feature::CORE, $planCodes, true);
        $overrides = $tenant?->featureOverrides()->with('feature')->get() ?? collect();
        $negative = $overrides->where('is_enabled', false)->pluck('feature.code')->filter()->values()->all();
        $positive = $overrides->where('is_enabled', true)->pluck('feature.code')->filter()->values()->all();
        $targetPackage = $this->translatedCodes($planCodes, $legacyPlan);
        $targetExtras = $this->translatedCodes($positive, $legacyPlan);
        $conversionErrors = [];
        try {
            $targetSources = $this->registry->resolve($targetPackage, $targetExtras);
        } catch (DomainException $exception) {
            $targetSources = [];
            $conversionErrors[] = $exception->getMessage();
        }
        $snapshot = $this->access->snapshot($tenant);
        $currentEntitlement = array_keys($snapshot['sources']);
        sort($currentEntitlement);
        $targetEntitlement = array_keys($targetSources);
        sort($targetEntitlement);

        return [
            'tenant' => $tenant?->slug,
            'plan' => $plan?->code,
            'target_package_modules' => $targetPackage,
            'target_extra_modules' => $targetExtras,
            'negative_overrides' => $negative,
            'current_entitlement' => $currentEntitlement,
            'target_entitlement' => $targetEntitlement,
            'preserves_effective_access' => $negative === [] && $snapshot['errors'] === [] && $conversionErrors === [] && $currentEntitlement === $targetEntitlement,
            'legacy_gate_access' => collect([Feature::CORE, Feature::FINANCE, Feature::CUSTOM_PRINTING])
                ->mapWithKeys(fn (string $code): array => [$code => $this->legacyAccess->isEnabled($tenant, $code)])
                ->all(),
            'errors' => array_values(array_unique(array_merge($snapshot['errors'], $conversionErrors))),
        ];
    }

    private function translatedCodes(array $codes, bool $legacyPlan): array
    {
        $translated = $this->registry->expandLegacy($codes, $legacyPlan);

        return collect($translated)
            ->reject(fn (string $code): bool => $code === 'foundation')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
