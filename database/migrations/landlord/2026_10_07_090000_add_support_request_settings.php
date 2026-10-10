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
            $table->json('support_request_options')->nullable()->after('suspended_data_retention_months');
        });

        Schema::table('platform_support_cases', function (Blueprint $table): void {
            $table->string('problem_reason', 80)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('platform_support_cases', function (Blueprint $table): void {
            $table->dropColumn('problem_reason');
        });

        Schema::table('saas_platform_settings', function (Blueprint $table): void {
            $table->dropColumn('support_request_options');
        });
    }
};
