<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\Tenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanModuleManager
{
    public function __construct(private ModuleRegistry $registry, private TenantModuleAccess $tenantAccess) {}

    public function catalog(): array
    {
        $unavailable = Feature::query()->where('is_active', false)->pluck('code')->all();

        return collect($this->registry->definitions())->except('foundation')
            ->map(fn (array $definition, string $code): array => [
                'code' => $code,
                'name' => $definition['name'],
                'requires' => $definition['requires'] ?? [],
                'requires_any' => $definition['requires_any'] ?? [],
                'is_available' => ! in_array($code, $unavailable, true),
            ])->values()->all();
    }

    public function normalize(array $codes): array
    {
        $codes = collect($codes)->filter(fn (mixed $code): bool => is_string($code) && $code !== 'foundation')->unique()->values()->all();
        $unavailable = Feature::query()->where('is_active', false)->pluck('code')->all();
        $sources = $this->registry->resolve($codes, [], $unavailable);

        return ['selected' => $codes, 'effective' => array_keys($sources), 'sources' => $sources];
    }

    public function preview(?Plan $plan, array $codes): array
    {
        $selection = $this->normalize($codes);
        $tenants = collect();

        if ($plan) {
            $tenants = Tenant::query()->whereHas('subscription', fn ($query) => $query->where('plan_id', $plan->id))
                ->with('subscription')->orderBy('name')->get()
                ->map(function (Tenant $tenant) use ($selection): array {
                    $before = $this->tenantAccess->snapshot($tenant);
                    $unavailable = Feature::query()->where('is_active', false)->pluck('code')->all();
                    $errors = [];
                    try {
                        $after = array_keys($this->registry->resolve($selection['selected'], $before['extras'], $unavailable));
                    } catch (DomainException $exception) {
                        $after = [];
                        $errors[] = $exception->getMessage();
                    }

                    return [
                        'tenant' => $tenant,
                        'added' => array_values(array_diff($after, $before['enabled'])),
                        'removed' => array_values(array_diff($before['enabled'], $after)),
                        'errors' => $errors,
                    ];
                });
        }

        $signature = hash('sha256', json_encode([
            $plan?->id, $plan?->updated_at?->toJSON(), $selection['selected'],
            $tenants->map(fn (array $impact): array => [
                $impact['tenant']->id, $impact['added'], $impact['removed'], $impact['errors'],
            ])->all(),
        ], JSON_THROW_ON_ERROR));

        return $selection + ['tenants' => $tenants, 'signature' => $signature];
    }

    public function create(array $attributes, array $codes, PlatformAdministrator $actor, ?string $ipAddress): Plan
    {
        $selection = $this->normalize($codes);

        return DB::connection('landlord')->transaction(function () use ($attributes, $selection, $actor, $ipAddress): Plan {
            $plan = Plan::query()->create($attributes);
            $plan->features()->sync($this->featureIds($selection['selected']));
            $this->audit($actor, 'plan_created', $plan, ['modules' => $selection['selected']], $ipAddress);

            return $plan;
        });
    }

    public function update(Plan $plan, array $attributes, array $codes, PlatformAdministrator $actor, ?string $ipAddress): Plan
    {
        $selection = $this->normalize($codes);

        return DB::connection('landlord')->transaction(function () use ($plan, $attributes, $selection, $actor, $ipAddress): Plan {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            $before = $locked->features()->pluck('code')->all();
            $locked->update($attributes);
            $beforeEffective = collect($this->selectedCodes($locked))->sort()->values()->all();
            $afterEffective = collect($selection['selected'])->sort()->values()->all();
            if ($beforeEffective !== $afterEffective) {
                $locked->features()->sync($this->featureIds($selection['selected']));
            }
            $this->audit($actor, 'plan_updated', $locked, [
                'before' => $before,
                'after' => $locked->features()->pluck('code')->all(),
                'affected_tenants' => $locked->subscriptions()->count(),
            ], $ipAddress);

            return $locked;
        });
    }

    public function duplicate(Plan $plan, string $name, string $code, PlatformAdministrator $actor, ?string $ipAddress): Plan
    {
        $selection = $this->normalize($this->selectedCodes($plan));

        return DB::connection('landlord')->transaction(function () use ($plan, $name, $code, $selection, $actor, $ipAddress): Plan {
            $copy = Plan::query()->create([
                'code' => $code,
                'name' => $name,
                'description' => $plan->description,
                'is_active' => true,
                'price_syp' => $plan->price_syp,
                'billing_period_days' => $plan->billing_period_days,
            ]);
            $copy->features()->sync($this->featureIds($selection['selected']));
            $this->audit($actor, 'plan_duplicated', $copy, [
                'source_plan_id' => $plan->id, 'source_plan_code' => $plan->code, 'modules' => $selection['selected'],
            ], $ipAddress);

            return $copy;
        });
    }

    public function selectedCodes(Plan $plan): array
    {
        $codes = $plan->features()->pluck('code')->all();
        $legacy = in_array(Feature::CORE, $codes, true);

        return array_values(array_filter($this->registry->expandLegacy($codes, $legacy), fn (string $code): bool => $code !== 'foundation'));
    }

    private function featureIds(array $codes): array
    {
        return collect($codes)->map(function (string $code): int {
            $definition = $this->registry->definitions()[$code] ?? throw new DomainException("Unknown module: {$code}");

            return Feature::query()->firstOrCreate(['code' => $code], [
                'name' => $definition['name'], 'is_core' => false, 'is_active' => true,
            ])->id;
        })->all();
    }

    private function audit(PlatformAdministrator $actor, string $event, Plan $plan, array $properties, ?string $ipAddress): void
    {
        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(), 'platform_administrator_id' => $actor->id,
            'event' => $event, 'properties' => ['plan_id' => $plan->id, 'plan_code' => $plan->code] + $properties,
            'ip_address' => $ipAddress,
        ]);
    }
}
