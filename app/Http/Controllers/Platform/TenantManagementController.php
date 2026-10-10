<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\SaasPlatformSetting;
use App\Models\Landlord\SubscriptionVoucher;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\PlanModuleManager;
use App\Services\Landlord\TenantModuleAccess;
use App\Services\Landlord\TenantResources;
use App\Services\Landlord\TenantStorageUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantManagementController extends Controller
{
    public function create(): View
    {
        return view('platform.tenants.create');
    }

    public function edit(Tenant $tenant): View
    {
        $settings = SaasPlatformSetting::current();

        return view('platform.tenants.overview', [
            'tenant' => $tenant->load(['subscription.plan', 'subscription.voucher', 'domains']),
            'billingBalance' => $this->balance($tenant),
            'suspendedDataRetentionMonths' => $settings->suspended_data_retention_months,
            'retentionEligibleAt' => $tenant->suspended_at?->copy()->addMonthsNoOverflow($settings->suspended_data_retention_months),
        ]);
    }

    public function organisation(Tenant $tenant): View
    {
        return view('platform.tenants.organisation', compact('tenant'));
    }

    public function subscription(Tenant $tenant): View
    {
        $tenant->load(['subscription.plan', 'subscription.voucher']);

        return view('platform.tenants.subscription', [
            'tenant' => $tenant,
            'plans' => Plan::query()->where(fn ($query) => $query->where('is_active', true)->when($tenant->subscription?->plan_id, fn ($q, $id) => $q->orWhere('id', $id)))->orderBy('name')->get(),
            'vouchers' => SubscriptionVoucher::query()->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))->where('is_active', true)->orderBy('code')->get(),
            'billingBalance' => $this->balance($tenant),
            'history' => PlatformAuditEvent::query()->with('platformAdministrator')->where('tenant_id', $tenant->id)->whereIn('event', ['tenant_subscription_updated', 'tenant_subscription_cancelled', 'tenant_subscription_reactivated', 'subscription_automatically_renewed'])->latest()->limit(25)->get(),
        ]);
    }

    public function billing(Tenant $tenant): View
    {
        return view('platform.tenants.billing', [
            'tenant' => $tenant->load('subscription.plan'),
            'billingEntries' => PlatformSubscriptionLedgerEntry::query()->where('tenant_id', $tenant->id)->latest()->limit(50)->get(),
            'billingBalance' => $this->balance($tenant),
        ]);
    }

    public function storage(Tenant $tenant, TenantStorageUsage $usage): View
    {
        return view('platform.tenants.storage', ['tenant' => $tenant, 'usage' => $usage->for($tenant)]);
    }

    public function updateStorage(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['storage_limit_gb' => ['required', 'numeric', 'min:0.1', 'max:100000']]);
        $before = $tenant->storage_limit_bytes;
        $tenant->update(['storage_limit_bytes' => (int) round((float) $data['storage_limit_gb'] * 1024 * 1024 * 1024)]);
        $this->audit($request, $tenant, 'tenant_storage_limit_updated', ['before_bytes' => $before, 'after_bytes' => $tenant->storage_limit_bytes]);

        return back()->with('status', 'Tenant storage limit updated.');
    }

    public function activity(Tenant $tenant): View
    {
        return view('platform.tenants.activity', [
            'tenant' => $tenant,
            'events' => PlatformAuditEvent::query()->with('platformAdministrator')->where('tenant_id', $tenant->id)->latest()->limit(100)->get(),
        ]);
    }

    public function modules(Tenant $tenant, PlanModuleManager $planModules, TenantModuleAccess $moduleAccess): View
    {
        $snapshot = $moduleAccess->snapshot($tenant);
        $packageModules = $tenant->subscription?->plan
            ? $planModules->preview(null, $planModules->selectedCodes($tenant->subscription->plan))['effective']
            : ['foundation'];

        return view('platform.tenants.modules', [
            'tenant' => $tenant,
            'moduleCatalog' => $planModules->catalog(),
            'moduleSnapshot' => $snapshot,
            'packageModules' => $packageModules,
            'moduleAuditEvents' => PlatformAuditEvent::query()->where('tenant_id', $tenant->id)->where('event', 'tenant_module_extras_updated')->latest()->limit(8)->get(),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', Rule::in(array_keys(config('app.supported_locales', [])))],
        ]);
        $tenant->update(['name' => $data['name'], 'timezone' => $data['timezone'] ?: null, 'locale' => $data['locale'] ?: null]);
        $this->audit($request, $tenant, 'tenant_updated', ['slug' => $tenant->slug]);

        return redirect()->route('platform.tenants.organisation', $tenant)->with('status', __('platform.tenant.updated'));
    }

    public function setStatus(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])]]);

        if ($data['status'] === Tenant::STATUS_ACTIVE) {
            abort_unless(
                filled($tenant->database_name) && $tenant->domains()->where('is_primary', true)->exists(),
                422,
                'A tenant cannot be activated until its database and primary domain are provisioned.',
            );
        }

        $tenant->update([
            'status' => $data['status'],
            'suspended_at' => $data['status'] === Tenant::STATUS_SUSPENDED ? now() : null,
        ]);
        $this->audit($request, $tenant, 'tenant_status_updated', ['status' => $data['status']]);

        return back()->with('status', __('platform.tenant.status_updated'));
    }

    public function resetAdministratorPassword(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        abort_unless(filled($tenant->database_name), 422, 'This tenant has not been provisioned yet.');
        $previousDatabase = config('database.connections.tenant.database');

        try {
            config()->set('database.connections.tenant.database', $tenant->database_name);
            DB::purge('tenant');
            $administrator = DB::connection('tenant')->table('users')
                ->join('model_has_roles', fn ($join) => $join->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', 'App\\Models\\User'))
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', 'admin')
                ->orderByDesc('users.is_tenant_administrator')
                ->orderBy('users.id')
                ->select('users.id', 'users.email')
                ->first();
            abort_unless($administrator, 404, 'The tenant administrator account was not found.');
            $isPlatformAdministrator = DB::connection('tenant')->table('tenant_platform_administrator_links')->where('user_id', $administrator->id)->exists();
            DB::connection('tenant')->table('users')->where('id', $administrator->id)->update([
                'password' => Hash::make($data['password']),
                'issued_password' => null,
                'must_change_password' => ! $isPlatformAdministrator,
                'password_changed_at' => $isPlatformAdministrator ? now() : null,
                'remember_token' => null,
                'updated_at' => now(),
            ]);
        } finally {
            config()->set('database.connections.tenant.database', $previousDatabase);
            DB::purge('tenant');
        }

        $this->audit($request, $tenant, 'tenant_administrator_password_reset', ['email' => $administrator->email]);

        return back()->with('status', 'Tenant administrator password reset successfully.');
    }

    public function destroy(Request $request, Tenant $tenant, TenantResources $resources): RedirectResponse
    {
        $request->validate(['confirm_slug' => ['required', Rule::in([$tenant->slug])]]);
        $uuid = $tenant->uuid;
        $slug = $tenant->slug;
        $resources->delete($tenant);
        $tenant->delete();
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id, 'event' => 'tenant_deleted', 'properties' => ['tenant_uuid' => $uuid, 'slug' => $slug], 'ip_address' => $request->ip()]);

        return redirect()->route('platform.dashboard')->with('status', __('platform.tenant.deleted'));
    }

    private function audit(Request $request, Tenant $tenant, string $event, array $properties): void
    {
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id, 'tenant_id' => $tenant->id, 'event' => $event, 'properties' => $properties, 'ip_address' => $request->ip()]);
    }

    private function balance(Tenant $tenant): int
    {
        return (int) PlatformSubscriptionLedgerEntry::query()->where('tenant_id', $tenant->id)->sum(DB::raw('credit_syp - debit_syp'));
    }
}
