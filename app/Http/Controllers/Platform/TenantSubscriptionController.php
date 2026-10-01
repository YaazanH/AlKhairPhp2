<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use App\Services\Landlord\SubscriptionBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['plan' => ['required', 'string'], 'voucher_id' => ['nullable', 'integer', 'exists:landlord.subscription_vouchers,id'], 'renews_automatically' => ['nullable', 'boolean']]);
        $plan = Plan::query()->where('code', $data['plan'])->where('is_active', true)->firstOrFail();
        $subscription = TenantSubscription::query()->firstOrNew(['tenant_id' => $tenant->id]);
        $subscription->fill(['plan_id' => $plan->id, 'subscription_voucher_id' => $data['voucher_id'] ?? null, 'renews_automatically' => (bool) ($data['renews_automatically'] ?? false), 'changed_by_platform_administrator_id' => $request->user('platform')->id]);
        if (! $subscription->exists) { $subscription->fill(['status' => TenantSubscription::STATUS_ACTIVE, 'starts_at' => now()]); }
        $subscription->save();
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id, 'tenant_id' => $tenant->id, 'event' => 'tenant_subscription_updated', 'properties' => ['plan' => $plan->code, 'subscription_id' => $subscription->id], 'ip_address' => $request->ip()]);
        return redirect()->route('platform.dashboard')->with('status', __('platform.provisioning.subscription_updated'));
    }

    public function recordOfflinePayment(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['amount_syp' => ['required', 'integer', 'min:1', 'max:999999999999'], 'reference' => ['required', 'string', 'max:100']]);
        $this->billing->recordOfflinePayment($tenant, (int) $data['amount_syp'], $data['reference'], $request->user('platform'), $request->ip());
        return back()->with('status', 'Offline SYP payment recorded as tenant credit.');
    }
}
