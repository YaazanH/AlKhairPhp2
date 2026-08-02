<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAttendanceExclusion extends Model
{
    protected $fillable = [
        'teacher_id',
        'excluded_by',
        'excluded_at',
    ];

    protected function casts(): array
    {
        return [
            'excluded_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }
}
