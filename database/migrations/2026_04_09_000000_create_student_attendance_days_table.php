<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_attendance_days', function (Blueprint $table) {
            $table->id();
            $table->date('attendance_date')->unique();
            $table->string('status')->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('group_attendance_days', function (Blueprint $table) {
            $table->foreignId('student_attendance_day_id')
                ->nullable()
                ->after('group_id')
                ->constrained('student_attendance_days')
                ->nullOnDelete();
        });

        $days = DB::table('group_attendance_days')
            ->select('attendance_date', DB::raw('MIN(id) as first_id'))
            ->groupBy('attendance_date')
            ->orderBy('attendance_date')
            ->get();

        foreach ($days as $day) {
            $source = DB::table('group_attendance_days')->where('id', $day->first_id)->first();

            $studentAttendanceDayId = DB::table('student_attendance_days')->insertGetId([
                'attendance_date' => $day->attendance_date,
                'status' => $source?->status ?? 'open',
                'notes' => $source?->notes,
                'created_by' => $source?->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('group_attendance_days')
                ->where('attendance_date', $day->attendance_date)
                ->update([
                    'student_attendance_day_id' => $studentAttendanceDayId,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('group_attendance_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_attendance_day_id');
        });

        Schema::dropIfExists('student_attendance_days');
    }
};
