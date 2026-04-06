<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_attendance_day_id',
        'teacher_id',
        'attendance_status_id',
        'notes',
    ];

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(TeacherAttendanceDay::class, 'teacher_attendance_day_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatus::class, 'attendance_status_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
