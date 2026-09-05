<?php

namespace App\Http\Middleware;

use App\Support\ApplicationTimezone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyApplicationTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        app(ApplicationTimezone::class)->applyConfigured();

        return $next($request);
    }
}
