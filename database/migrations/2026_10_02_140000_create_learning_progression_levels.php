<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_progression_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order');
            $table->decimal('attendance_threshold', 5, 2);
            $table->foreignId('final_assessment_id')->unique()->constrained('assessments')->restrictOnDelete();
            $table->decimal('passing_score', 8, 2);
            $table->timestamps();

            $table->index('sort_order');
        });

        Schema::create('learning_progression_level_lesson', function (Blueprint $table): void {
            $table->foreignId('learning_progression_level_id');
            $table->foreignId('curriculum_lesson_id');
            $table->foreign('learning_progression_level_id', 'progression_lesson_level_fk')
                ->references('id')->on('learning_progression_levels')->cascadeOnDelete();
            $table->foreign('curriculum_lesson_id', 'progression_lesson_curriculum_fk')
                ->references('id')->on('curriculum_lessons')->restrictOnDelete();
            $table->primary(['learning_progression_level_id', 'curriculum_lesson_id'], 'progression_level_lesson_primary');
        });

        Schema::create('learning_progression_level_group', function (Blueprint $table): void {
            $table->foreignId('learning_progression_level_id');
            $table->foreignId('group_id')->constrained()->restrictOnDelete();
            $table->foreign('learning_progression_level_id', 'progression_group_level_fk')
                ->references('id')->on('learning_progression_levels')->cascadeOnDelete();
            $table->primary(['learning_progression_level_id', 'group_id'], 'progression_level_group_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_progression_level_group');
        Schema::dropIfExists('learning_progression_level_lesson');
        Schema::dropIfExists('learning_progression_levels');
    }
};
