<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

    public const SCOPE_DATABASE = 'database';

    public const SCOPE_FILES = 'files';

    public const SCOPE_FULL = 'full';

    protected $fillable = [
        'uuid',
        'disk',
        'file_path',
        'filename',
        'trigger',
        'scope',
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
            && $this->artifactIsAvailable();
    }

    public function artifactIsAvailable(): bool
    {
        if (blank($this->file_path)) {
            return false;
        }

        try {
            $disk = Storage::disk($this->disk);
            if (! $disk->exists($this->file_path)) {
                return false;
            }

            $size = $disk->size($this->file_path);

            return $size > 0 && ($this->size_bytes === null || $size === $this->size_bytes);
        } catch (Throwable) {
            return false;
        }
    }
}
