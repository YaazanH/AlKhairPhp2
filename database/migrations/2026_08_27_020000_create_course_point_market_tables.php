<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_point_market_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('point_price', 18, 2)->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'name']);
        });

        Schema::create('course_point_market_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'invoice_id']);
        });

        Schema::create('course_point_market_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_point_market_department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->string('currency_code', 10);
            $table->string('currency_symbol', 20)->nullable();
            $table->unsignedTinyInteger('currency_decimal_places')->default(2);
            $table->decimal('local_unit_price', 18, 2);
            $table->string('local_currency_code', 10);
            $table->string('local_currency_symbol', 20)->nullable();
            $table->unsignedTinyInteger('local_currency_decimal_places')->default(2);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'invoice_item_id']);
            $table->index(['course_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_point_market_items');
        Schema::dropIfExists('course_point_market_invoices');
        Schema::dropIfExists('course_point_market_departments');
    }
};
