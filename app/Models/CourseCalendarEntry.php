<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseCalendarEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'date',
        'name',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
