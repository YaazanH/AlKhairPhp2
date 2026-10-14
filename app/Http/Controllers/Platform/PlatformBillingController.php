<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformBillingController extends Controller
{
    public function subscriptions(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $statuses = ['pending', 'active', 'trial', 'suspended', 'expired', 'cancelled'];
        $query = TenantSubscription::query()->with(['tenant', 'plan', 'voucher']);
        $query->when($search !== '', fn ($q) => $q->where(fn ($nested) => $nested->whereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"))->orWhereHas('plan', fn ($plan) => $plan->where('name', 'like', "%{$search}%"))));
        $query->when(in_array($request->query('status'), $statuses, true), fn ($q) => $q->where('status', $request->query('status')));
        $query->when($request->query('ending') === '7d', fn ($q) => $q->whereIn('status', ['active', 'trial'])->whereBetween('ends_at', [now(), now()->addDays(7)]));

        return view('platform.billing.subscriptions', [
            'subscriptions' => $query->orderBy('ends_at')->orderBy('id')->paginate(20)->withQueryString(),
            'statuses' => $statuses,
        ]);
    }

    public function payments(Request $request): View
    {
        $dates = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null])]);
        $search = trim((string) $request->query('search', ''));
        $query = PlatformSubscriptionLedgerEntry::query()->with(['tenant', 'recordedBy'])->where('type', PlatformSubscriptionLedgerEntry::TYPE_OFFLINE_PAYMENT);
        $query->when($search !== '', fn ($q) => $q->where(fn ($nested) => $nested->where('reference', 'like', "%{$search}%")->orWhere('receipt_number', 'like', "%{$search}%")->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$search}%"))));
        $query->when($request->filled('tenant'), fn ($q) => $q->where('tenant_id', $request->query('tenant')));
        $query->when($dates['from'] ?? null, fn ($q, $date) => $q->whereDate('paid_at', '>=', $date));
        $query->when($dates['to'] ?? null, fn ($q, $date) => $q->whereDate('paid_at', '<=', $date));

        return view('platform.billing.payments', [
            'collected' => (clone $query)->sum('credit_syp'),
            'payments' => $query->latest('paid_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'tenants' => Tenant::query()->whereIn('id', PlatformSubscriptionLedgerEntry::query()->where('type', 'offline_payment')->select('tenant_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
