<?php

namespace App\Http\Middleware;

use App\Models\Group;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGroupIsAccessible
{
    public function handle(Request $request, Closure $next): Response
    {
        $group = $request->route('group');

        if (! $group instanceof Group) {
            $group = Group::query()->findOrFail($group);
        }

        abort_unless($group->isAccessibleInActiveWorkspace(), 404);

        return $next($request);
    }
}
