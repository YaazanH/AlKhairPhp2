<?php

namespace App\Support;

class CourseCalendarPalette
{
    public const START_COLOR = '#a4d0ad';

    public const TEXT_COLOR = '#24332d';

    // Opaque paper-friendly fills, ordered by hue in the picker.
    public const COLORS = [
        '#b8d9cc', '#c9ddb0', '#e2e6ad', '#b5e1c2', '#aee0d8', '#c4e8df',
        '#b5dfe8', '#b5d3ed', '#c6d7f2', '#b5c5f0', '#ced4ee', '#c5d2dc',
        '#d0c3e7', '#c7c2e6', '#dec5e6', '#ecc8e2', '#e8bacc', '#efb7bc',
        '#f2c6bf', '#e9bda9', '#f1ceac', '#e7c9a2', '#ecd9af', '#f2e3ac',
        '#d1ddd2', '#c0d9d8', '#d5e1ec', '#dfd5e9', '#e6d1d2', '#ded3c1',
    ];

    /** Keep legacy hues, but lift dark fills until small calendar text has strong contrast. */
    public static function readableColor(string $color): string
    {
        $color = strtolower($color);
        if (! preg_match('/^#[0-9a-f]{6}$/', $color)) {
            return self::COLORS[0];
        }

        $rgb = array_map(fn (int $offset): int => hexdec(substr($color, $offset, 2)), [1, 3, 5]);
        for ($step = 0; $step <= 20; $step++) {
            $channels = array_map(fn (int $channel): int => (int) round($channel + (255 - $channel) * $step / 20), $rgb);
            $linear = array_map(function (int $channel): float {
                $value = $channel / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, $channels);
            $luminance = $linear[0] * 0.2126 + $linear[1] * 0.7152 + $linear[2] * 0.0722;
            if ($luminance >= 0.55) {
                return sprintf('#%02x%02x%02x', ...$channels);
            }
        }

        return '#ffffff';
    }

    /** Use the same readable, distinct fills in the editor, calendar and legend. */
    public static function uniqueRows(array $rows): array
    {
        $rows = array_map(function (array $row): array {
            $row['color'] = self::readableColor((string) ($row['color'] ?? ''));

            return $row;
        }, $rows);
        $reserved = array_merge([self::START_COLOR], array_map('strtolower', array_column($rows, 'color')));
        $used = [self::START_COLOR];
        foreach ($rows as &$row) {
            $color = strtolower($row['color']);
            if (! preg_match('/^#[0-9a-f]{6}$/', $color) || in_array($color, $used, true)) {
                $color = collect(self::COLORS)->first(fn (string $candidate): bool => ! in_array($candidate, $reserved, true));
                // Older calendars can have more entries than the current palette.
                for ($index = 0; $color === null; $index++) {
                    $candidate = self::readableColor('#'.substr(hash('sha256', 'course-calendar-'.$index), 0, 6));
                    if (! in_array($candidate, $reserved, true)) {
                        $color = $candidate;
                    }
                }
            }
            $row['color'] = $color;
            $used[] = $color;
            $reserved[] = $color;
        }

        return $rows;
    }
}
