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
    private const PERMISSIONS = [
        'print-templates.view',
        'print-templates.manage',
        'print-templates.print',
    ];

    public function up(): void
    {
        Schema::table('print_templates', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('is_active')->index();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        Role::query()
            ->whereIn('name', RoleRegistry::unrestrictedRoles())
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('print_templates', function (Blueprint $table): void {
            $table->dropIndex(['is_system']);
            $table->dropColumn('is_system');
        });
    }
};
