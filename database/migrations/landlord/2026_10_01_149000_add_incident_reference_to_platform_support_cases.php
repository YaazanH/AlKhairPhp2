<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::table('platform_support_cases', function (Blueprint $table): void {
            $table->string('incident_reference', 32)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('platform_support_cases', function (Blueprint $table): void {
            $table->dropColumn('incident_reference');
        });
    }
};
