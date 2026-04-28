<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuranPartialTestAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'quran_partial_test_part_id',
        'teacher_id',
        'tested_on',
        'mistake_count',
        'score',
        'status',
        'attempt_no',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'mistake_count' => 'integer',
            'score' => 'decimal:2',
            'tested_on' => 'date',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(QuranPartialTestPart::class, 'quran_partial_test_part_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
