<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class GroupAttendanceDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'student_attendance_day_id',
        'attendance_date',
        'status',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
        ];
    }

    protected function attendanceDate(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => filled($value) ? Carbon::parse($value)->toDateString() : null,
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function studentAttendanceDay(): BelongsTo
    {
        return $this->belongsTo(StudentAttendanceDay::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(StudentAttendanceRecord::class);
    }
}
