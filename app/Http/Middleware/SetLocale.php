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
        $defaultLocale = config('app.locale', 'ar');
        $configuredLocale = (bool) $request->session()->get('locale_user_selected', false)
            ? $request->session()->get('locale', $defaultLocale)
            : $defaultLocale;

        if (! in_array($configuredLocale, $supportedLocales, true)) {
            $configuredLocale = $defaultLocale;
        }

        App::setLocale($configuredLocale);

        return $next($request);
    }
}
