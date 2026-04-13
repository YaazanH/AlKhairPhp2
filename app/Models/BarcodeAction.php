<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarcodeAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type',
        'attendance_status_id',
        'point_type_id',
        'points',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function attendanceStatus(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatus::class);
    }

    public function pointType(): BelongsTo
    {
        return $this->belongsTo(PointType::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isAttendance(): bool
    {
        return $this->type === 'attendance';
    }

    public function isPoints(): bool
    {
        return $this->type === 'points';
    }
}
