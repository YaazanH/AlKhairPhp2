<?php

use App\Support\RoleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'report-dashboard-layout.manage';

    public function up(): void
    {
        Schema::create('report_dashboard_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('size', 10)->default('medium');
            $table->timestamps();

            $table->unique(['report_definition_id', 'role_id']);
            $table->index(['role_id', 'position']);
        });

        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');

        foreach ([RoleRegistry::SUPER_ADMIN, RoleRegistry::ADMIN] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if ($permissionId && $roleId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_dashboard_placements');

        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
