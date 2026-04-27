<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quran_final_tests', function (Blueprint $table) {
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

        Schema::create('quran_final_test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quran_final_test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->date('tested_on');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('status', 20)->default('failed');
            $table->unsignedInteger('attempt_no')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['quran_final_test_id', 'attempt_no']);
            $table->index(['teacher_id', 'status']);
        });

        $finalTypeId = DB::table('quran_test_types')->where('code', 'final')->value('id');

        if (! $finalTypeId) {
            return;
        }

        $legacyFinalTests = DB::table('quran_tests')
            ->where('quran_test_type_id', $finalTypeId)
            ->orderBy('student_id')
            ->orderBy('juz_id')
            ->orderBy('tested_on')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $row): string => $row->student_id.'-'.$row->juz_id);

        $legacyFinalTests->each(function (Collection $attempts): void {
            $firstAttempt = $attempts->first();
            $passedAttempt = $attempts->firstWhere('status', 'passed');
            $createdAt = $firstAttempt->created_at ?? now();
            $updatedAt = $attempts->last()->updated_at ?? $createdAt;

            $finalTestId = DB::table('quran_final_tests')->insertGetId([
                'created_at' => $createdAt,
                'created_by' => null,
                'enrollment_id' => $firstAttempt->enrollment_id,
                'juz_id' => $firstAttempt->juz_id,
                'passed_on' => $passedAttempt?->tested_on,
                'status' => $passedAttempt ? 'passed' : 'in_progress',
                'student_id' => $firstAttempt->student_id,
                'updated_at' => $updatedAt,
            ]);

            foreach ($attempts->values() as $index => $attempt) {
                DB::table('quran_final_test_attempts')->insert([
                    'attempt_no' => $index + 1,
                    'created_at' => $attempt->created_at ?? $createdAt,
                    'notes' => $attempt->notes,
                    'quran_final_test_id' => $finalTestId,
                    'score' => $attempt->score,
                    'status' => $attempt->status,
                    'teacher_id' => $attempt->teacher_id,
                    'tested_on' => $attempt->tested_on,
                    'updated_at' => $attempt->updated_at ?? $createdAt,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quran_final_test_attempts');
        Schema::dropIfExists('quran_final_tests');
    }
};
