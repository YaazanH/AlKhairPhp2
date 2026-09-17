<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = Tenant::query()->with('subscription.plan');
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $query->when($search !== '', fn ($tenants) => $tenants->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when(in_array($status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED, Tenant::STATUS_PROVISIONING_FAILED], true), fn ($tenants) => $tenants->where('status', $status));

        return view('platform.dashboard', [
            'tenants' => $query->orderBy('name')->get(),
            'tenantCounts' => ['total' => Tenant::count(), 'active' => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(), 'suspended' => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count()],
            'plans' => Plan::query()
                ->with('features')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
