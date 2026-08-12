<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_subject_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('curriculum_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_definition_id')->constrained('curriculum_subject_definitions')->cascadeOnDelete();
            $table->string('book_name');
            $table->string('author')->nullable();
            $table->string('publisher')->nullable();
            $table->date('published_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('curricula', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['course_id', 'is_active']);
        });

        Schema::create('curriculum_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_definition_id')->constrained('curriculum_subject_definitions')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['curriculum_id', 'subject_definition_id']);
        });

        Schema::create('curriculum_subject_resources', function (Blueprint $table) {
            $table->foreignId('curriculum_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_resource_id')->constrained()->cascadeOnDelete();
            $table->primary(['curriculum_subject_id', 'curriculum_resource_id'], 'curriculum_subject_resource_primary');
        });

        Schema::create('curriculum_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_subject_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedTinyInteger('importance')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('curriculum_id')->nullable()->after('grade_level_id')->constrained('curricula')->nullOnDelete();
            $table->index(['course_id', 'curriculum_id']);
        });

        Schema::create('group_curriculum_lesson_progresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->date('taught_on');
            $table->timestamps();
            $table->unique(['group_id', 'curriculum_lesson_id'], 'group_curriculum_lesson_unique');
            $table->index(['group_id', 'taught_on']);
        });

        Schema::create('group_custom_curriculum_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_name');
            $table->string('name');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedTinyInteger('importance')->default(1);
            $table->string('status', 20);
            $table->date('taught_on');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['group_id', 'taught_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_custom_curriculum_lessons');
        Schema::dropIfExists('group_curriculum_lesson_progresses');
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'curriculum_id']);
            $table->dropConstrainedForeignId('curriculum_id');
        });
        Schema::dropIfExists('curriculum_lessons');
        Schema::dropIfExists('curriculum_subject_resources');
        Schema::dropIfExists('curriculum_subjects');
        Schema::dropIfExists('curricula');
        Schema::dropIfExists('curriculum_resources');
        Schema::dropIfExists('curriculum_subject_definitions');
    }
};
