<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('template', 50)->default('page');
            $table->json('title')->nullable();
            $table->json('navigation_label')->nullable();
            $table->json('excerpt')->nullable();
            $table->json('body')->nullable();
            $table->json('sections')->nullable();
            $table->json('settings')->nullable();
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->string('hero_media_path')->nullable();
            $table->boolean('is_home')->default(false);
            $table->boolean('is_published')->default(true);
            $table->boolean('show_in_navigation')->default(false);
            $table->unsignedInteger('navigation_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_pages');
    }
};
