<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->foreignId('teacher_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('groups')->whereNull('teacher_id')->exists()) {
            throw new RuntimeException('Assign teachers to every group before rolling back this migration.');
        }

        Schema::table('groups', function (Blueprint $table): void {
            $table->foreignId('teacher_id')->nullable(false)->change();
        });
    }
};
