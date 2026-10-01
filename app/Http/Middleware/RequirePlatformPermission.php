<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless($request->user('platform')?->hasPlatformPermission($permission), 403);

        return $next($request);
    }
}
