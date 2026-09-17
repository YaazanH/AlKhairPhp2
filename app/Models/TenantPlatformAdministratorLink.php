<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TenantPlatformAdministratorLink extends Model
{
    protected $fillable = [
        'user_id',
        'platform_administrator_uuid',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
