<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportDefinition extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    protected $fillable = [
        'name',
        'description',
        'data_source',
        'selected_fields',
        'calculations',
        'filters',
        'sort_field',
        'sort_direction',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'selected_fields' => 'array',
            'calculations' => 'array',
            'filters' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
