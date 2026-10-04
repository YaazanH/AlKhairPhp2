<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attendance_records', function (Blueprint $table) {
            $table->timestamp('quick_attendance_added_at')->nullable();
            $table->json('quick_attendance_previous')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_attendance_records', function (Blueprint $table) {
            $table->dropColumn(['quick_attendance_added_at', 'quick_attendance_previous']);
        });
    }
};
