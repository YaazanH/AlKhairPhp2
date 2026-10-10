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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('storage_limit_bytes')->nullable()->after('logo_path');
        });

        DB::connection('landlord')->statement(<<<'SQL'
            UPDATE tenants
            SET storage_limit_bytes = (
                SELECT plans.storage_limit_bytes
                FROM tenant_subscriptions
                INNER JOIN plans ON plans.id = tenant_subscriptions.plan_id
                WHERE tenant_subscriptions.tenant_id = tenants.id
                LIMIT 1
            )
        SQL);

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('storage_limit_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('storage_limit_bytes')->nullable()->after('is_active');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('storage_limit_bytes');
        });
    }
};
