<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantModuleAccess;
use App\Services\Landlord\TenantModuleExtras;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantModuleExtrasController extends Controller
{
    public function preview(Request $request, Tenant $tenant, TenantModuleAccess $access): RedirectResponse
    {
        $data = $this->validated($request);
        $before = $access->snapshot($tenant);
        if (! hash_equals($before['version'], $data['expected_version'])) {
            return back()->withErrors(['modules' => 'Module access changed. Refresh the preview.']);
        }
        $after = $access->snapshot($tenant, $data['modules'] ?? []);
        if ($after['errors']) {
            return back()->withInput()->withErrors(['modules' => implode('; ', $after['errors'])]);
        }
        $request->session()->put('platform.tenant_extra_preview.'.$tenant->id, $this->signature($data));

        return back()->withInput()->with('module_preview', [
            'added' => array_values(array_diff($after['enabled'], $before['enabled'])),
            'removed' => array_values(array_diff($before['enabled'], $after['enabled'])),
            'effective' => $after['enabled'],
        ]);
    }

    public function update(Request $request, Tenant $tenant, TenantModuleExtras $extras): RedirectResponse
    {
        $data = $this->validated($request);
        $previewed = (string) $request->session()->get('platform.tenant_extra_preview.'.$tenant->id, '');
        if (! hash_equals($this->signature($data), $previewed) || ! $request->boolean('confirm_extras')) {
            return back()->withInput()->withErrors(['confirm_extras' => 'Preview and confirm the tenant-specific changes before saving.']);
        }
        try {
            $extras->replace($tenant, $data['modules'] ?? [], $request->user('platform'), $data['expected_version']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['modules' => $exception->getMessage()]);
        }
        $request->session()->forget('platform.tenant_extra_preview.'.$tenant->id);

        return redirect()->route('platform.tenants.edit', $tenant)->with('status', 'Tenant extras updated successfully.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'modules' => ['array'], 'modules.*' => ['string'],
            'expected_version' => ['required', 'string', 'size:64'], 'confirm_extras' => ['nullable', 'boolean'],
        ]);
    }

    private function signature(array $data): string
    {
        $modules = collect($data['modules'] ?? [])->sort()->values()->all();

        return hash('sha256', json_encode([$data['expected_version'], $modules], JSON_THROW_ON_ERROR));
    }
}
