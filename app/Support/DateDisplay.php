<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** Presentation only: database, form and API dates keep their ISO values. */
final class DateDisplay
{
    private const DATE_PATTERN = '/(?<![\pL\pN_])(?:(\d{4})-(\d{2})-(\d{2})|(\d{2})[-\/](\d{2})[-\/](\d{4}))(?![\pL\pN_])([ ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?)?/u';

    /** Escape the entire label, isolating each date from surrounding RTL text. */
    public static function html(?string $label): HtmlString
    {
        return new HtmlString(self::replace(e($label ?? ''), static fn (string $date): string => '<span dir="ltr" style="display:inline-block;direction:ltr;unicode-bidi:isolate;white-space:nowrap">'.$date.'</span>'
        ));
    }

    /** Plain text counterpart for tooltips and SVG labels, which cannot contain HTML. */
    public static function text(?string $label): string
    {
        return self::replace($label ?? '', static fn (string $date): string => "\u{2066}".$date."\u{2069}");
    }

    private static function replace(string $label, callable $render): string
    {
        return preg_replace_callback(self::DATE_PATTERN, static function (array $match) use ($render): string {
            [$year, $month, $day] = $match[1] !== ''
                ? [(int) $match[1], (int) $match[2], (int) $match[3]]
                : [(int) $match[6], (int) $match[5], (int) $match[4]];

            return checkdate($month, $day, $year)
                ? $render(sprintf('%02d-%02d-%04d', $day, $month, $year).($match[7] ?? ''))
                : $match[0];
        }, $label) ?? $label;
    }
}
