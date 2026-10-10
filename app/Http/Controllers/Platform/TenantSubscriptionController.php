<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\SubscriptionVoucher;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use App\Services\Landlord\SubscriptionBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TenantSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string'],
            'voucher_id' => ['nullable', 'integer', 'exists:landlord.subscription_vouchers,id'],
            'subscription_status' => ['nullable', Rule::in([TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])],
            'trial_days' => ['nullable', 'required_if:subscription_status,'.TenantSubscription::STATUS_TRIAL, 'integer', 'min:1', 'max:365'],
            'starts_at' => ['nullable', 'date', 'before_or_equal:today'],
            'renews_automatically' => ['nullable', 'boolean'],
        ]);
        $plan = Plan::query()->where('code', $data['plan'])->where('is_active', true)->firstOrFail();
        $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->first();
        $voucher = isset($data['voucher_id']) ? SubscriptionVoucher::query()->findOrFail($data['voucher_id']) : null;
        if ($voucher && ! $voucher->canBeAssignedTo($tenant) && $subscription?->subscription_voucher_id !== $voucher->id) {
            return back()->withErrors(['voucher_id' => 'This voucher is inactive or belongs to another tenant.'])->withInput();
        }
        $this->billing->activatePackage(
            $tenant,
            $plan,
            $voucher,
            isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now(),
            (bool) ($data['renews_automatically'] ?? false),
            $request->user('platform'),
            $request->ip(),
            ($data['subscription_status'] ?? TenantSubscription::STATUS_ACTIVE) === TenantSubscription::STATUS_TRIAL,
            (int) ($data['trial_days'] ?? 0),
        );

        return redirect()->route('platform.tenants.subscription', $tenant)->with('status', __('platform.provisioning.subscription_updated'));
    }

    public function cancel(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate(['confirm_slug' => ['required', Rule::in([$tenant->slug])]]);
        $this->billing->cancel($tenant, $request->user('platform'), $request->ip());

        return back()->with('status', 'Subscription cancelled. Paid access remains available through its end date and grace period.');
    }

    public function reactivate(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless(
            filled($tenant->database_name) && $tenant->domains()->where('is_primary', true)->exists(),
            422,
            'A subscription cannot be reactivated until its tenant database and primary domain are provisioned.',
        );
        $this->billing->reactivate($tenant, $request->user('platform'), $request->ip());

        return back()->with('status', 'Subscription reactivated.');
    }

    public function recordOfflinePayment(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'amount_syp' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'payment_method' => ['required', Rule::in(PlatformSubscriptionLedgerEntry::PAYMENT_METHODS)],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'reference' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->billing->recordOfflinePayment(
            $tenant,
            (int) $data['amount_syp'],
            $data['payment_method'],
            Carbon::parse($data['paid_at']),
            $data['reference'],
            $data['note'] ?? null,
            $request->user('platform'),
            $request->ip(),
        );

        return back()->with('status', 'Offline SYP payment recorded as tenant credit.');
    }
}
