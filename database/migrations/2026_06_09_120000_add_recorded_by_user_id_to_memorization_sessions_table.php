<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memorization_sessions', function (Blueprint $table) {
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->after('teacher_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memorization_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_user_id');
        });
    }
};
