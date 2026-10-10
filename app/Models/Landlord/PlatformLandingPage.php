<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformLandingPage extends LandlordModel
{
    protected $fillable = ['key', 'draft_content', 'published_revision_id', 'published_at'];

    protected function casts(): array
    {
        return ['draft_content' => 'array', 'published_at' => 'datetime'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PlatformLandingPageRevision::class);
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PlatformLandingPageRevision::class, 'published_revision_id');
    }
}
