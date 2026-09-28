<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantFeatureOverride extends LandlordModel
{
    protected $fillable = [
        'tenant_id',
        'feature_id',
        'is_enabled',
        'changed_by_platform_administrator_id',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdministrator::class, 'changed_by_platform_administrator_id');
    }
}
