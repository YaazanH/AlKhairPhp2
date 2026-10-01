<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PlatformSubscriptionLedgerEntry extends LandlordModel
{
    public const TYPE_OFFLINE_PAYMENT = 'offline_payment';

    public const TYPE_RENEWAL = 'renewal';

    public const PAYMENT_METHOD_CASH = 'cash';

    public const PAYMENT_METHOD_BANK_TRANSFER = 'bank_transfer';

    public const PAYMENT_METHOD_CHEQUE = 'cheque';

    public const PAYMENT_METHOD_OTHER = 'other';

    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_CASH,
        self::PAYMENT_METHOD_BANK_TRANSFER,
        self::PAYMENT_METHOD_CHEQUE,
        self::PAYMENT_METHOD_OTHER,
    ];

    protected $fillable = [
        'uuid',
        'tenant_id',
        'tenant_subscription_id',
        'credit_syp',
        'debit_syp',
        'type',
        'receipt_number',
        'payment_method',
        'currency',
        'paid_at',
        'reference',
        'note',
        'metadata',
        'recorded_by_platform_administrator_id',
    ];

    protected function casts(): array
    {
        return [
            'credit_syp' => 'integer',
            'debit_syp' => 'integer',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Subscription ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Subscription ledger entries are immutable.'));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdministrator::class, 'recorded_by_platform_administrator_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PlatformSubscriptionAllocation::class, 'payment_entry_id');
    }

    public function chargeAllocations(): HasMany
    {
        return $this->hasMany(PlatformSubscriptionAllocation::class, 'charge_entry_id');
    }
}
