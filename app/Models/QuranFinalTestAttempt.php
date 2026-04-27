<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuranFinalTestAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'quran_final_test_id',
        'teacher_id',
        'tested_on',
        'score',
        'status',
        'attempt_no',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'score' => 'decimal:2',
            'tested_on' => 'date',
        ];
    }

    public function finalTest(): BelongsTo
    {
        return $this->belongsTo(QuranFinalTest::class, 'quran_final_test_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
