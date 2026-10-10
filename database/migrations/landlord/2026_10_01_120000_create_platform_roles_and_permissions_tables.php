<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->id(); $table->string('name')->unique(); $table->text('description')->nullable(); $table->boolean('is_owner')->default(false); $table->timestamps();
        });
        Schema::create('platform_permissions', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->text('description')->nullable(); $table->timestamps();
        });
        Schema::create('platform_permission_role', function (Blueprint $table) {
            $table->foreignId('platform_role_id')->constrained('platform_roles')->cascadeOnDelete(); $table->foreignId('platform_permission_id')->constrained('platform_permissions')->cascadeOnDelete(); $table->primary(['platform_role_id', 'platform_permission_id']);
        });
        Schema::create('platform_administrator_role', function (Blueprint $table) {
            $table->foreignId('platform_administrator_id')->constrained('platform_administrators')->cascadeOnDelete(); $table->foreignId('platform_role_id')->constrained('platform_roles')->cascadeOnDelete(); $table->primary(['platform_administrator_id', 'platform_role_id']);
        });

        $ownerId = DB::connection('landlord')->table('platform_roles')->insertGetId(['name' => 'Owner', 'description' => 'Full Platform control', 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            ['view.dashboard', 'View dashboard'], ['view.tenants', 'View tenants'], ['manage.tenants', 'Manage tenants'],
            ['manage.plans', 'Manage packages'], ['view.backups', 'View backups'], ['manage.backups', 'Manage backup settings'],
            ['restore.backups', 'Restore backups'], ['view.storage', 'View storage'], ['manage.platform-users', 'Manage Platform users'],
            ['manage.platform-roles', 'Manage Platform roles'],
        ] as [$code, $name]) {
            DB::connection('landlord')->table('platform_permissions')->insert(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $firstAdministrator = DB::connection('landlord')->table('platform_administrators')->orderBy('id')->value('id');
        if ($firstAdministrator) {
            DB::connection('landlord')->table('platform_administrator_role')->insert(['platform_administrator_id' => $firstAdministrator, 'platform_role_id' => $ownerId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_administrator_role'); Schema::dropIfExists('platform_permission_role'); Schema::dropIfExists('platform_permissions'); Schema::dropIfExists('platform_roles');
    }
};