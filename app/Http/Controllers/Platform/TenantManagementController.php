<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Services\Landlord\PlanModuleManager;
use App\Services\Landlord\TenantModuleAccess;
use App\Services\Landlord\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantManagementController extends Controller
{
    public function create(PlanModuleManager $planModules): View
    {
        return view('platform.tenants.create', [
            'plans' => Plan::query()->where('is_active', true)->orderBy('name')->get(),
            'moduleCatalog' => $planModules->catalog(),
        ]);
    }

    public function edit(Tenant $tenant, PlanModuleManager $planModules, TenantModuleAccess $moduleAccess): View
    {
        $snapshot = $moduleAccess->snapshot($tenant);
        $packageModules = $tenant->subscription?->plan
            ? $planModules->preview(null, $planModules->selectedCodes($tenant->subscription->plan))['effective']
            : ['foundation'];

        return view('platform.tenants.edit', [
            'tenant' => $tenant->load('subscription.plan.features'),
            'plans' => Plan::query()
                ->where(function ($query) use ($tenant): void {
                    $query->where('is_active', true);
                    if ($tenant->subscription?->plan_id) {
                        $query->orWhere('id', $tenant->subscription->plan_id);
                    }
                })
                ->orderBy('name')->get(),
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

        return redirect()->route('platform.tenants.edit', $tenant)->with('status', __('platform.tenant.updated'));
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

        $tenant->update(['status' => $data['status']]);
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

    public function destroy(Request $request, Tenant $tenant, TenantStorage $storage): RedirectResponse
    {
        $request->validate(['confirm_slug' => ['required', Rule::in([$tenant->slug])]]);
        $database = $tenant->database_name;
        $uuid = $tenant->uuid;
        $slug = $tenant->slug;

        if ($database !== null && preg_match('/^alkhair_tenant_[a-f0-9]{32}$/', $database)) {
            DB::connection('tenant')->statement('DROP DATABASE '.$database);
        }

        File::deleteDirectory(storage_path('app/public/'.$storage->root($tenant)));
        File::deleteDirectory(storage_path('app/private/'.$storage->root($tenant)));
        $tenant->delete();
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id, 'event' => 'tenant_deleted', 'properties' => ['tenant_uuid' => $uuid, 'slug' => $slug], 'ip_address' => $request->ip()]);

        return redirect()->route('platform.dashboard')->with('status', __('platform.tenant.deleted'));
    }

    private function audit(Request $request, Tenant $tenant, string $event, array $properties): void
    {
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id, 'tenant_id' => $tenant->id, 'event' => $event, 'properties' => $properties, 'ip_address' => $request->ip()]);
    }
}
