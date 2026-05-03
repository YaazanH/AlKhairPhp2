<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCurrencyExchange extends Model
{
    use HasFactory;

    protected $fillable = [
        'pair_uuid',
        'from_cash_box_id',
        'to_cash_box_id',
        'from_currency_id',
        'to_currency_id',
        'from_amount',
        'to_amount',
        'from_rate_to_base',
        'to_rate_to_base',
        'base_amount',
        'local_amount',
        'exchange_date',
        'entered_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'from_rate_to_base' => 'decimal:8',
            'to_rate_to_base' => 'decimal:8',
            'base_amount' => 'decimal:2',
            'local_amount' => 'decimal:2',
            'exchange_date' => 'date',
        ];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function fromCashBox(): BelongsTo
    {
        return $this->belongsTo(FinanceCashBox::class, 'from_cash_box_id');
    }

    public function toCashBox(): BelongsTo
    {
        return $this->belongsTo(FinanceCashBox::class, 'to_cash_box_id');
    }

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(FinanceCurrency::class, 'from_currency_id');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(FinanceCurrency::class, 'to_currency_id');
    }
}
