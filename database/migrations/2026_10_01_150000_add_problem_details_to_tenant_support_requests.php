<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->text('expected_result')->nullable()->after('message');
            $table->string('impact', 30)->nullable()->after('expected_result');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->dropColumn(['expected_result', 'impact']);
        });
    }
};
