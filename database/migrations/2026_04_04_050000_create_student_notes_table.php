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
        Schema::create('student_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32);
            $table->string('visibility', 32);
            $table->text('body');
            $table->timestamp('noted_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'noted_at']);
            $table->index(['enrollment_id', 'noted_at']);
            $table->index(['source', 'noted_at']);
            $table->index(['visibility', 'noted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_notes');
    }
};
