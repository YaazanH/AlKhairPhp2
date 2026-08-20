<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_resources', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable()->after('published_on');
            $table->dropForeign(['subject_definition_id']);
            $table->foreignId('subject_definition_id')->nullable()->change();
            $table->foreign('subject_definition_id')->references('id')->on('curriculum_subject_definitions')->cascadeOnDelete();
        });

        Schema::create('curriculum_resource_curriculum', function (Blueprint $table): void {
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_resource_id')->constrained()->cascadeOnDelete();
            $table->primary(['curriculum_id', 'curriculum_resource_id'], 'curriculum_resource_curriculum_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_resource_curriculum');
        Schema::table('curriculum_resources', function (Blueprint $table): void {
            $table->dropColumn('pdf_path');
            $table->dropForeign(['subject_definition_id']);
            $table->foreignId('subject_definition_id')->nullable(false)->change();
            $table->foreign('subject_definition_id')->references('id')->on('curriculum_subject_definitions')->cascadeOnDelete();
        });
    }
};
