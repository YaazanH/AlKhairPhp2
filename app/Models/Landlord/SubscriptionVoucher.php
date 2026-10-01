<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionVoucher extends LandlordModel
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const APPLICATION_FIRST_PERIOD = 'first_period';

    public const APPLICATION_LIMITED_PERIODS = 'limited_periods';

    public const APPLICATION_RECURRING = 'recurring';

    public const APPLICATION_TYPES = [
        self::APPLICATION_FIRST_PERIOD,
        self::APPLICATION_LIMITED_PERIODS,
        self::APPLICATION_RECURRING,
    ];

    protected $fillable = [
        'code',
        'name',
        'tenant_id',
        'discount_type',
        'discount_value',
        'max_redemptions',
        'starts_at',
        'ends_at',
        'application_type',
        'max_uses_per_subscription',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses_per_subscription' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function redemptionRecords(): HasMany
    {
        return $this->hasMany(SubscriptionVoucherRedemption::class);
    }

    public function isUsableFor(Tenant $tenant, TenantSubscription $subscription): bool
    {
        if (! $this->is_active
            || ($this->starts_at !== null && $this->starts_at->isFuture())
            || ($this->ends_at !== null && $this->ends_at->isPast())
            || ($this->max_redemptions !== null && $this->redemptions >= $this->max_redemptions)
            || ($this->tenant_id !== null && $this->tenant_id !== $tenant->id)) {
            return false;
        }

        $subscriptionUses = $this->redemptionRecords()
            ->where('tenant_subscription_id', $subscription->id)
            ->count();

        return match ($this->application_type) {
            self::APPLICATION_RECURRING => true,
            self::APPLICATION_LIMITED_PERIODS => $subscriptionUses < (int) $this->max_uses_per_subscription,
            default => $subscriptionUses === 0,
        };
    }

    public function canBeAssignedTo(Tenant $tenant): bool
    {
        return $this->is_active && ($this->tenant_id === null || $this->tenant_id === $tenant->id);
    }

    public function discountFor(int $amount): int
    {
        return min(
            $amount,
            $this->discount_type === self::PERCENT
                ? (int) floor($amount * $this->discount_value / 100)
                : $this->discount_value,
        );
    }
}
