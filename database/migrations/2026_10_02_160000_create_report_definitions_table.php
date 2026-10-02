<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('data_source', 50);
            $table->json('selected_fields');
            $table->json('filters')->nullable();
            $table->string('sort_field', 50)->nullable();
            $table->string('sort_direction', 4)->default('asc');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['data_source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
