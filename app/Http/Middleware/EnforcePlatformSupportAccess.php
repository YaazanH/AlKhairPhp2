<?php

namespace App\Http\Middleware;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\TenantPlatformAdministratorLink;
use App\Services\Landlord\PlatformTenantAccess;
use App\Services\Landlord\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforcePlatformSupportAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $support = $request->session()->get(PlatformTenantAccess::SESSION_KEY);

        if (! is_array($support)) {
            return $next($request);
        }

        // Returning to the landlord portal deliberately leaves the temporary
        // tenant-support identity. Do not treat the missing tenant context on
        // Platform routes as an expired tenant session and redirect to the
        // tenant login page.
        if ($request->routeIs('platform.*') || $request->is('platform') || $request->is('platform/*')) {
            Auth::guard('web')->logout();
            $request->session()->forget(PlatformTenantAccess::SESSION_KEY);

            return $next($request);
        }

        $context = app(TenantContext::class);
        $tenant = $context->hasTenant() ? $context->tenant() : null;
        $administrator = PlatformAdministrator::query()->find($support['platform_administrator_id'] ?? null);
        $linkMatches = Auth::guard('web')->check()
            && TenantPlatformAdministratorLink::query()
                ->where('user_id', Auth::guard('web')->id())
                ->where('platform_administrator_uuid', $support['platform_administrator_uuid'] ?? '')
                ->exists();
        $reason = match (true) {
            ! $tenant || ($support['tenant_uuid'] ?? null) !== $tenant->uuid => 'tenant_mismatch',
            (int) ($support['expires_at'] ?? 0) <= now()->timestamp => 'expired',
            ! $administrator?->is_active => 'administrator_inactive',
            ! $administrator?->hasPlatformPermission('support-access.'.($support['access_level'] ?? '')) => 'permission_revoked',
            ! $linkMatches => 'linked_account_mismatch',
            default => null,
        };

        if ($reason !== null) {
            $this->endSession($request, $administrator, $tenant, $support, $reason);

            if ($request->expectsJson()) {
                abort(401, 'Platform support session expired.');
            }

            return redirect()->route('login')->with('status', 'The Platform support session has ended.');
        }

        if ($request->routeIs('logout')) {
            $this->recordSessionEnd($request, $administrator, $tenant, $support, 'user_logout');

            return $next($request);
        }

        if (! $this->allows($request, (string) $support['access_level'])) {
            PlatformAuditEvent::query()->create([
                'uuid' => (string) Str::uuid(),
                'platform_administrator_id' => $administrator->id,
                'tenant_id' => $tenant->id,
                'event' => 'tenant_support_action_denied',
                'properties' => [
                    'handoff_uuid' => $support['handoff_uuid'] ?? null,
                    'access_level' => $support['access_level'],
                    'method' => $request->method(),
                    'route' => $request->route()?->getName(),
                ],
                'ip_address' => $request->ip(),
            ]);
            abort(403, 'This Platform support session does not allow that action.');
        }

        return $next($request);
    }

    private function allows(Request $request, string $level): bool
    {
        if ($request->isMethodSafe()) {
            return true;
        }

        if ($level === 'read') {
            return false;
        }

        if ($level === 'delete') {
            return true;
        }

        return ! $this->isDestructive($request);
    }

    private function isDestructive(Request $request): bool
    {
        if ($request->isMethod('DELETE')) {
            return true;
        }

        $routeName = strtolower(implode('|', [
            (string) $request->route()?->getName(),
            (string) $request->route()?->getActionName(),
            (string) $request->route()?->uri(),
        ]));
        $destructiveWords = ['delete', 'destroy', 'void', 'restore', 'cancel', 'archive', 'remove'];

        if (collect($destructiveWords)->contains(fn (string $word): bool => str_contains($routeName, $word))) {
            return true;
        }

        $middleware = collect($request->route()?->gatherMiddleware() ?? [])->implode('|');

        if (collect($destructiveWords)->contains(fn (string $word): bool => str_contains(strtolower($middleware), $word))) {
            return true;
        }

        $livewireMethods = collect($request->input('components', []))
            ->flatMap(fn (array $component): array => $component['calls'] ?? [])
            ->pluck('method')
            ->filter()
            ->map(fn (string $method): string => strtolower($method));

        return $livewireMethods->contains(fn (string $method): bool => collect($destructiveWords)
            ->contains(fn (string $word): bool => str_contains($method, $word)));
    }

    private function endSession(Request $request, ?PlatformAdministrator $administrator, mixed $tenant, array $support, string $reason): void
    {
        $this->recordSessionEnd($request, $administrator, $tenant, $support, $reason);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function recordSessionEnd(Request $request, ?PlatformAdministrator $administrator, mixed $tenant, array $support, string $reason): void
    {
        if (! $tenant) {
            return;
        }

        PlatformAuditEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $administrator?->id,
            'tenant_id' => $tenant->id,
            'event' => 'tenant_support_session_ended',
            'properties' => ['handoff_uuid' => $support['handoff_uuid'] ?? null, 'reason' => $reason],
            'ip_address' => $request->ip(),
        ]);
    }
}
