<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_tenant_administrator')->default(false)->after('is_active');
            $table->boolean('must_change_password')->default(false)->after('is_tenant_administrator');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });

        DB::table('users')->update(['password_changed_at' => now()]);

        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if (! $adminRoleId) {
            return;
        }

        $query = DB::table('model_has_roles')
            ->where('role_id', $adminRoleId)
            ->where('model_type', 'App\\Models\\User')
            ->orderBy('model_id');

        if (Schema::hasTable('tenant_platform_administrator_links')) {
            $query->whereNotIn('model_id', DB::table('tenant_platform_administrator_links')->select('user_id'));
        }

        $tenantAdministratorId = $query->value('model_id');
        if ($tenantAdministratorId) {
            $tenantAdministrator = DB::table('users')->where('id', $tenantAdministratorId)->first(['username', 'email']);

            DB::table('users')->where('id', $tenantAdministratorId)->update([
                'is_tenant_administrator' => true,
                'username' => filled($tenantAdministrator?->username) ? $tenantAdministrator->username : $tenantAdministrator?->email,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_tenant_administrator', 'must_change_password', 'password_changed_at']);
        });
    }
};
