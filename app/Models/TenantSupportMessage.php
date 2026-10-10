<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSupportMessage extends Model
{
    protected $fillable = [
        'sender_user_id',
        'is_tenant_administrator',
        'message',
    ];

    protected function casts(): array
    {
        return ['is_tenant_administrator' => 'boolean'];
    }

    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(TenantSupportRequest::class, 'tenant_support_request_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
