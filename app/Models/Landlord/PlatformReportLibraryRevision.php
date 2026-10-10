<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformReportLibraryRevision extends LandlordModel
{
    protected $fillable = [
        'library_item_id',
        'version',
        'kind',
        'name',
        'description',
        'definition',
        'required_modules',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'definition' => 'array',
            'required_modules' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlatformReportLibraryItem::class, 'library_item_id');
    }
}
