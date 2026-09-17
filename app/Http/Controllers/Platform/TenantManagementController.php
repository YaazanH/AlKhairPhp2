<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Services\Landlord\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantManagementController extends Controller
{
    public function create(): View { return view('platform.tenants.create'); }

    public function edit(Tenant $tenant): View { return view('platform.tenants.edit', compact('tenant')); }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::notIn(config('tenancy.reserved_subdomains', []))],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', Rule::in(array_keys(config('app.supported_locales', [])))],
        ]);
        $slug = Str::slug($data['slug']);
        abort_if(Tenant::query()->where('slug', $slug)->whereKeyNot($tenant->id)->exists(), 422, __('platform.tenant.slug_taken'));
        $tenant->update(['name' => $data['name'], 'slug' => $slug, 'timezone' => $data['timezone'] ?: null, 'locale' => $data['locale'] ?: null]);
        TenantDomain::query()->updateOrCreate(['tenant_id' => $tenant->id, 'is_primary' => true], ['host' => $slug.'.'.config('tenancy.base_domain')]);
        $this->audit($request, $tenant, 'tenant_updated', ['slug' => $slug]);

        return redirect()->route('platform.tenants.edit', $tenant)->with('status', __('platform.tenant.updated'));
    }

    public function setStatus(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])]]);
        $tenant->update(['status' => $data['status']]);
        $this->audit($request, $tenant, 'tenant_status_updated', ['status' => $data['status']]);

        return back()->with('status', __('platform.tenant.status_updated'));
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
