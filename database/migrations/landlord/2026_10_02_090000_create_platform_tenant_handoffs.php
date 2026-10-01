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
        foreach ([
            ['support-access.read', 'Open tenants with read-only support access'],
            ['support-access.edit', 'Open tenants and make non-destructive changes'],
            ['support-access.delete', 'Open tenants and perform destructive tenant actions'],
        ] as [$code, $name]) {
            DB::connection('landlord')->table('platform_permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'description' => null, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        Schema::create('platform_tenant_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_administrator_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('access_level', 16);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('created_ip_address', 45)->nullable();
            $table->string('consumed_ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_tenant_handoffs');
        $permissionIds = DB::connection('landlord')->table('platform_permissions')
            ->whereIn('code', ['support-access.read', 'support-access.edit', 'support-access.delete'])
            ->pluck('id');
        DB::connection('landlord')->table('platform_permission_role')->whereIn('platform_permission_id', $permissionIds)->delete();
        DB::connection('landlord')->table('platform_permissions')->whereIn('id', $permissionIds)->delete();
    }
};
