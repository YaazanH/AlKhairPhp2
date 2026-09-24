<?php

namespace App\Services\PrintTemplates;

use App\Models\PrintTemplate;

class StandardStudentCardTemplate
{
    public function get(): PrintTemplate
    {
        return PrintTemplate::query()->firstOrCreate(
            ['is_system' => true, 'is_student_card' => true],
            [
                'name' => 'Standard Student Card',
                'width_mm' => 85.6,
                'height_mm' => 53.98,
                'paper_size' => 'a4',
                'orientation' => 'portrait',
                'margin_top_mm' => 10,
                'margin_right_mm' => 10,
                'margin_bottom_mm' => 10,
                'margin_left_mm' => 10,
                'gap_x_mm' => 6,
                'gap_y_mm' => 6,
                'rounded_corners' => true,
                'data_sources' => [['key' => 'student', 'entity' => 'student', 'mode' => 'multiple']],
                'layout_json' => $this->layout(),
                'is_active' => true,
                'is_report_card' => false,
            ],
        );
    }

    private function layout(): array
    {
        return [
            ['type' => 'custom_text', 'content' => '{{ student.full_name }}', 'x' => 7, 'y' => 8, 'width' => 71, 'height' => 9, 'z_index' => 1, 'styling' => ['font_size' => 5, 'font_weight' => '700', 'color' => '#102316', 'text_align' => 'center']],
            ['type' => 'dynamic_image', 'source' => 'student', 'field' => 'photo', 'x' => 30.8, 'y' => 19, 'width' => 24, 'height' => 24, 'z_index' => 2, 'styling' => []],
            ['type' => 'barcode', 'source' => 'student', 'field' => 'student_number', 'x' => 7, 'y' => 44, 'width' => 71, 'height' => 6, 'z_index' => 3, 'styling' => ['barcode_format' => 'code128', 'show_text' => true, 'color' => '#102316']],
        ];
    }
}
