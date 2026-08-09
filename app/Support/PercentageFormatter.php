<?php

namespace App\Support;

class PercentageFormatter
{
    public static function format(int|float|string|null $value, string $fallback = '-'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $number = (float) $value;
        $decimals = abs($number - round($number)) < 0.00001 ? 0 : 1;

        return number_format($number, $decimals).'%';
    }
}
