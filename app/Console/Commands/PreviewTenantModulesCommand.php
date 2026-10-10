<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantFeatureAccess;
use App\Services\Landlord\TenantModuleAccess;
use Illuminate\Console\Command;

class PreviewTenantModulesCommand extends Command
{
    protected $signature = 'saas:preview-modules {--tenant= : Limit to a tenant slug}';

    protected $description = 'Read-only legacy-to-module access preview; reports conversion blockers without changing data';

    public function handle(TenantModuleAccess $modules, TenantFeatureAccess $legacy): int
    {
        $query = Tenant::query()->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug));
        $found = false;
        $blocked = false;
        foreach ($query->cursor() as $tenant) {
            $found = true;
            $snapshot = $modules->snapshot($tenant);
            $blocked = $blocked || count($snapshot['errors']) > 0;
            $this->line(json_encode([
                'tenant' => $tenant->slug,
                'current_legacy_access' => collect(['core', 'finance', 'custom_printing'])->mapWithKeys(fn ($code) => [$code => $legacy->isEnabled($tenant, $code)])->all(),
                'proposed' => $snapshot,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        if (! $found && $this->option('tenant')) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        return $blocked ? self::FAILURE : self::SUCCESS;
    }
}
