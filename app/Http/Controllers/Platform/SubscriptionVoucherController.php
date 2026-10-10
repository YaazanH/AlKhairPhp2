<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\SubscriptionVoucher;
use App\Models\Landlord\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionVoucherController extends Controller
{
    public function index(): View
    {
        return view('platform.vouchers.index', [
            'vouchers' => SubscriptionVoucher::query()
                ->with('tenant')
                ->withCount('redemptionRecords')
                ->latest()
                ->get(),
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:80', 'unique:landlord.subscription_vouchers,code'],
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in([SubscriptionVoucher::PERCENT, SubscriptionVoucher::FIXED])],
            'discount_value' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'usage_limit' => ['required', Rule::in(['one', 'total', 'tenant', 'unlimited'])],
            'max_redemptions' => ['nullable', 'required_if:usage_limit,total', 'integer', 'min:2', 'max:4294967295'],
            'tenant_id' => ['nullable', 'required_if:usage_limit,tenant', 'integer', 'exists:landlord.tenants,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', $request->filled('starts_at') ? 'after:starts_at' : 'after:now'],
            'application_type' => ['required', Rule::in(SubscriptionVoucher::APPLICATION_TYPES)],
            'max_uses_per_subscription' => ['nullable', 'required_if:application_type,limited_periods', 'integer', 'min:1', 'max:120'],
        ]);

        if ($data['discount_type'] === SubscriptionVoucher::PERCENT && $data['discount_value'] > 100) {
            return back()->withErrors(['discount_value' => 'Percentage cannot exceed 100.'])->withInput();
        }

        $voucher = SubscriptionVoucher::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'tenant_id' => $data['usage_limit'] === 'tenant' ? $data['tenant_id'] : null,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'max_redemptions' => match ($data['usage_limit']) {
                'one' => 1,
                'total' => $data['max_redemptions'],
                default => null,
            },
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'application_type' => $data['application_type'],
            'max_uses_per_subscription' => $data['application_type'] === SubscriptionVoucher::APPLICATION_LIMITED_PERIODS
                ? $data['max_uses_per_subscription']
                : null,
            'is_active' => true,
        ]);

        $this->audit($request, $voucher, 'subscription_voucher_created');

        return back()->with('status', 'Voucher created.');
    }

    public function status(Request $request, SubscriptionVoucher $voucher): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $voucher->update($data);
        $this->audit($request, $voucher, 'subscription_voucher_status_updated');

        return back()->with('status', 'Voucher status saved.');
    }

    private function audit(Request $request, SubscriptionVoucher $voucher, string $event): void
    {
        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $request->user('platform')->id,
            'tenant_id' => $voucher->tenant_id,
            'event' => $event,
            'properties' => [
                'voucher_id' => $voucher->id,
                'code' => $voucher->code,
                'is_active' => $voucher->is_active,
                'application_type' => $voucher->application_type,
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}
