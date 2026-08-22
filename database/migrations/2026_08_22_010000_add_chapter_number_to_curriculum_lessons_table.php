<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_lessons', function (Blueprint $table): void {
            $table->string('chapter_number', 40)->nullable()->before('name');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_lessons', function (Blueprint $table): void {
            $table->dropColumn('chapter_number');
        });
    }
};
