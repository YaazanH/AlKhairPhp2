<?php

namespace App\Http\Middleware;

use App\Models\Landlord\TenantDomain;
use App\Services\Landlord\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantFromHost
{
    public function handle(Request $request, Closure $next): Response
    {
        // The platform portal is deliberately landlord-only, even when it is
        // hosted below the same base domain as tenant applications.
        if ($request->is('platform') || $request->is('platform/*')) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $baseDomain = strtolower(trim((string) config('tenancy.base_domain')));

        // The base host remains reserved for the platform and local tooling.
        // A tenant must always arrive through its registered subdomain.
        if ($host === $baseDomain) {
            return $next($request);
        }

        if (! str_ends_with($host, '.'.$baseDomain)) {
            abort(404);
        }

        $domain = TenantDomain::query()
            ->with('tenant')
            ->where('host', $host)
            ->first();

        if ($domain === null || ! $domain->tenant->isOperational() || blank($domain->tenant->database_name)) {
            abort(404);
        }

        $previousConnection = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        $previousPublicRoot = config('filesystems.disks.public.root');
        $previousPrivateRoot = config('filesystems.disks.local.root');
        $previousPublicUrl = config('filesystems.disks.public.url');
        $previousAppUrl = config('app.url');
        $tenantRoot = 'tenants/'.strtolower($domain->tenant->uuid);

        try {
            config()->set('database.connections.tenant.database', $domain->tenant->database_name);
            config()->set('filesystems.disks.public.root', storage_path('app/public/'.$tenantRoot));
            config()->set('filesystems.disks.local.root', storage_path('app/private/'.$tenantRoot));
            config()->set('filesystems.disks.public.url', $request->getSchemeAndHttpHost().'/storage');
            config()->set('app.url', $request->getSchemeAndHttpHost());
            DB::purge('tenant');
            app('filesystem')->forgetDisk('public');
            app('filesystem')->forgetDisk('local');
            DB::setDefaultConnection('tenant');
            app(TenantContext::class)->set($domain->tenant);

            return $next($request);
        } finally {
            app(TenantContext::class)->clear();
            DB::setDefaultConnection($previousConnection);
            config()->set('database.connections.tenant.database', $previousDatabase);
            config()->set('filesystems.disks.public.root', $previousPublicRoot);
            config()->set('filesystems.disks.local.root', $previousPrivateRoot);
            config()->set('filesystems.disks.public.url', $previousPublicUrl);
            config()->set('app.url', $previousAppUrl);
            DB::purge('tenant');
            app('filesystem')->forgetDisk('public');
            app('filesystem')->forgetDisk('local');
        }
    }
}
