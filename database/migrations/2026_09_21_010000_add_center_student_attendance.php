<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attendance_days', function (Blueprint $table): void {
            $table->string('scope', 20)->default('groups')->after('course_id');
            $table->index(['scope', 'attendance_date'], 'student_attendance_days_scope_date_index');
        });

        Schema::table('student_attendance_records', function (Blueprint $table): void {
            $table->foreignId('group_attendance_day_id')->nullable()->change();
            $table->foreignId('enrollment_id')->nullable()->change();
            $table->foreignId('student_attendance_day_id')->nullable()->after('group_attendance_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->after('enrollment_id')->constrained()->cascadeOnDelete();
            $table->unique(['student_attendance_day_id', 'student_id'], 'student_attendance_day_student_unique');
        });
    }

    public function down(): void
    {
        $centerDayIds = DB::table('student_attendance_days')->where('scope', 'center')->pluck('id');
        DB::table('student_attendance_records')->whereIn('student_attendance_day_id', $centerDayIds)->delete();
        DB::table('student_attendance_days')->whereIn('id', $centerDayIds)->delete();

        Schema::table('student_attendance_records', function (Blueprint $table): void {
            $table->dropUnique('student_attendance_day_student_unique');
            $table->dropConstrainedForeignId('student_id');
            $table->dropConstrainedForeignId('student_attendance_day_id');
            $table->foreignId('enrollment_id')->nullable(false)->change();
            $table->foreignId('group_attendance_day_id')->nullable(false)->change();
        });

        Schema::table('student_attendance_days', function (Blueprint $table): void {
            $table->dropIndex('student_attendance_days_scope_date_index');
            $table->dropColumn('scope');
        });
    }
};
