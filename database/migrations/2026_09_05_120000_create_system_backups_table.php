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
        Schema::create('system_backups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk')->default('local');
            $table->string('file_path')->unique();
            $table->string('filename');
            $table->string('trigger')->index();
            $table->string('status')->index();
            $table->boolean('includes_files')->default(true);
            $table->boolean('encrypted')->default(true);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('manifest_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->unsignedInteger('restore_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('backups.manage', 'web');

        Role::query()
            ->whereIn('name', [RoleRegistry::SUPER_ADMIN, RoleRegistry::ADMIN])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        Role::query()
            ->whereNotIn('name', [RoleRegistry::SUPER_ADMIN, RoleRegistry::ADMIN])
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('system_backups');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'backups.manage')
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
