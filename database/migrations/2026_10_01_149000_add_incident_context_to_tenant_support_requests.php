<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->string('incident_reference', 32)->nullable()->unique()->after('priority');
            $table->string('app_version', 100)->nullable()->after('browser_info');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->dropUnique(['incident_reference']);
            $table->dropColumn(['incident_reference', 'app_version']);
        });
    }
};
