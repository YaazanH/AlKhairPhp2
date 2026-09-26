<?php

namespace Tests\Unit;

use App\Support\DateDisplay;
use PHPUnit\Framework\TestCase;

class DateDisplayTest extends TestCase
{
    public function test_each_date_in_a_translated_range_is_isolated_and_day_first(): void
    {
        foreach (['إلى', 'to'] as $separator) {
            $html = DateDisplay::html("17-09-2026 $separator 2027-05-06")->toHtml();
            $this->assertSame(2, substr_count($html, 'dir="ltr"'));
            $this->assertStringContainsString('>17-09-2026</span>', $html);
            $this->assertStringContainsString('>06-05-2027</span>', $html);
            $this->assertStringContainsString("</span> $separator <span", $html);
        }
    }

    public function test_dates_are_validated_and_surrounding_content_is_escaped(): void
    {
        $html = DateDisplay::html('<script>alert(1)</script> 29/02/2024 31-02-2026 ID2026-09-17')->toHtml();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('>29-02-2024</span>', $html);
        $this->assertStringContainsString('31-02-2026 ID2026-09-17', $html);
        $this->assertSame(1, substr_count($html, 'dir="ltr"'));
        $this->assertSame('', DateDisplay::html(null)->toHtml());
        $this->assertSame('—', DateDisplay::html('—')->toHtml());
    }

    public function test_text_labels_keep_time_and_isolate_dates_without_html(): void
    {
        $this->assertSame("التاريخ \u{2066}17-09-2026 16:15\u{2069}", DateDisplay::text('التاريخ 17-09-2026 16:15'));
        $this->assertStringContainsString('>17-09-2026 16:15</span>', DateDisplay::html('2026-09-17 16:15')->toHtml());
    }
}
