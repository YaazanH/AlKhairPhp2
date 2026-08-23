<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_attendance_days', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable()->after('attendance_date')->constrained()->nullOnDelete();
        });

        $defaultCourseId = DB::table('courses')->where('is_default', true)->value('id');
        if ($defaultCourseId) {
            DB::table('teacher_attendance_days')->whereNull('course_id')->update(['course_id' => $defaultCourseId]);
        }
    }

    public function down(): void
    {
        Schema::table('teacher_attendance_days', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('course_id');
        });
    }
};
