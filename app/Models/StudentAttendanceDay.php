<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class StudentAttendanceDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_date',
        'course_id',
        'status',
        'course_finished_at',
        'course_finished_was_open',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'course_finished_at' => 'datetime',
            'course_finished_was_open' => 'boolean',
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

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function groupAttendanceDays(): HasMany
    {
        return $this->hasMany(GroupAttendanceDay::class);
    }
}
