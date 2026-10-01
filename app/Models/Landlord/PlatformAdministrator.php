<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PlatformAdministrator extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $connection = 'landlord';

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'is_active',
        'must_change_password',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $administrator): void {
            if (static::query()->count() !== 1) {
                return;
            }

            $owner = PlatformRole::query()->where('is_owner', true)->first();
            if ($owner) {
                $administrator->roles()->syncWithoutDetaching([$owner->id]);
            }
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRole::class, 'platform_administrator_role');
    }

    public function hasPlatformPermission(string $permission): bool
    {
        return $this->roles()
            ->where(fn ($query) => $query->where('is_owner', true)->orWhereHas('permissions', fn ($permissions) => $permissions->where('code', $permission)))
            ->exists();
    }

    public function isPlatformOwner(): bool
    {
        return $this->roles()->where('is_owner', true)->exists();
    }
}
