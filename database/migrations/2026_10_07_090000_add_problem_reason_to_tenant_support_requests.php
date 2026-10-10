<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->string('problem_reason', 80)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_support_requests', function (Blueprint $table): void {
            $table->dropColumn('problem_reason');
        });
    }
};
