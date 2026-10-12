<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = array_keys(config('app.supported_locales', []));
        $requestedLocale = $request->header('X-Locale')
            ?: $request->getPreferredLanguage($supportedLocales)
            ?: config('app.locale', 'ar');

        App::setLocale(in_array($requestedLocale, $supportedLocales, true)
            ? $requestedLocale
            : config('app.locale', 'ar'));

        return $next($request);
    }
}
