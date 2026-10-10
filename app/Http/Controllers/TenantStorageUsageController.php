<?php

namespace App\Http\Controllers;

use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantStorageUsage;
use Illuminate\View\View;

class TenantStorageUsageController extends Controller
{
    public function __invoke(TenantContext $context, TenantStorageUsage $usage): View
    {
        $tenant = $context->tenant();

        return view('tenant.storage-usage', ['tenant' => $tenant, 'usage' => $usage->for($tenant)]);
    }
}
