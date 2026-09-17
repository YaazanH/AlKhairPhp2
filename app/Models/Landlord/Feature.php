<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends LandlordModel
{
    public const CORE = 'core';
    public const FINANCE = 'finance';
    public const CUSTOM_PRINTING = 'custom_printing';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_core',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_feature')->withTimestamps();
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(TenantFeatureOverride::class);
    }
}
