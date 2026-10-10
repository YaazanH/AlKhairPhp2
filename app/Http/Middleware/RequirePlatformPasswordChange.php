<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $administrator = $request->user('platform');

        if (! $administrator?->must_change_password || $request->routeIs('platform.password-change.*', 'platform.logout')) {
            return $next($request);
        }

        return redirect()->route('platform.password-change.show');
    }
}
