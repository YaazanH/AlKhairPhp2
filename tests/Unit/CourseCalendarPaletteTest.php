<?php

namespace Tests\Unit;

use App\Support\CourseCalendarPalette;
use PHPUnit\Framework\TestCase;

class CourseCalendarPaletteTest extends TestCase
{
    public function test_all_thirty_fills_match_the_picker_and_keep_dark_text_readable(): void
    {
        $this->assertCount(30, array_unique(CourseCalendarPalette::COLORS));
        foreach ([CourseCalendarPalette::START_COLOR, ...CourseCalendarPalette::COLORS] as $fill) {
            $this->assertSame($fill, CourseCalendarPalette::readableColor($fill));
            $this->assertGreaterThanOrEqual(7, $this->contrast($fill), $fill);
        }
    }

    public function test_legacy_dark_colours_and_overflow_colours_remain_readable_unique_and_stable(): void
    {
        $rows = array_map(fn (int $index): array => ['name' => 'Event '.$index, 'color' => $index % 2 ? '#000080' : '#399c91'], range(1, 45));
        $normalized = CourseCalendarPalette::uniqueRows($rows);
        $this->assertCount(45, array_unique(array_column($normalized, 'color')));
        $this->assertSame($normalized, CourseCalendarPalette::uniqueRows($normalized));
        $this->assertSame(array_column($rows, 'name'), array_column($normalized, 'name'));
        foreach ($normalized as $row) {
            $this->assertGreaterThanOrEqual(7, $this->contrast($row['color']));
        }
    }

    private function contrast(string $fill): float
    {
        $luminance = static function (string $hex): float {
            $rgb = array_map(static function (int $offset) use ($hex): float {
                $channel = hexdec(substr($hex, $offset, 2)) / 255;

                return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            }, [1, 3, 5]);

            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };

        return ($luminance($fill) + 0.05) / ($luminance(CourseCalendarPalette::TEXT_COLOR) + 0.05);
    }
}
