<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformLandingPageRevision extends LandlordModel
{
    protected $fillable = [
        'platform_landing_page_id', 'revision_number', 'content',
        'published_by_platform_administrator_id', 'published_at',
    ];

    protected function casts(): array
    {
        return ['content' => 'array', 'published_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PlatformLandingPage::class, 'platform_landing_page_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(PlatformAdministrator::class, 'published_by_platform_administrator_id');
    }
}
