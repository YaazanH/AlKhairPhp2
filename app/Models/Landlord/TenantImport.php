<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantImport extends LandlordModel
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'summary' => 'array', 'selection' => 'array', 'report' => 'array',
            'expected_bytes' => 'integer', 'uploaded_bytes' => 'integer',
            'next_chunk' => 'integer', 'progress' => 'integer',
            'database_owned' => 'boolean', 'database_allocation_started' => 'boolean', 'public_storage_owned' => 'boolean',
            'private_storage_owned' => 'boolean', 'heartbeat_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['securing', 'validating', 'queued', 'importing'], true);
    }

    public function isSettingsBackup(): bool
    {
        return str_ends_with($this->filename, '.alkhair-backup');
    }
}
