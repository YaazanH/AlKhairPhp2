<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class PdfOptions
{
    private const MINIMUM_MEMORY_LIMIT = '256M';

    private const MINIMUM_MEMORY_LIMIT_BYTES = 256 * 1024 * 1024;

    public static function make(array $options = []): array
    {
        self::ensureMemoryCapacity();

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        return array_replace([
            'mode' => 'utf-8',
            'default_font' => 'dubai',
            'fontDir' => array_merge((new ConfigVariables)->getDefaults()['fontDir'], [public_path('fonts/dubai')]),
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'dubai' => ['R' => 'Dubai-Regular.ttf', 'L' => 'Dubai-Light.ttf', 'M' => 'Dubai-Medium.ttf', 'B' => 'Dubai-Bold.ttf', 'useOTL' => 0x80, 'useKashida' => 75],
                'dubailight' => ['R' => 'Dubai-Light.ttf', 'useOTL' => 0x80, 'useKashida' => 75],
                'dubaimedium' => ['R' => 'Dubai-Medium.ttf', 'useOTL' => 0x80, 'useKashida' => 75],
            ],
            'tempDir' => $tempDir,
        ], $options);
    }

    public static function ensureMemoryCapacity(): void
    {
        $currentLimit = ini_get('memory_limit');

        if ($currentLimit === false || trim($currentLimit) === '' || trim($currentLimit) === '-1') {
            return;
        }

        if (self::memoryLimitInBytes($currentLimit) >= self::MINIMUM_MEMORY_LIMIT_BYTES) {
            return;
        }

        ini_set('memory_limit', self::MINIMUM_MEMORY_LIMIT);
    }

    private static function memoryLimitInBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $amount = (float) $value;

        $multiplier = match ($unit) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };

        return (int) round($amount * $multiplier);
    }
}
