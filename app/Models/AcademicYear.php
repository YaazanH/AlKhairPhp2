<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'starts_on',
        'ends_on',
        'is_current',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $academicYear): void {
            if (! $academicYear->is_current) {
                return;
            }

            static::query()
                ->whereKeyNot($academicYear->getKey())
                ->update(['is_current' => false]);
        });
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
