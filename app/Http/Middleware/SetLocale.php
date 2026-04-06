<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = array_keys(config('app.supported_locales', []));
        $configuredLocale = $request->session()->get('locale', config('app.locale'));

        if (! in_array($configuredLocale, $supportedLocales, true)) {
            $configuredLocale = config('app.fallback_locale', 'en');
        }

        App::setLocale($configuredLocale);

        return $next($request);
    }
}
