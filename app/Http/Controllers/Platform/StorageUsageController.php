<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantStorageUsage;
use Illuminate\View\View;

class StorageUsageController extends Controller
{
    public function __invoke(TenantStorageUsage $usage): View
    {
        $tenants = Tenant::query()->whereNotNull('database_name')->orderBy('name')->get();
        $rows = $tenants->map(fn (Tenant $tenant) => ['tenant' => $tenant, 'usage' => $usage->for($tenant)])->sortByDesc(fn (array $row) => $row['usage']['total'])->values();

        return view('platform.storage.index', ['rows' => $rows, 'total' => $rows->sum(fn (array $row) => $row['usage']['total'])]);
    }
}
