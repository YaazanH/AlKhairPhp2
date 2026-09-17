<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

class AuthenticatePlatform extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        return parent::handle($request, $next, 'platform');
    }

    protected function redirectTo(Request $request): ?string
    {
        return route('platform.login');
    }
}
