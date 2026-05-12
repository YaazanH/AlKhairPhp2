<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_PULL = 'pull';
    public const TYPE_EXPENSE = 'expense';
    public const TYPE_REVENUE = 'revenue';
    public const TYPE_RETURN = 'return';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'request_no',
        'type',
        'status',
        'finance_pull_request_kind_id',
        'requested_currency_id',
        'requested_amount',
        'requested_count',
        'accepted_currency_id',
        'accepted_amount',
        'accepted_count',
        'final_count',
        'remaining_amount',
        'cash_box_id',
        'activity_id',
        'teacher_id',
        'finance_category_id',
        'requested_by',
        'reviewed_by',
        'posted_transaction_id',
        'invoice_id',
        'return_transaction_id',
        'closing_transaction_id',
        'counterparty_name',
        'requested_reason',
        'review_notes',
        'terms_snapshot',
        'terms_accepted_at',
        'terms_accepted_by',
        'accepted_at',
        'declined_at',
        'settled_at',
        'settled_by',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'accepted_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'terms_accepted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public static function maskDisplayName(?string $name): string
    {
        $parts = collect(preg_split('/\s+/u', trim((string) $name)) ?: [])
            ->filter()
            ->map(function (string $part): string {
                $length = mb_strlen($part);

                if ($length <= 0) {
                    return '';
                }

                return mb_substr($part, 0, 1).str_repeat('*', max($length - 1, 1));
            })
            ->filter()
            ->values();

        return $parts->isEmpty() ? '' : $parts->implode(' ');
    }

    public function maskedCounterpartyName(): string
    {
        return static::maskDisplayName($this->counterparty_name);
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

    public function closingTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class, 'closing_transaction_id');
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

    public function pullRequestKind(): BelongsTo
    {
        return $this->belongsTo(FinancePullRequestKind::class, 'finance_pull_request_kind_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function returnTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class, 'return_transaction_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function termsAcceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terms_accepted_by');
    }
}
