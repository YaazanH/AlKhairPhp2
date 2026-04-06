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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_type_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->decimal('total_mark', 8, 2)->nullable();
            $table->decimal('pass_mark', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt_no')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'enrollment_id']);
        });

        Schema::create('assessment_score_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('from_mark', 8, 2);
            $table->decimal('to_mark', 8, 2);
            $table->foreignId('point_type_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('points')->nullable();
            $table->boolean('is_fail')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_score_bands');
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
    }
};
