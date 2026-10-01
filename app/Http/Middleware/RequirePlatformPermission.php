<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $administrator = $request->user('platform');
        abort_unless(
            collect($permissions)->contains(fn (string $permission): bool => $administrator?->hasPlatformPermission($permission) === true),
            403,
        );

        return $next($request);
    }
}
