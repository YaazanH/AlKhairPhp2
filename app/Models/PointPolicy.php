<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'point_type_id',
        'name',
        'source_type',
        'trigger_key',
        'grade_level_id',
        'from_value',
        'to_value',
        'points',
        'priority',
        'period_type',
        'active_from',
        'active_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'from_value' => 'decimal:2',
            'to_value' => 'decimal:2',
            'points' => 'integer',
            'priority' => 'integer',
            'active_from' => 'date',
            'active_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function pointType(): BelongsTo
    {
        return $this->belongsTo(PointType::class);
    }
}
