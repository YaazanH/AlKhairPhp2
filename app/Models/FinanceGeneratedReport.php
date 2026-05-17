<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class FinanceGeneratedReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_type',
        'filters',
        'report_data',
        'generated_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'report_data' => 'array',
        ];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public static function storageIsReady(): bool
    {
        return Schema::hasTable((new static)->getTable());
    }
}
