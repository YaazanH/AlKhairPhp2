<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PlatformRole extends LandlordModel
{
    protected $fillable = ['name', 'description', 'is_owner'];

    protected function casts(): array
    {
        return ['is_owner' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PlatformPermission::class, 'platform_permission_role');
    }

    public function administrators(): BelongsToMany
    {
        return $this->belongsToMany(PlatformAdministrator::class, 'platform_administrator_role');
    }
}
