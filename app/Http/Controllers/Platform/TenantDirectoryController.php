<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantDirectoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $creating = $request->boolean('create');
        abort_if($creating && ! $request->user('platform')->hasPlatformPermission('manage.tenants'), 403);
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $statuses = ['draft', 'provisioning', 'trial', 'active', 'suspended', 'expired', 'provisioning_failed'];
        $canViewBilling = $request->user('platform')->hasPlatformPermission('view.subscriptions') || $request->user('platform')->hasPlatformPermission('manage.subscriptions');

        return view('platform.tenants.index', [
            'creating' => $creating,
            'canViewBilling' => $canViewBilling,
            'tenants' => Tenant::query()->with($canViewBilling ? ['subscription.plan', 'domains'] : ['domains'])
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
                ->when(in_array($status, $statuses, true), fn ($query) => $query->where('status', $status))
                ->when($status === 'attention', fn ($query) => $query->whereIn('status', ['suspended', 'expired', 'provisioning_failed']))
                ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(),
        ]);
    }
}
