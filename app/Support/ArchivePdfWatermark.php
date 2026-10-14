<?php

namespace App\Support;

use App\Models\Course;
use Mpdf\Mpdf;
use Mpdf\WatermarkImage;

final class ArchivePdfWatermark
{
    public static function apply(Mpdf $mpdf, ?Course $archiveCourse): void
    {
        if (! $archiveCourse) {
            return;
        }

        $mpdf->SetWatermarkImage(new WatermarkImage(
            path: resource_path('images/archive-watermark.png'),
            size: [200, 168.85],
            position: WatermarkImage::POSITION_CENTER_PAGE,
            alpha: 0.25,
            behindContent: true,
        ));
        $mpdf->showWatermarkImage = true;
    }
}
