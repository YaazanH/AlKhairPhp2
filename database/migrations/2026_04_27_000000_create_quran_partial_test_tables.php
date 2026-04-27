<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quran_partial_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('juz_id')->constrained('quran_juzs')->restrictOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->date('passed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'juz_id']);
            $table->index(['enrollment_id', 'status']);
        });

        Schema::create('quran_partial_test_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quran_partial_test_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('part_number');
            $table->string('status', 20)->default('pending');
            $table->date('passed_on')->nullable();
            $table->timestamps();

            $table->unique(['quran_partial_test_id', 'part_number'],'qu_pr_te_id_part');
        });

        Schema::create('quran_partial_test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quran_partial_test_part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->date('tested_on');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('status', 20)->default('failed');
            $table->unsignedInteger('attempt_no')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['quran_partial_test_part_id', 'attempt_no'],'qu_pr_te_id_ate');
            $table->index(['teacher_id', 'status']);
        });

        DB::table('quran_test_types')
            ->where('code', 'partial')
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('quran_test_types')
            ->where('code', 'partial')
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);

        Schema::dropIfExists('quran_partial_test_attempts');
        Schema::dropIfExists('quran_partial_test_parts');
        Schema::dropIfExists('quran_partial_tests');
    }
};
