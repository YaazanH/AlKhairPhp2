<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceTransaction extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'transaction_no',
        'special_transaction_no',
        'cash_box_id',
        'currency_id',
        'finance_category_id',
        'activity_id',
        'teacher_id',
        'finance_request_id',
        'source_type',
        'source_id',
        'type',
        'direction',
        'amount',
        'signed_amount',
        'rate_to_base',
        'base_amount',
        'local_amount',
        'transaction_date',
        'description',
        'entered_by',
        'pair_uuid',
        'metadata',
        'status',
        'deleted_by',
        'deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'signed_amount' => 'decimal:2',
            'rate_to_base' => 'decimal:12',
            'base_amount' => 'decimal:2',
            'local_amount' => 'decimal:2',
            'transaction_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(FinanceCashBox::class, 'cash_box_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(FinanceCurrency::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function financeRequest(): BelongsTo
    {
        return $this->belongsTo(FinanceRequest::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
