<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBackup extends LandlordModel
{
    public const STATUS_CREATING = 'creating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_PRE_RESTORE = 'pre_restore';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'source_backup_uuid',
        'disk',
        'file_path',
        'filename',
        'trigger',
        'status',
        'size_bytes',
        'sha256',
        'manifest_summary',
        'verified_at',
        'restored_at',
        'restore_count',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'manifest_summary' => 'array',
            'verified_at' => 'datetime',
            'restored_at' => 'datetime',
            'restore_count' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->verified_at !== null
            && filled($this->file_path);
    }
}
