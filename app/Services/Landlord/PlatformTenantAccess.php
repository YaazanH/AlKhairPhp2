<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformTenantHandoff;
use App\Models\Landlord\Tenant;
use App\Models\TenantPlatformAdministratorLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformTenantAccess
{
    public const SESSION_KEY = 'platform_support_access';

    public const SUPPORT_ACTOR_NAME = 'Platform Management';

    public function createHandoff(PlatformAdministrator $administrator, Tenant $tenant, string $level, ?string $ipAddress): array
    {
        if (! in_array($level, [PlatformTenantHandoff::LEVEL_READ, PlatformTenantHandoff::LEVEL_EDIT, PlatformTenantHandoff::LEVEL_DELETE], true)) {
            abort(422);
        }

        if (! $administrator->hasPlatformPermission('support-access.'.$level)) {
            $this->audit($administrator, $tenant, 'tenant_support_handoff_denied', [
                'reason' => 'permission_denied',
                'access_level' => $level,
            ], $ipAddress);
            abort(403);
        }

        if (! $tenant->isOperational() || blank($tenant->database_name)) {
            throw ValidationException::withMessages(['access_level' => 'This tenant is not available for support access.']);
        }

        $utcNow = CarbonImmutable::now('UTC');
        $nonce = Str::random(64);
        $plainToken = $nonce.'.'.hash_hmac('sha256', $nonce, (string) config('app.key'));
        $handoff = PlatformTenantHandoff::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'platform_administrator_id' => $administrator->id,
            'token_hash' => hash('sha256', $plainToken),
            'access_level' => $level,
            'expires_at' => $utcNow->addMinutes(5),
            'created_ip_address' => $ipAddress,
        ]);

        $this->audit($administrator, $tenant, 'tenant_support_handoff_created', [
            'handoff_uuid' => $handoff->uuid,
            'access_level' => $level,
            'expires_at' => $handoff->expires_at->toIso8601String(),
        ], $ipAddress);

        return [$handoff, $plainToken];
    }

    public function consume(string $plainToken, Tenant $tenant, ?string $ipAddress): array
    {
        if (! $this->tokenIsAuthentic($plainToken)) {
            $this->audit(null, $tenant, 'tenant_support_handoff_denied', ['reason' => 'invalid_signature'], $ipAddress);
            abort(404);
        }

        $result = DB::connection('landlord')->transaction(function () use ($plainToken, $tenant, $ipAddress): array {
            $handoff = PlatformTenantHandoff::query()
                ->with('platformAdministrator')
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $handoff) {
                return ['handoff' => null, 'administrator' => null, 'failure' => 'invalid_token'];
            }

            if ($handoff->tenant_id !== $tenant->id) {
                return ['handoff' => $handoff, 'administrator' => $handoff->platformAdministrator, 'failure' => 'tenant_mismatch'];
            }

            $administrator = $handoff->platformAdministrator;
            $utcNow = CarbonImmutable::now('UTC');
            $expiresAt = CarbonImmutable::parse((string) $handoff->getRawOriginal('expires_at'), 'UTC');
            $failure = match (true) {
                $handoff->consumed_at !== null => 'already_consumed',
                $expiresAt->lessThan($utcNow) => 'expired',
                ! $administrator?->is_active => 'administrator_inactive',
                ! $administrator?->hasPlatformPermission('support-access.'.$handoff->access_level) => 'permission_revoked',
                default => null,
            };

            if ($failure) {
                return compact('handoff', 'administrator', 'failure');
            }

            $handoff->forceFill([
                'consumed_at' => $utcNow,
                'consumed_ip_address' => $ipAddress,
                'updated_at' => $utcNow,
            ])->save();
            $user = $this->tenantSupportUser($administrator);

            $this->audit($administrator, $tenant, 'tenant_support_session_started', [
                'handoff_uuid' => $handoff->uuid,
                'access_level' => $handoff->access_level,
                'expires_at' => $utcNow->addMinutes(60)->toIso8601String(),
            ], $ipAddress);

            return ['session' => [$user, [
                'handoff_uuid' => $handoff->uuid,
                'tenant_uuid' => $tenant->uuid,
                'platform_administrator_id' => $administrator->id,
                'platform_administrator_uuid' => $administrator->uuid,
                'access_level' => $handoff->access_level,
                'expires_at' => $utcNow->addMinutes(60)->timestamp,
            ]]];
        });

        if (isset($result['failure'])) {
            $this->audit($result['administrator'], $tenant, 'tenant_support_handoff_denied', [
                'handoff_uuid' => $result['handoff']?->uuid,
                'reason' => $result['failure'],
            ], $ipAddress);
            abort($result['failure'] === 'invalid_token' ? 404 : 403, 'This support access link is no longer valid.');
        }

        return $result['session'];
    }

    private function tokenIsAuthentic(string $token): bool
    {
        [$nonce, $signature] = array_pad(explode('.', $token, 2), 2, null);

        return filled($nonce)
            && filled($signature)
            && hash_equals(hash_hmac('sha256', $nonce, (string) config('app.key')), $signature);
    }

    private function tenantSupportUser(PlatformAdministrator $administrator): User
    {
        $linkedUser = TenantPlatformAdministratorLink::query()
            ->where('platform_administrator_uuid', $administrator->uuid)
            ->first()?->user;

        if ($linkedUser) {
            if (! $linkedUser->is_tenant_administrator) {
                $linkedUser->forceFill([
                    'name' => self::SUPPORT_ACTOR_NAME,
                    'username' => $this->supportUsername($administrator),
                    'email' => $this->supportEmail($administrator),
                    'password' => Hash::make(Str::random(64)),
                    'issued_password' => null,
                    'must_change_password' => false,
                    'password_changed_at' => CarbonImmutable::now('UTC'),
                ])->saveQuietly();
            }

            return $linkedUser;
        }

        $user = User::query()->create([
            'name' => self::SUPPORT_ACTOR_NAME,
            'username' => $this->supportUsername($administrator),
            'email' => $this->supportEmail($administrator),
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => CarbonImmutable::now('UTC'),
        ]);
        $user->assignRole('super_admin');
        TenantPlatformAdministratorLink::query()->create([
            'user_id' => $user->id,
            'platform_administrator_uuid' => $administrator->uuid,
        ]);

        return $user;
    }

    private function supportUsername(PlatformAdministrator $administrator): string
    {
        return 'platform-'.Str::lower(Str::substr(str_replace('-', '', $administrator->uuid), 0, 16));
    }

    private function supportEmail(PlatformAdministrator $administrator): string
    {
        return 'platform-'.Str::lower($administrator->uuid).'@support.invalid';
    }

    private function audit(?PlatformAdministrator $administrator, Tenant $tenant, string $event, array $properties, ?string $ipAddress): void
    {
        $utcNow = CarbonImmutable::now('UTC');

        PlatformAuditEvent::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'platform_administrator_id' => $administrator?->id,
            'tenant_id' => $tenant->id,
            'event' => $event,
            'properties' => $properties,
            'ip_address' => $ipAddress,
            'created_at' => $utcNow,
            'updated_at' => $utcNow,
        ]);
    }
}
