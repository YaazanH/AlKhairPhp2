<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_attendance_day_id',
        'enrollment_id',
        'attendance_status_id',
        'notes',
    ];

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(GroupAttendanceDay::class, 'group_attendance_day_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatus::class, 'attendance_status_id');
    }
}
