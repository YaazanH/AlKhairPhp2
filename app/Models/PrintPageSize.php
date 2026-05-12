<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintPageSize extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'page_width_mm',
        'page_height_mm',
        'margin_top_mm',
        'margin_right_mm',
        'margin_bottom_mm',
        'margin_left_mm',
        'gap_x_mm',
        'gap_y_mm',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'gap_x_mm' => 'decimal:2',
            'gap_y_mm' => 'decimal:2',
            'is_default' => 'boolean',
            'margin_bottom_mm' => 'decimal:2',
            'margin_left_mm' => 'decimal:2',
            'margin_right_mm' => 'decimal:2',
            'margin_top_mm' => 'decimal:2',
            'page_height_mm' => 'decimal:2',
            'page_width_mm' => 'decimal:2',
        ];
    }

    public function layoutConfig(): array
    {
        return [
            'page_width_mm' => (float) $this->page_width_mm,
            'page_height_mm' => (float) $this->page_height_mm,
            'margin_top_mm' => (float) $this->margin_top_mm,
            'margin_right_mm' => (float) $this->margin_right_mm,
            'margin_bottom_mm' => (float) $this->margin_bottom_mm,
            'margin_left_mm' => (float) $this->margin_left_mm,
            'gap_x_mm' => (float) $this->gap_x_mm,
            'gap_y_mm' => (float) $this->gap_y_mm,
        ];
    }
}
