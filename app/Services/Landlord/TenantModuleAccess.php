<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Tenant;
use App\Models\User;
use DomainException;

class TenantModuleAccess
{
    public function __construct(private ModuleRegistry $registry) {}

    // Read fresh state: no cross-tenant or stale relation cache. Version is content-derived.
    public function snapshot(Tenant $tenant, ?array $proposedExtras = null): array
    {
        $tenant = $tenant->fresh();
        $subscription = $tenant?->subscription()->with('plan.features')->first();
        $plan = $subscription?->plan;
        $package = $plan?->features->pluck('code')->all() ?? [];
        $overrides = $tenant?->featureOverrides()->with('feature')->get() ?? collect();
        $legacy = in_array('core', $package, true);
        $errors = [];
        // Negative legacy overrides must be explicitly migrated, not silently turned into grants.
        $negative = $overrides->where('is_enabled', false)->pluck('feature.code')->all();
        if ($negative) {
            $errors[] = 'Legacy negative overrides require review: '.implode(', ', $negative);
        }
        $extras = $proposedExtras ?? $overrides->where('is_enabled', true)->pluck('feature.code')->all();
        $unavailable = Feature::query()->where('is_active', false)->pluck('code')->all();
        $sources = [];
        try {
            $sources = $this->registry->resolve(
                $this->registry->expandLegacy($package, $legacy),
                $this->registry->expandLegacy($extras, $legacy),
                $this->registry->expandLegacy($unavailable, $legacy),
            );
        } catch (DomainException $exception) {
            $errors[] = $exception->getMessage();
        }
        $operational = $tenant?->isOperational() ?? false;
        $current = $subscription?->isCurrent() && $plan !== null;
        $enabled = $operational && $current && ! $errors ? array_keys($sources) : [];
        // Foundation is infrastructure, not a bypass to business modules.
        if ($operational && ! in_array('foundation', $unavailable, true) && ! in_array('foundation', $enabled, true)) {
            $enabled[] = 'foundation';
        }
        sort($enabled);
        $result = ['enabled' => $enabled, 'sources' => $sources, 'errors' => $errors,
            'package' => $package, 'extras' => $extras, 'legacy' => $legacy,
            'tenant_operational' => $operational, 'subscription_current' => (bool) $current];
        $result['version'] = hash('sha256', json_encode([$tenant?->uuid, $this->registry->definitions(), $result], JSON_THROW_ON_ERROR));

        return $result;
    }

    public function isEnabled(Tenant $tenant, string $module): bool
    {
        return in_array($module, $this->snapshot($tenant)['enabled'], true);
    }

    public function allows(Tenant $tenant, User $user, string $action): bool
    {
        $required = config('modules.actions')[$action] ?? null;
        if ($required === null) {
            return false;
        }
        $enabled = $this->snapshot($tenant)['enabled'];

        return ! array_diff($required, $enabled) && $user->can($action);
    }
}
