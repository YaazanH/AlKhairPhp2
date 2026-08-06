<?php

namespace App\Models;

use App\Support\PhoneNumberFormatter;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunityContact extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'category',
        'organization',
        'phone',
        'secondary_phone',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function phone(): Attribute
    {
        return $this->phoneAttribute();
    }

    protected function secondaryPhone(): Attribute
    {
        return $this->phoneAttribute();
    }

    protected function phoneAttribute(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => PhoneNumberFormatter::format($value),
            set: fn (mixed $value): ?string => PhoneNumberFormatter::normalizeOrOriginal($value),
        );
    }
}
