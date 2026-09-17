<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonicalUrl = rtrim((string) config('app.url'), '/');
        $canonicalHost = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));
        $requestHost = strtolower($request->getHost());

        if ($canonicalHost === ''
            || $requestHost === $canonicalHost
            || $this->withoutWww($requestHost) !== $this->withoutWww($canonicalHost)) {
            return $next($request);
        }

        return redirect()->away($canonicalUrl.$request->getRequestUri(), 308, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
        ]);
    }

    private function withoutWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
