<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = Tenant::query()->with(['subscription.plan', 'domains']);
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');
        $statuses = [
            Tenant::STATUS_DRAFT,
            Tenant::STATUS_PROVISIONING,
            Tenant::STATUS_TRIAL,
            Tenant::STATUS_ACTIVE,
            Tenant::STATUS_SUSPENDED,
            Tenant::STATUS_EXPIRED,
            Tenant::STATUS_PROVISIONING_FAILED,
        ];

        $query->when($search !== '', fn ($tenants) => $tenants->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when(in_array($status, $statuses, true), fn ($tenants) => $tenants->where('status', $status));

        return view('platform.dashboard', [
            'tenants' => $query->orderBy('name')->get(),
            'tenantStatusCounts' => Tenant::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }
}
