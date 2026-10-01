<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends LandlordModel
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'price_syp',
        'billing_period_days',
        'storage_limit_bytes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_syp' => 'integer',
            'billing_period_days' => 'integer',
            'storage_limit_bytes' => 'integer',
        ];
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_feature')->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }
}