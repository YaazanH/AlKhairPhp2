<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use App\Services\Landlord\SubscriptionBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
            'period_type' => ['nullable', Rule::in([
                TenantSubscription::PERIOD_MONTHLY,
                TenantSubscription::PERIOD_ANNUAL,
                TenantSubscription::PERIOD_CUSTOM,
            ])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'renews_automatically' => ['nullable', 'boolean'],
        ]);
        $plan = Plan::query()->where('code', $data['plan'])->where('is_active', true)->firstOrFail();
        $subscription = TenantSubscription::query()->firstOrNew(['tenant_id' => $tenant->id]);
        $periodType = $data['period_type'] ?? $subscription->period_type ?? TenantSubscription::PERIOD_MONTHLY;
        $start = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : ($subscription->starts_at ?? now());
        $scheduleChanged = ! $subscription->exists || $request->hasAny(['period_type', 'starts_at', 'ends_at']);
        $end = $subscription->ends_at;

        if ($scheduleChanged) {
            $end = match ($periodType) {
                TenantSubscription::PERIOD_ANNUAL => $start->copy()->addYearNoOverflow(),
                TenantSubscription::PERIOD_CUSTOM => isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null,
                default => $start->copy()->addMonthNoOverflow(),
            };
        }

        if ($periodType === TenantSubscription::PERIOD_CUSTOM && $end === null) {
            return back()->withErrors(['ends_at' => 'A custom subscription requires an end date.'])->withInput();
        }

        $status = $subscription->exists
            ? $subscription->status
            : ($data['subscription_status'] ?? TenantSubscription::STATUS_ACTIVE);
        if (! in_array($status, [TenantSubscription::STATUS_CANCELLED, TenantSubscription::STATUS_SUSPENDED], true)) {
            $status = $data['subscription_status'] ?? $status;
        }

        $subscription->fill([
            'plan_id' => $plan->id,
            'subscription_voucher_id' => $data['voucher_id'] ?? null,
            'status' => $status,
            'period_type' => $periodType,
            'starts_at' => $start,
            'ends_at' => $end,
            'renews_automatically' => $periodType !== TenantSubscription::PERIOD_CUSTOM
                && (bool) ($data['renews_automatically'] ?? false),
            'changed_by_platform_administrator_id' => $request->user('platform')->id,
        ]);
        $subscription->save();
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id, 'tenant_id' => $tenant->id, 'event' => 'tenant_subscription_updated', 'properties' => ['plan' => $plan->code, 'subscription_id' => $subscription->id, 'status' => $subscription->status, 'period_type' => $subscription->period_type, 'starts_at' => $subscription->starts_at?->toIso8601String(), 'ends_at' => $subscription->ends_at?->toIso8601String()], 'ip_address' => $request->ip()]);

        return redirect()->route('platform.dashboard')->with('status', __('platform.provisioning.subscription_updated'));
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
