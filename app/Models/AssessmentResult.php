<?php

namespace App\Models;

use App\Services\LessonLevelProgressionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentResult extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn (self $result) => app(LessonLevelProgressionService::class)->assessmentResultSaved($result));
        static::deleted(fn (self $result) => app(LessonLevelProgressionService::class)->evaluateStudent((int) $result->student_id));
    }

    protected $fillable = [
        'assessment_id',
        'enrollment_id',
        'student_id',
        'teacher_id',
        'score',
        'status',
        'attempt_no',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'attempt_no' => 'integer',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
