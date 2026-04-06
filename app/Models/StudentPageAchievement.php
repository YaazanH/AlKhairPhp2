<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentPageAchievement extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'page_no',
        'first_enrollment_id',
        'first_session_id',
        'first_recorded_on',
    ];

    protected function casts(): array
    {
        return [
            'page_no' => 'integer',
            'first_recorded_on' => 'date',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'first_enrollment_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(MemorizationSession::class, 'first_session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
