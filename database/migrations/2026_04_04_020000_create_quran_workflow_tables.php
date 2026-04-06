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
        Schema::create('teacher_attendance_days', function (Blueprint $table) {
            $table->id();
            $table->date('attendance_date')->unique();
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_attendance_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->foreignId('attendance_status_id')->nullable()->constrained('attendance_statuses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['teacher_attendance_day_id', 'teacher_id']);
        });

        Schema::create('group_attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'attendance_date']);
        });

        Schema::create('student_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_attendance_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_status_id')->nullable()->constrained('attendance_statuses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['group_attendance_day_id', 'enrollment_id']);
        });

        Schema::create('memorization_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->date('recorded_on');
            $table->string('entry_type', 20)->default('new');
            $table->unsignedSmallInteger('from_page')->nullable();
            $table->unsignedSmallInteger('to_page')->nullable();
            $table->unsignedInteger('pages_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'recorded_on']);
        });

        Schema::create('memorization_session_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memorization_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_no');
            $table->timestamps();

            $table->unique(['memorization_session_id', 'page_no']);
        });

        Schema::create('student_page_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_no');
            $table->foreignId('first_enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('first_session_id')->constrained('memorization_sessions')->cascadeOnDelete();
            $table->date('first_recorded_on');
            $table->timestamps();

            $table->unique(['student_id', 'page_no']);
        });

        Schema::create('quran_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->foreignId('juz_id')->constrained('quran_juzs')->restrictOnDelete();
            $table->foreignId('quran_test_type_id')->constrained()->restrictOnDelete();
            $table->date('tested_on');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('status', 20)->default('failed');
            $table->unsignedInteger('attempt_no')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'juz_id', 'quran_test_type_id', 'status']);
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('point_policies')->nullOnDelete();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->integer('points');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entered_at');
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'voided_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('quran_tests');
        Schema::dropIfExists('student_page_achievements');
        Schema::dropIfExists('memorization_session_pages');
        Schema::dropIfExists('memorization_sessions');
        Schema::dropIfExists('student_attendance_records');
        Schema::dropIfExists('group_attendance_days');
        Schema::dropIfExists('teacher_attendance_records');
        Schema::dropIfExists('teacher_attendance_days');
    }
};
