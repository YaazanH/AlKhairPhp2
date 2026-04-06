<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('grade_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('scope', 20)->default('both');
            $table->integer('default_points')->default(0);
            $table->string('color', 32)->nullable();
            $table->boolean('is_present')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('assessment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_scored')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('quran_test_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('point_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('category', 50);
            $table->integer('default_points')->default(0);
            $table->boolean('allow_manual_entry')->default(true);
            $table->boolean('allow_negative')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('point_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('point_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source_type', 50);
            $table->string('trigger_key', 100);
            $table->foreignId('grade_level_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('from_value', 8, 2)->nullable();
            $table->decimal('to_value', 8, 2)->nullable();
            $table->integer('points');
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group');
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type', 50)->default('string');
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::create('quran_juzs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('juz_number')->unique();
            $table->unsignedSmallInteger('from_page');
            $table->unsignedSmallInteger('to_page');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quran_juzs');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('point_policies');
        Schema::dropIfExists('point_types');
        Schema::dropIfExists('quran_test_types');
        Schema::dropIfExists('assessment_types');
        Schema::dropIfExists('attendance_statuses');
        Schema::dropIfExists('grade_levels');
        Schema::dropIfExists('academic_years');
    }
};
