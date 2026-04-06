<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'is_scored',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_scored' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function scoreBands(): HasMany
    {
        return $this->hasMany(AssessmentScoreBand::class);
    }
}
