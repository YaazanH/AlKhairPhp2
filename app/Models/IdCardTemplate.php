<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdCardTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'width_mm',
        'height_mm',
        'background_image',
        'layout_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'width_mm' => 'float',
            'height_mm' => 'float',
            'layout_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getBackgroundImageUrlAttribute(): ?string
    {
        if (blank($this->background_image)) {
            return null;
        }

        if (str_starts_with($this->background_image, '/')) {
            return $this->background_image;
        }

        return '/storage/'.ltrim($this->background_image, '/');
    }
}
