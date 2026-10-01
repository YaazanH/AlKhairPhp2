<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends LandlordModel
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_PROVISIONING_FAILED = 'provisioning_failed';

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'database_name',
        'status',
        'suspended_at',
        'timezone',
        'locale',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    public function featureOverrides(): HasMany
    {
        return $this->hasMany(TenantFeatureOverride::class);
    }

    public function provisioningAttempts(): HasMany
    {
        return $this->hasMany(TenantProvisioningAttempt::class);
    }

    public function billingEntries(): HasMany
    {
        return $this->hasMany(PlatformSubscriptionLedgerEntry::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(TenantBackup::class);
    }

    public function isOperational(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true);
    }
}
