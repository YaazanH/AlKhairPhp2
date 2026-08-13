<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'dashboard.group-teacher.view', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $obsoleteId = DB::table('permissions')->where('name', 'curricula.view')->value('id');
        if ($obsoleteId) {
            DB::table('role_has_permissions')->where('permission_id', $obsoleteId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $obsoleteId)->delete();
            DB::table('permissions')->where('id', $obsoleteId)->delete();
        }

        Schema::table('finance_currencies', function (Blueprint $table): void {
            $table->boolean('show_in_dropdowns')->default(true)->after('is_active');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'dashboard.group-teacher.view')->value('id');
        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        DB::table('permissions')->insertOrIgnore([
            'name' => 'curricula.view',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::table('finance_currencies', fn (Blueprint $table) => $table->dropColumn('show_in_dropdowns'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
