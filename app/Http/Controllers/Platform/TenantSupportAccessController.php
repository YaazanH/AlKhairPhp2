<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformTenantHandoff;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\PlatformTenantAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantSupportAccessController extends Controller
{
    public function store(Request $request, Tenant $tenant, PlatformTenantAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'access_level' => ['required', Rule::in([
                PlatformTenantHandoff::LEVEL_READ,
                PlatformTenantHandoff::LEVEL_EDIT,
                PlatformTenantHandoff::LEVEL_DELETE,
            ])],
        ]);
        $domain = $tenant->domains()->where('is_primary', true)->value('host');
        abort_if(blank($domain), 409, 'The tenant does not have a primary domain.');

        [, $plainToken] = $access->createHandoff(
            $request->user('platform'),
            $tenant,
            $data['access_level'],
            $request->ip(),
        );

        $port = $request->getPort();
        $portSuffix = in_array($port, [80, 443], true) ? '' : ':'.$port;
        $path = route('tenant-support.consume', ['token' => $plainToken], false);

        return redirect()->away($request->getScheme().'://'.$domain.$portSuffix.$path);
    }
}
