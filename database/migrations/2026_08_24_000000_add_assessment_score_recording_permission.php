<?php

use App\Support\RoleRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'assessment-results.record-scores';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');
        $allowedRoles = [
            RoleRegistry::SUPER_ADMIN,
            RoleRegistry::ADMIN,
            RoleRegistry::MANAGER,
        ];

        Role::query()
            ->whereIn('name', $allowedRoles)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        Role::query()
            ->whereNotIn('name', $allowedRoles)
            ->get()
            ->each(function (Role $role) use ($permission): void {
                if ($role->hasPermissionTo($permission)) {
                    $role->revokePermissionTo($permission);
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
