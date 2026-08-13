<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_lessons', function (Blueprint $table): void {
            $table->foreignId('curriculum_resource_id')->nullable()->after('curriculum_subject_id')->constrained('curriculum_resources')->nullOnDelete();
            $table->index(['curriculum_subject_id', 'curriculum_resource_id'], 'curriculum_lesson_subject_resource_index');
        });

        Schema::create('curriculum_lesson_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curriculum_lesson_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['curriculum_lesson_id', 'sort_order']);
        });

        Schema::create('group_curriculum_topic_progresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_lesson_topic_id')->constrained('curriculum_lesson_topics')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->date('taught_on');
            $table->timestamps();
            $table->unique(['group_id', 'curriculum_lesson_topic_id'], 'group_curriculum_topic_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_curriculum_topic_progresses');
        Schema::dropIfExists('curriculum_lesson_topics');
        Schema::table('curriculum_lessons', function (Blueprint $table): void {
            $table->dropIndex('curriculum_lesson_subject_resource_index');
            $table->dropConstrainedForeignId('curriculum_resource_id');
        });
    }
};
