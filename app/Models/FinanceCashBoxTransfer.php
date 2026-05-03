<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCashBoxTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'pair_uuid',
        'from_cash_box_id',
        'to_cash_box_id',
        'currency_id',
        'amount',
        'transfer_date',
        'entered_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_date' => 'date',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(FinanceCurrency::class);
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
}
