<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'category',
        'default_points',
        'allow_manual_entry',
        'allow_negative',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_points' => 'integer',
            'allow_manual_entry' => 'boolean',
            'allow_negative' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(PointPolicy::class);
    }

    public function assessmentScoreBands(): HasMany
    {
        return $this->hasMany(AssessmentScoreBand::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }
}
