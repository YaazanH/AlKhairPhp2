<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_learning_progressions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('current_level_id')->constrained('learning_progression_levels')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('level_started_at');
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_level_id']);
        });

        Schema::create('student_learning_progression_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_learning_progression_id');
            $table->foreign('student_learning_progression_id', 'student_progression_history_progression_fk')
                ->references('id')->on('student_learning_progressions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_level_id')->nullable()->constrained('learning_progression_levels')->restrictOnDelete();
            $table->foreignId('to_level_id')->nullable()->constrained('learning_progression_levels')->restrictOnDelete();
            $table->string('event', 30);
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['student_id', 'occurred_at'], 'student_progression_history_student_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_learning_progression_history');
        Schema::dropIfExists('student_learning_progressions');
    }
};
