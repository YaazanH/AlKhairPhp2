<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLearningProgressionHistory extends Model
{
    use HasFactory;

    protected $table = 'student_learning_progression_history';

    protected $fillable = [
        'student_learning_progression_id', 'student_id', 'from_level_id', 'to_level_id',
        'event', 'performed_by', 'reason', 'evidence', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'datetime'];
    }

    public function progression(): BelongsTo
    {
        return $this->belongsTo(StudentLearningProgression::class, 'student_learning_progression_id');
    }

    public function fromLevel(): BelongsTo
    {
        return $this->belongsTo(LearningProgressionLevel::class, 'from_level_id');
    }

    public function toLevel(): BelongsTo
    {
        return $this->belongsTo(LearningProgressionLevel::class, 'to_level_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
