<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class PdfOptions
{
    public static function make(array $options = []): array
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        return array_replace([
            'mode' => 'utf-8',
            'default_font' => 'dubai',
            'fontDir' => array_merge((new ConfigVariables)->getDefaults()['fontDir'], [public_path('fonts/dubai')]),
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'dubai' => ['R' => 'Dubai-Regular.ttf', 'L' => 'Dubai-Light.ttf', 'M' => 'Dubai-Medium.ttf', 'B' => 'Dubai-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
            ],
            'tempDir' => $tempDir,
        ], $options);
    }
}
