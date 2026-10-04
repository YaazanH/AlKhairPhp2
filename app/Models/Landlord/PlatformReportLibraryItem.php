<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformReportLibraryItem extends LandlordModel
{
    protected $fillable = [
        'uuid',
        'kind',
        'system_key',
        'is_system',
        'name',
        'description',
        'draft_definition',
        'required_modules',
        'latest_version',
        'published_revision_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'draft_definition' => 'array',
            'required_modules' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PlatformReportLibraryRevision::class, 'published_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PlatformReportLibraryRevision::class, 'library_item_id');
    }
}
