<?php

namespace App\Http\Controllers;

use App\Services\Landlord\PlatformTenantAccess;
use App\Services\Landlord\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantSupportHandoffController extends Controller
{
    public function __invoke(Request $request, string $token, TenantContext $context, PlatformTenantAccess $access): RedirectResponse
    {
        abort_unless($context->hasTenant(), 404);
        [$user, $session] = $access->consume($token, $context->tenant(), $request->ip());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(PlatformTenantAccess::SESSION_KEY, $session);

        return redirect()->route('dashboard');
    }
}
