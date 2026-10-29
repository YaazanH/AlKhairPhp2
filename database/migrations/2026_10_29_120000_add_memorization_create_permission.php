<?php

use App\Support\RoleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'memorization.create',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('name', 'memorization.create')->where('guard_name', 'web')->value('id');
        $roleIds = DB::table('roles')->where('guard_name', 'web')
            ->whereIn('name', [RoleRegistry::SUPER_ADMIN, RoleRegistry::ADMIN, RoleRegistry::MANAGER])->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->where('name', 'memorization.create')->where('guard_name', 'web')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
