<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformSuggestionGroup extends LandlordModel
{
    protected $fillable = ['title', 'summary'];

    public function cases(): HasMany
    {
        return $this->hasMany(PlatformSupportCase::class, 'platform_suggestion_group_id');
    }
}
