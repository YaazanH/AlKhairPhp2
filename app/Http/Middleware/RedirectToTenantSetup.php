<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantSetupManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToTenantSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $user = $request->user();
        $eligibleRequest = $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->routeIs('tenant-setup.*', 'login', 'logout', 'locale.switch')
            && ! $request->is('livewire/*');
        $canManage = $context->hasTenant()
            && $user instanceof User
            && ($user->isPlatformAdministrator() || $user->can('settings.manage'));

        if ($eligibleRequest && $context->hasTenant() && $canManage) {
            $summary = app(TenantSetupManager::class)->summary($context->tenant());
            $mustPrompt = $summary['status'] === 'required'
                || ($summary['status'] === 'attention'
                    && $request->session()->get('tenant_setup_prompted_version') !== $summary['version']);
            if ($mustPrompt) {
                $request->session()->put('tenant_setup_prompted_version', $summary['version']);

                return redirect()->route('tenant-setup.show');
            }
        }

        return $next($request);
    }
}
