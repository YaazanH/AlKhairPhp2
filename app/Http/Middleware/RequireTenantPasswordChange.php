<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedRoute = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($middleware) => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')));
        $user = $request->user();

        if (! $authenticatedRoute
            || ! $user instanceof User
            || ! $user->must_change_password
            || $user->isPlatformAdministrator()
            || $request->routeIs('password.change-required.*', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(409, 'password_change_required');
        }

        return redirect()->route('password.change-required.show');
    }
}
