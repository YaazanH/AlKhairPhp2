<?php

namespace App\Http\Middleware;

use App\Services\Landlord\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNoTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(app(TenantContext::class)->hasTenant(), 404);

        return $next($request);
    }
}
