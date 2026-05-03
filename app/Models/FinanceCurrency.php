<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCurrency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'rate_to_base',
        'is_active',
        'is_local',
        'is_base',
        'rate_updated_by',
        'rate_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_local' => 'boolean',
            'is_base' => 'boolean',
            'rate_to_base' => 'decimal:8',
            'rate_updated_at' => 'datetime',
        ];
    }

    public function rateUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rate_updated_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'currency_id');
    }
}
