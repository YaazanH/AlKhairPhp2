<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('curriculum_lessons', 'curriculum_resource_id')) {
            Schema::table('curriculum_lessons', function (Blueprint $table): void {
                $table->foreignId('curriculum_resource_id')->nullable()->after('curriculum_subject_id')->constrained('curriculum_resources')->nullOnDelete();
                $table->index(['curriculum_subject_id', 'curriculum_resource_id'], 'curriculum_lesson_subject_resource_index');
            });
        }

        if (! Schema::hasTable('curriculum_lesson_topics')) {
            Schema::create('curriculum_lesson_topics', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('curriculum_lesson_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['curriculum_lesson_id', 'sort_order']);
            });
        }

        // MySQL applies CREATE TABLE and its ALTER TABLE constraints separately. If
        // the original migration failed on a constraint name, this empty shell may
        // remain even though Laravel did not record the migration as completed.
        Schema::dropIfExists('group_curriculum_topic_progresses');

        Schema::create('group_curriculum_topic_progresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id');
            $table->foreignId('curriculum_lesson_topic_id');
            $table->foreignId('teacher_id')->nullable();
            $table->date('taught_on');
            $table->timestamps();
            $table->unique(['group_id', 'curriculum_lesson_topic_id'], 'group_curriculum_topic_unique');
            $table->foreign('group_id', 'gctp_group_fk')->references('id')->on('groups')->cascadeOnDelete();
            $table->foreign('curriculum_lesson_topic_id', 'gctp_topic_fk')->references('id')->on('curriculum_lesson_topics')->cascadeOnDelete();
            $table->foreign('teacher_id', 'gctp_teacher_fk')->references('id')->on('teachers')->nullOnDelete();
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
