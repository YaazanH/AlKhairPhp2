<?php

namespace App\Models\Landlord;

use DomainException;
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

    public const LEARNING_PATH_QURAN = 'quran';

    public const LEARNING_PATH_LESSON_LEVEL = 'lesson_level';

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'database_name',
        'status',
        'suspended_at',
        'timezone',
        'locale',
        'learning_path_type',
        'logo_path',
        'storage_limit_bytes',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            $tenant->learning_path_type ??= self::LEARNING_PATH_QURAN;

            if (! in_array($tenant->learning_path_type, self::learningPathTypes(), true)) {
                throw new DomainException('Unsupported tenant learning path type.');
            }
        });

        static::updating(function (self $tenant): void {
            if ($tenant->isDirty('learning_path_type')) {
                throw new DomainException('The tenant learning path type is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'storage_limit_bytes' => 'integer',
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

    public static function learningPathTypes(): array
    {
        return [self::LEARNING_PATH_QURAN, self::LEARNING_PATH_LESSON_LEVEL];
    }

    public function learningPathLabel(): string
    {
        return match ($this->learning_path_type) {
            self::LEARNING_PATH_LESSON_LEVEL => 'Lessons and levels',
            default => 'Quran',
        };
    }
}
