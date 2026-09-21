<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\Landlord\ModuleRegistry;
use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantModuleAccess;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class CapabilitiesController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, CurrentModuleAccess $access)
    {
        $snapshot = $context->hasTenant() ? app(TenantModuleAccess::class)->snapshot($context->tenant()) : null;
        $modules = $snapshot['enabled'] ?? array_keys(app(ModuleRegistry::class)->definitions());
        $permissions = Permission::query()->pluck('name')->filter(fn ($name) => $access->permissionAvailable($name) && $request->user()->can($name))->sort()->values()->all();
        $data = ['modules' => $modules, 'permissions' => $permissions, 'setup' => ['status' => 'not_managed_yet']];
        $data['version'] = hash('sha256', json_encode([$snapshot['version'] ?? 'standalone', $request->user()->id, $data], JSON_THROW_ON_ERROR));

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
