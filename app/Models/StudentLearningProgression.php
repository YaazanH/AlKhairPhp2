<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentLearningProgression extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'current_level_id', 'status', 'assigned_by', 'started_at',
        'level_started_at', 'last_evaluated_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'level_started_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function currentLevel(): BelongsTo
    {
        return $this->belongsTo(LearningProgressionLevel::class, 'current_level_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(StudentLearningProgressionHistory::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
