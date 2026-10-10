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
        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->string('period_type', 20)->default('monthly')->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('grace_ends_at');
            $table->unsignedBigInteger('cancelled_by_platform_administrator_id')->nullable()->after('cancelled_at');
            $table->foreign(
                'cancelled_by_platform_administrator_id',
                'tenant_subscription_cancelled_by_foreign',
            )->references('id')->on('platform_administrators')->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('status');
        });

        Schema::create('saas_platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('suspended_data_retention_months')->default(12);
            $table->timestamps();
        });

        DB::connection('landlord')->table('saas_platform_settings')->insert([
            'suspended_data_retention_months' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['view.subscriptions', 'View subscriptions'],
            ['manage.subscriptions', 'Manage subscriptions'],
        ] as [$code, $name]) {
            DB::connection('landlord')->table('platform_permissions')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'description' => $name.' and lifecycle information.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('landlord')->table('platform_permissions')
            ->whereIn('code', ['view.subscriptions', 'manage.subscriptions'])
            ->delete();

        Schema::dropIfExists('saas_platform_settings');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('suspended_at');
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table): void {
            $table->dropForeign('tenant_subscription_cancelled_by_foreign');
            $table->dropColumn([
                'period_type',
                'cancelled_at',
                'cancelled_by_platform_administrator_id',
            ]);
        });
    }
};
