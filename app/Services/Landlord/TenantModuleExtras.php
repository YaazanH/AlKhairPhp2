<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantFeatureOverride;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantModuleExtras
{
    public function __construct(private TenantModuleAccess $access, private ModuleRegistry $registry) {}

    // Full replacement of explicit extras, not of the package or inferred prerequisites.
    public function replace(Tenant $tenant, array $codes, PlatformAdministrator $actor, string $expectedVersion): array
    {
        return DB::connection('landlord')->transaction(function () use ($tenant, $codes, $actor, $expectedVersion) {
            $actor = $actor->fresh();
            if (! $actor?->is_active) {
                throw new DomainException('An active platform administrator is required.');
            }
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $subscription = $tenant->subscription()->lockForUpdate()->first();
            if ($subscription) {
                Plan::query()->whereKey($subscription->plan_id)->lockForUpdate()->first();
            }
            $before = $this->access->snapshot($tenant);
            if (! hash_equals($before['version'], $expectedVersion)) {
                throw new DomainException('Module access changed. Refresh the preview.');
            }
            foreach ($codes as $code) {
                if (! is_string($code) || $code === 'foundation' || ! isset($this->registry->definitions()[$code])) {
                    throw new DomainException('Only registered optional modules can be added as extras.');
                }
            }
            $after = $this->access->snapshot($tenant, array_values(array_unique($codes)));
            if ($after['errors']) {
                throw new DomainException(implode('; ', $after['errors']));
            }
            $ids = [];
            foreach (array_unique($codes) as $code) {
                $feature = Feature::query()->firstOrCreate(['code' => $code], [
                    'name' => $this->registry->definitions()[$code]['name'], 'is_core' => false, 'is_active' => true,
                ]);
                $ids[] = $feature->id;
                TenantFeatureOverride::query()->updateOrCreate(['tenant_id' => $tenant->id, 'feature_id' => $feature->id], [
                    'is_enabled' => true, 'changed_by_platform_administrator_id' => $actor->id,
                ]);
            }
            $tenant->featureOverrides()->where('is_enabled', true)->whereNotIn('feature_id', $ids)->delete();
            PlatformAuditEvent::query()->create([
                'uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
                'platform_administrator_id' => $actor->id, 'event' => 'tenant_module_extras_updated',
                'properties' => ['before' => $before['extras'], 'after' => array_values(array_unique($codes))],
            ]);

            return $this->access->snapshot($tenant);
        });
    }
}
