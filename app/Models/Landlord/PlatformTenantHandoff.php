<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformTenantHandoff extends LandlordModel
{
    public const LEVEL_READ = 'read';

    public const LEVEL_EDIT = 'edit';

    public const LEVEL_DELETE = 'delete';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'platform_administrator_id',
        'token_hash',
        'access_level',
        'expires_at',
        'consumed_at',
        'created_ip_address',
        'consumed_ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function platformAdministrator(): BelongsTo
    {
        return $this->belongsTo(PlatformAdministrator::class);
    }
}
