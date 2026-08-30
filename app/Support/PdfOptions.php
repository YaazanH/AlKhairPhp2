<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class PdfOptions
{
    public static function make(array $options = []): array
    {
        $fontDir = public_path('fonts/dubai');
        $fontFiles = glob($fontDir.DIRECTORY_SEPARATOR.'*.ttf') ?: [];
        sort($fontFiles);
        $fontFingerprint = substr(hash('sha256', implode('|', array_map(
            static fn (string $path): string => hash_file('sha256', $path),
            $fontFiles,
        ))), 0, 12);
        $tempDir = storage_path('app/mpdf/'.$fontFingerprint);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        return array_replace([
            'mode' => 'utf-8',
            'default_font' => 'dubai',
            'fontDir' => array_merge((new ConfigVariables)->getDefaults()['fontDir'], [$fontDir]),
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'dubai' => ['R' => 'Dubai-Regular.ttf', 'L' => 'Dubai-Light.ttf', 'M' => 'Dubai-Medium.ttf', 'B' => 'Dubai-Bold.ttf', 'useOTL' => 0x80, 'useKashida' => 75],
                'dubaimedium' => ['R' => 'Dubai-Medium.ttf', 'useOTL' => 0x80, 'useKashida' => 75],
            ],
            'tempDir' => $tempDir,
        ], $options);
    }
}
