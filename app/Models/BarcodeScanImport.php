<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BarcodeScanImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'attendance_date',
        'raw_dump',
        'status',
        'processed_count',
        'error_count',
        'created_by',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'error_count' => 'integer',
            'processed_at' => 'datetime',
            'processed_count' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BarcodeScanEvent::class);
    }
}
