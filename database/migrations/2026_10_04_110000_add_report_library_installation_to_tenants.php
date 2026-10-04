<?php

use App\Support\RoleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->uuid('library_item_uuid')->nullable()->after('status')->index();
            $table->unsignedInteger('library_revision')->nullable()->after('library_item_uuid');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('report-library.install', 'web');

        Role::query()
            ->whereIn('name', [RoleRegistry::SUPER_ADMIN, RoleRegistry::ADMIN])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->dropColumn(['library_item_uuid', 'library_revision']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->where('guard_name', 'web')->where('name', 'report-library.install')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
