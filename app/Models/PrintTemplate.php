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
        'paper_size',
        'orientation',
        'margin_top_mm',
        'margin_right_mm',
        'margin_bottom_mm',
        'margin_left_mm',
        'gap_x_mm',
        'gap_y_mm',
        'rounded_corners',
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
            'margin_top_mm' => 'float',
            'margin_right_mm' => 'float',
            'margin_bottom_mm' => 'float',
            'margin_left_mm' => 'float',
            'gap_x_mm' => 'float',
            'gap_y_mm' => 'float',
            'rounded_corners' => 'boolean',
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

    public static function paperSizes(): array
    {
        return [
            'a4' => [210.0, 297.0], 'a5' => [148.0, 210.0], 'a6' => [105.0, 148.0],
            'b5' => [176.0, 250.0], 'envelope_dl' => [110.0, 220.0], 'envelope_c6' => [114.0, 162.0],
        ];
    }

    public function printLayoutConfig(): array
    {
        [$width, $height] = static::paperSizes()[$this->paper_size] ?? static::paperSizes()['a4'];
        if ($this->orientation === 'landscape') [$width, $height] = [$height, $width];

        return [
            'page_width_mm' => $width, 'page_height_mm' => $height,
            'margin_top_mm' => $this->margin_top_mm, 'margin_right_mm' => $this->margin_right_mm,
            'margin_bottom_mm' => $this->margin_bottom_mm, 'margin_left_mm' => $this->margin_left_mm,
            'gap_x_mm' => $this->gap_x_mm, 'gap_y_mm' => $this->gap_y_mm,
        ];
    }
}
