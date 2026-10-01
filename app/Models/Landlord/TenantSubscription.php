<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSubscription extends LandlordModel
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_ANNUAL = 'annual';

    public const PERIOD_CUSTOM = 'custom';

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'subscription_voucher_id',
        'status',
        'period_type',
        'starts_at',
        'ends_at',
        'grace_ends_at',
        'cancelled_at',
        'cancelled_by_platform_administrator_id',
        'renews_automatically',
        'changed_by_platform_administrator_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'renews_automatically' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(SubscriptionVoucher::class, 'subscription_voucher_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdministrator::class, 'changed_by_platform_administrator_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdministrator::class, 'cancelled_by_platform_administrator_id');
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_CANCELLED], true)
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo(now()))
            && ($this->ends_at === null || $this->ends_at->isFuture() || ($this->grace_ends_at !== null && $this->grace_ends_at->isFuture()));
    }
}
