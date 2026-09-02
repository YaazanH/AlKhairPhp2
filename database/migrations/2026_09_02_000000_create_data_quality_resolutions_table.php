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
        Schema::create('data_quality_resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('issue_key')->unique();
            $table->string('issue_type')->index();
            $table->string('status')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect([
            'data-quality.view',
            'data-quality.resolve',
            'data-audit.view',
        ])->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        $allowedRoles = [RoleRegistry::SUPER_ADMIN, RoleRegistry::ADMIN];

        Role::query()
            ->whereIn('name', $allowedRoles)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        Role::query()
            ->whereNotIn('name', $allowedRoles)
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('data_quality_resolutions');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['data-quality.view', 'data-quality.resolve', 'data-audit.view'])
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
