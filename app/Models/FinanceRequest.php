<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceRequest extends Model
{
    use HasFactory;

    public const TYPE_PULL = 'pull';
    public const TYPE_EXPENSE = 'expense';
    public const TYPE_REVENUE = 'revenue';
    public const TYPE_RETURN = 'return';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'request_no',
        'type',
        'status',
        'requested_currency_id',
        'requested_amount',
        'accepted_currency_id',
        'accepted_amount',
        'cash_box_id',
        'activity_id',
        'teacher_id',
        'finance_category_id',
        'requested_by',
        'reviewed_by',
        'posted_transaction_id',
        'requested_reason',
        'review_notes',
        'terms_snapshot',
        'terms_accepted_at',
        'terms_accepted_by',
        'accepted_at',
        'declined_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'accepted_amount' => 'decimal:2',
            'terms_accepted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FinanceRequestAttachment::class);
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(FinanceCashBox::class, 'cash_box_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'finance_category_id');
    }

    public function requestedCurrency(): BelongsTo
    {
        return $this->belongsTo(FinanceCurrency::class, 'requested_currency_id');
    }

    public function acceptedCurrency(): BelongsTo
    {
        return $this->belongsTo(FinanceCurrency::class, 'accepted_currency_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function postedTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class, 'posted_transaction_id');
    }

    public function termsAcceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terms_accepted_by');
    }
}
