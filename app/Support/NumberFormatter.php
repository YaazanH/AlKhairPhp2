<?php

namespace App\Support;

final class NumberFormatter
{
    public static function trimmed(float|int|string|null $value, int $maximumDecimals = 2): string
    {
        $decimals = max(0, min(6, $maximumDecimals));
        $formatted = number_format((float) ($value ?? 0), $decimals, '.', ',');

        return $decimals === 0
            ? $formatted
            : rtrim(rtrim($formatted, '0'), '.');
    }

    public static function withSuffix(
        float|int|string|null $value,
        ?string $suffix,
        int $maximumDecimals = 2,
    ): string {
        return trim(self::trimmed($value, $maximumDecimals).' '.trim((string) $suffix));
    }
}
