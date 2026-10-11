<?php

namespace App\Models\Landlord;

class PlatformMetricSnapshot extends LandlordModel
{
    public const RESOLUTION_INTERVAL = 'interval';

    public const RESOLUTION_DAILY = 'daily';

    protected $fillable = [
        'resolution',
        'captured_at',
        'tenant_total',
        'tenant_active',
        'tenant_trial',
        'tenant_suspended',
        'tenant_failed',
        'tenant_storage_bytes',
        'server_used_bytes',
        'server_used_percent',
        'cpu_average',
        'ram_average',
        'incoming_total',
        'outgoing_total',
        'network_unit',
        'latest_backup_at',
        'failed_backups',
        'infrastructure_status',
        'vps_state',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'latest_backup_at' => 'datetime',
            'tenant_total' => 'integer',
            'tenant_active' => 'integer',
            'tenant_trial' => 'integer',
            'tenant_suspended' => 'integer',
            'tenant_failed' => 'integer',
            'tenant_storage_bytes' => 'integer',
            'server_used_bytes' => 'integer',
            'server_used_percent' => 'float',
            'cpu_average' => 'float',
            'ram_average' => 'float',
            'incoming_total' => 'float',
            'outgoing_total' => 'float',
            'failed_backups' => 'integer',
        ];
    }
}
