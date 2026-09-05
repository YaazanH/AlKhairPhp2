<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemBackup extends Model
{
    use HasFactory;

    public const STATUS_CREATING = 'creating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_PRE_RESTORE = 'pre_restore';

    public const TRIGGER_IMPORTED = 'imported';

    protected $fillable = [
        'uuid',
        'disk',
        'file_path',
        'filename',
        'trigger',
        'status',
        'includes_files',
        'encrypted',
        'size_bytes',
        'sha256',
        'manifest_summary',
        'created_by',
        'verified_at',
        'restored_at',
        'restore_count',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
            'includes_files' => 'boolean',
            'manifest_summary' => 'array',
            'restored_at' => 'datetime',
            'size_bytes' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->verified_at !== null
            && filled($this->file_path);
    }
}
