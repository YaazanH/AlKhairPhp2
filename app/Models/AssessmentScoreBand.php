<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentScoreBand extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_type_id',
        'name',
        'from_mark',
        'to_mark',
        'point_type_id',
        'points',
        'is_fail',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'from_mark' => 'decimal:2',
            'to_mark' => 'decimal:2',
            'points' => 'integer',
            'is_fail' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    public function pointType(): BelongsTo
    {
        return $this->belongsTo(PointType::class);
    }
}
