<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_attendance_days', function (Blueprint $table): void {
            $table->dropUnique(['attendance_date']);
            $table->unique(['attendance_date', 'course_id'], 'teacher_attendance_date_course_unique');
        });
        Schema::create('teacher_attendance_inclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_attendance_inclusions');
        Schema::table('teacher_attendance_days', function (Blueprint $table): void {
            $table->dropUnique('teacher_attendance_date_course_unique');
            $table->unique('attendance_date');
        });
    }
};
