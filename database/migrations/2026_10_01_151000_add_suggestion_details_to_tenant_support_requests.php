<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->text('desired_outcome')->nullable()->after('message');
            $table->text('current_workaround')->nullable()->after('desired_outcome');
            $table->string('affected_users', 500)->nullable()->after('current_workaround');
            $table->string('business_impact', 20)->nullable()->after('affected_users');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->dropColumn(['desired_outcome', 'current_workaround', 'affected_users', 'business_impact']);
        });
    }
};
