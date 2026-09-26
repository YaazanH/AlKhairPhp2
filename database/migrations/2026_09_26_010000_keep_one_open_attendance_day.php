<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_day_locks')) {
            Schema::create('attendance_day_locks', function (Blueprint $table): void {
                $table->string('attendance_table')->primary();
            });
        }

        foreach (['student_attendance_days', 'teacher_attendance_days'] as $table) {
            DB::table('attendance_day_locks')->insertOrIgnore(['attendance_table' => $table]);

            $latestOpenId = DB::table($table)->where('status', 'open')
                ->orderByDesc('attendance_date')->orderByDesc('id')->value('id');
            $olderDays = DB::table($table)->where('status', 'open')->where('id', '!=', $latestOpenId);

            if ($table === 'student_attendance_days') {
                DB::table('group_attendance_days')->whereIn('student_attendance_day_id', (clone $olderDays)->select('id'))
                    ->update(['status' => 'closed']);
            }

            $olderDays->update(['status' => 'closed']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_day_locks');
        // Preserve the closed days and all of their attendance records.
    }
};
