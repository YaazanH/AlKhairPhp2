<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuranPartialTestPart extends Model
{
    use HasFactory;

    protected $fillable = [
        'quran_partial_test_id',
        'part_number',
        'status',
        'passed_on',
    ];

    protected function casts(): array
    {
        return [
            'part_number' => 'integer',
            'passed_on' => 'date',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuranPartialTestAttempt::class)->orderBy('attempt_no');
    }

    public function partialTest(): BelongsTo
    {
        return $this->belongsTo(QuranPartialTest::class, 'quran_partial_test_id');
    }
}
