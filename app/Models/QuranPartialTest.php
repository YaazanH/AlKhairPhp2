<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuranPartialTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id',
        'student_id',
        'juz_id',
        'status',
        'passed_on',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'passed_on' => 'date',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function juz(): BelongsTo
    {
        return $this->belongsTo(QuranJuz::class, 'juz_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(QuranPartialTestPart::class)->orderBy('part_number');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
