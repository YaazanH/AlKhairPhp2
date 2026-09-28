<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAuditEvent extends LandlordModel
{
    protected $fillable = [
        'uuid',
        'platform_administrator_id',
        'tenant_id',
        'event',
        'properties',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function platformAdministrator(): BelongsTo
    {
        return $this->belongsTo(PlatformAdministrator::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
