<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quran_partial_test_attempts', function (Blueprint $table) {
            $table->unsignedInteger('mistake_count')->nullable()->after('tested_on');
        });
    }

    public function down(): void
    {
        Schema::table('quran_partial_test_attempts', function (Blueprint $table) {
            $table->dropColumn('mistake_count');
        });
    }
};
