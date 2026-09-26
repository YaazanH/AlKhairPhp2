<?php

namespace App\Support;

class CourseCalendarPalette
{
    public const START_COLOR = '#365948';

    public const COLORS = [
        '#3f8067', '#245c46', '#628a4b', '#84a34f', '#1f8c7c', '#399c91',
        '#247c91', '#2b7aab', '#407cc7', '#2563eb', '#6478ba', '#475569',
        '#7965a8', '#7c3aed', '#945ca5', '#b667a0', '#a75478', '#be123c',
        '#dc2626', '#bd654e', '#cf8051', '#b88847', '#a37326', '#c6a44a',
        '#71897b', '#6c9092', '#8096ab', '#a193b8', '#bd9195', '#a99884',
    ];

    /** Keep existing distinct colours and replace legacy duplicates consistently in the editor and PDF. */
    public static function uniqueRows(array $rows): array
    {
        $reserved = array_merge([self::START_COLOR], array_map('strtolower', array_column($rows, 'color')));
        $used = [self::START_COLOR];
        foreach ($rows as &$row) {
            $color = strtolower($row['color']);
            if (! preg_match('/^#[0-9a-f]{6}$/', $color) || in_array($color, $used, true)) {
                $color = collect(self::COLORS)->first(fn (string $candidate): bool => ! in_array($candidate, $reserved, true));
                // Older calendars can have more entries than the current palette.
                for ($index = 0; $color === null; $index++) {
                    $candidate = '#'.substr(hash('sha256', 'course-calendar-'.$index), 0, 6);
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
