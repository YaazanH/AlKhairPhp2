<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_menus', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('title')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('website_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('website_menu_items')->cascadeOnDelete();
            $table->foreignId('website_page_id')->nullable()->constrained('website_pages')->nullOnDelete();
            $table->json('label')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_menu_items');
        Schema::dropIfExists('website_menus');
    }
};
