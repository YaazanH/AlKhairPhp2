<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class StudentAttendanceDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_date',
        'status',
        'notes',
        'created_by',
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

    public function groupAttendanceDays(): HasMany
    {
        return $this->hasMany(GroupAttendanceDay::class);
    }
}
