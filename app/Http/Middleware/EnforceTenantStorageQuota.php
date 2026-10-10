<?php

namespace App\Http\Middleware;

use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantStorageUsage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantStorageQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        if (! $context->hasTenant() || $request->allFiles() === []) {
            return $next($request);
        }

        $bytes = collect($request->allFiles())->flatten()->sum(fn ($file) => method_exists($file, 'getSize') ? (int) $file->getSize() : 0);
        if (app(TenantStorageUsage::class)->wouldExceed($context->tenant(), $bytes)) {
            throw ValidationException::withMessages([
                'uploads' => 'Your tenant has reached its storage limit. Delete unused files or backups, or ask the Platform administrator to increase the tenant limit.',
            ]);
        }

        return $next($request);
    }
}
