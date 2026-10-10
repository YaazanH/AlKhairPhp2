<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSupportAttachment extends Model
{
    protected $fillable = [
        'uploaded_by_user_id',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(TenantSupportRequest::class, 'tenant_support_request_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
