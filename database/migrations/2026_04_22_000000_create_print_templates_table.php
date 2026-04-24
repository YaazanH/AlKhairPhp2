<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('width_mm', 8, 2)->default(85.60);
            $table->decimal('height_mm', 8, 2)->default(53.98);
            $table->string('background_image')->nullable();
            $table->json('data_sources')->nullable();
            $table->json('layout_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};
