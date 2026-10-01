<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PlatformAccessManager
{
    public function createRole(array $attributes, array $permissionIds, PlatformAdministrator $actor, ?string $ipAddress): PlatformRole
    {
        return DB::connection('landlord')->transaction(function () use ($attributes, $permissionIds, $actor, $ipAddress): PlatformRole {
            $role = PlatformRole::query()->create(['name' => $attributes['name'], 'description' => $attributes['description'] ?? null]);
            $role->permissions()->sync($permissionIds);
            $this->audit($actor, 'platform_role_created', ['role_id' => $role->id, 'name' => $role->name], $ipAddress);

            return $role;
        });
    }

    public function updateRole(PlatformRole $role, array $attributes, array $permissionIds, PlatformAdministrator $actor, ?string $ipAddress): void
    {
        if ($role->is_owner) {
            throw new LogicException('The Owner role cannot be changed.');
        }

        DB::connection('landlord')->transaction(function () use ($role, $attributes, $permissionIds, $actor, $ipAddress): void {
            $role->update(['name' => $attributes['name'], 'description' => $attributes['description'] ?? null]);
            $role->permissions()->sync($permissionIds);
            $this->audit($actor, 'platform_role_updated', ['role_id' => $role->id, 'name' => $role->name], $ipAddress);
        });
    }

    public function deleteRole(PlatformRole $role, PlatformAdministrator $actor, ?string $ipAddress): void
    {
        if ($role->is_owner) {
            throw new LogicException('The Owner role cannot be deleted.');
        }

        DB::connection('landlord')->transaction(function () use ($role, $actor, $ipAddress): void {
            $properties = ['role_id' => $role->id, 'name' => $role->name];
            $role->delete();
            $this->audit($actor, 'platform_role_deleted', $properties, $ipAddress);
        });
    }

    public function createAdministrator(array $attributes, array $roleIds, PlatformAdministrator $actor, ?string $ipAddress): PlatformAdministrator
    {
        return DB::connection('landlord')->transaction(function () use ($attributes, $roleIds, $actor, $ipAddress): PlatformAdministrator {
            $administrator = PlatformAdministrator::query()->create([
                'uuid' => (string) Str::uuid(), 'name' => $attributes['name'], 'email' => Str::lower($attributes['email']),
                'password' => $attributes['password'], 'is_active' => true, 'must_change_password' => true,
            ]);
            $administrator->roles()->sync($roleIds);
            $this->audit($actor, 'platform_user_created', ['administrator_id' => $administrator->id, 'email' => $administrator->email], $ipAddress);

            return $administrator;
        });
    }

    public function updateAdministrator(PlatformAdministrator $administrator, array $attributes, PlatformAdministrator $actor, ?string $ipAddress): void
    {
        DB::connection('landlord')->transaction(function () use ($administrator, $attributes, $actor, $ipAddress): void {
            $administrator->update(['name' => $attributes['name'], 'email' => Str::lower($attributes['email'])]);
            $this->audit($actor, 'platform_user_updated', ['administrator_id' => $administrator->id, 'email' => $administrator->email], $ipAddress);
        });
    }

    public function syncAdministratorRoles(PlatformAdministrator $administrator, array $roleIds, PlatformAdministrator $actor, ?string $ipAddress): void
    {
        $ownerRole = PlatformRole::query()->where('is_owner', true)->sole();
        $willRemainOwner = in_array($ownerRole->id, $roleIds, true);
        if ($administrator->isPlatformOwner() && ! $willRemainOwner && $this->activeOwnerCount() <= 1) {
            throw new LogicException('At least one active Platform Owner must remain.');
        }

        DB::connection('landlord')->transaction(function () use ($administrator, $roleIds, $actor, $ipAddress): void {
            $administrator->roles()->sync($roleIds);
            $this->audit($actor, 'platform_user_roles_updated', ['administrator_id' => $administrator->id, 'role_ids' => array_values($roleIds)], $ipAddress);
        });
    }

    public function setAdministratorActive(PlatformAdministrator $administrator, bool $isActive, PlatformAdministrator $actor, ?string $ipAddress): void
    {
        if (! $isActive && $administrator->is_active && $administrator->isPlatformOwner() && $this->activeOwnerCount() <= 1) {
            throw new LogicException('At least one active Platform Owner must remain.');
        }

        $administrator->update(['is_active' => $isActive]);
        $this->audit($actor, $isActive ? 'platform_user_activated' : 'platform_user_deactivated', ['administrator_id' => $administrator->id], $ipAddress);
    }

    public function resetAdministratorPassword(PlatformAdministrator $administrator, string $password, PlatformAdministrator $actor, ?string $ipAddress): void
    {
        $administrator->update(['password' => $password, 'must_change_password' => true, 'password_changed_at' => null, 'remember_token' => Str::random(60)]);
        $this->audit($actor, 'platform_user_password_reset', ['administrator_id' => $administrator->id], $ipAddress);
    }

    private function activeOwnerCount(): int
    {
        return PlatformAdministrator::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('is_owner', true))->count();
    }

    private function audit(PlatformAdministrator $actor, string $event, array $properties, ?string $ipAddress): void
    {
        PlatformAuditEvent::query()->create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $actor->id, 'event' => $event, 'properties' => $properties, 'ip_address' => $ipAddress]);
    }
}
