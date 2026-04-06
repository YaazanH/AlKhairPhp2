<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuranTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'student_id',
        'teacher_id',
        'juz_id',
        'quran_test_type_id',
        'tested_on',
        'score',
        'status',
        'attempt_no',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tested_on' => 'date',
            'score' => 'decimal:2',
            'attempt_no' => 'integer',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function juz(): BelongsTo
    {
        return $this->belongsTo(QuranJuz::class, 'juz_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(QuranTestType::class, 'quran_test_type_id');
    }
}
