<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_generated_reports', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable()->after('report_data');
        });
    }

    public function down(): void
    {
        Schema::table('finance_generated_reports', function (Blueprint $table): void {
            $table->dropColumn('pdf_path');
        });
    }
};
