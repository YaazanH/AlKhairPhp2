<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attendance_days', function (Blueprint $table): void {
            $table->timestamp('course_finished_at')->nullable()->after('status')->index();
            $table->boolean('course_finished_was_open')->default(false)->after('course_finished_at');
        });

        Schema::table('teacher_attendance_records', function (Blueprint $table): void {
            $table->foreignId('archived_course_id')->nullable()->after('teacher_id')->constrained('courses')->nullOnDelete();
            $table->timestamp('course_finished_at')->nullable()->after('archived_course_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_attendance_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_course_id');
            $table->dropColumn('course_finished_at');
        });

        Schema::table('student_attendance_days', function (Blueprint $table): void {
            $table->dropColumn(['course_finished_at', 'course_finished_was_open']);
        });
    }
};
