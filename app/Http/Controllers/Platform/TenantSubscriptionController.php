<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantSubscriptionController extends Controller
{
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $plan = Plan::query()
            ->where('code', $data['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        $subscription = TenantSubscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $plan->id,
                'status' => TenantSubscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => null,
                'changed_by_platform_administrator_id' => $request->user('platform')->id,
            ],
        );

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'tenant_id' => $tenant->id,
            'event' => 'tenant_subscription_updated',
            'properties' => ['plan' => $plan->code, 'subscription_id' => $subscription->id],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('platform.dashboard')->with('status', __('platform.provisioning.subscription_updated'));
    }
}
