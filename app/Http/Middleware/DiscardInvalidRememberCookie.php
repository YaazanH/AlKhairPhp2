<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Recaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class DiscardInvalidRememberCookie
{
    /**
     * Remove stale remember-me cookies before Laravel attempts authentication.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        // This middleware only runs in the browser stack. Use the session
        // guard explicitly so an earlier Sanctum/API request cannot leave the
        // auth manager pointed at a token guard without remember-cookie APIs.
        $guard = Auth::guard('web');
        $cookieName = $guard->getRecallerName();
        $cookieValue = $request->cookies->get($cookieName);

        if (! $request->session()->has($guard->getName())
            && is_string($cookieValue)
            && $cookieValue !== '') {
            $recaller = new Recaller($cookieValue);
            $rememberedUser = $recaller->valid()
                ? $guard->getProvider()->retrieveByToken($recaller->id(), $recaller->token())
                : null;

            if ($rememberedUser === null) {
                $request->cookies->remove($cookieName);
                Cookie::queue(Cookie::forget($cookieName));
            }
        }

        return $next($request);
    }
}
