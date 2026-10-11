<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::table('saas_platform_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('metrics_refresh_minutes')->default(5);
        });

        Schema::create('platform_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('resolution', 16)->default('interval');
            $table->timestamp('captured_at');
            $table->unsignedInteger('tenant_total')->default(0);
            $table->unsignedInteger('tenant_active')->default(0);
            $table->unsignedInteger('tenant_trial')->default(0);
            $table->unsignedInteger('tenant_suspended')->default(0);
            $table->unsignedInteger('tenant_failed')->default(0);
            $table->unsignedBigInteger('tenant_storage_bytes')->default(0);
            $table->unsignedBigInteger('server_used_bytes')->nullable();
            $table->decimal('server_used_percent', 5, 2)->nullable();
            $table->decimal('cpu_average', 8, 2)->nullable();
            $table->decimal('ram_average', 8, 2)->nullable();
            $table->double('incoming_total')->nullable();
            $table->double('outgoing_total')->nullable();
            $table->string('network_unit', 24)->nullable();
            $table->timestamp('latest_backup_at')->nullable();
            $table->unsignedInteger('failed_backups')->default(0);
            $table->string('infrastructure_status', 24)->nullable();
            $table->string('vps_state', 24)->nullable();
            $table->timestamps();

            $table->index(['resolution', 'captured_at'], 'platform_metric_resolution_captured_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_metric_snapshots');

        Schema::table('saas_platform_settings', function (Blueprint $table): void {
            $table->dropColumn('metrics_refresh_minutes');
        });
    }
};
