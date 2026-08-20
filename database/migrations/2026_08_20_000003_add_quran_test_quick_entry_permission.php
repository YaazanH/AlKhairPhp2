<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'name' => 'quran-tests.quick-entry',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'quran-tests.quick-entry')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId) {
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
