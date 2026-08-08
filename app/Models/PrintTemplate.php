<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'width_mm',
        'height_mm',
        'background_image',
        'data_sources',
        'layout_json',
        'is_active',
        'is_student_card',
        'is_report_card',
    ];

    protected function casts(): array
    {
        return [
            'width_mm' => 'float',
            'height_mm' => 'float',
            'data_sources' => 'array',
            'layout_json' => 'array',
            'is_active' => 'boolean',
            'is_student_card' => 'boolean',
            'is_report_card' => 'boolean',
        ];
    }

    public function studentCardPrints(): HasMany
    {
        return $this->hasMany(StudentCardPrint::class, 'print_template_id');
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
