<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LearningProgressionLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sort_order',
        'attendance_threshold',
        'final_assessment_id',
        'passing_score',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'attendance_threshold' => 'decimal:2',
            'passing_score' => 'decimal:2',
        ];
    }

    public function finalAssessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'final_assessment_id');
    }

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(CurriculumLesson::class, 'learning_progression_level_lesson');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'learning_progression_level_group');
    }
}
