<?php

namespace App\Http\Middleware;

use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantFeatureAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $context = app(TenantContext::class);

        // The legacy base host remains available during the conversion. Tenant
        // requests, however, are always checked at the server boundary.
        if (! $context->hasTenant()) {
            return $next($request);
        }

        if (! app(TenantFeatureAccess::class)->isEnabled($context->tenant(), $feature)) {
            abort(403, __('platform.features.unavailable'));
        }

        return $next($request);
    }
}
