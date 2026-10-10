<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->json('relationships')->nullable()->after('data_source');
        });
    }

    public function down(): void
    {
        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->dropColumn('relationships');
        });
    }
};
