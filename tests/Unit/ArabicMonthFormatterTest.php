<?php

namespace Tests\Unit;

use App\Support\ArabicMonthFormatter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ArabicMonthFormatterTest extends TestCase
{
    public function test_it_combines_the_gregorian_and_hijri_month_and_year(): void
    {
        $date = Carbon::parse('2026-09-02 12:00:00', 'Asia/Damascus');

        $this->assertSame('أيلول 2026', ArabicMonthFormatter::monthYear($date));
        $this->assertSame('ربيع الأول 1448', ArabicMonthFormatter::hijriMonthYear($date));
        $this->assertSame('ربيع الأول ١٤٤٨هـ - أيلول ٢٠٢٦ مـ', ArabicMonthFormatter::monthYearWithHijri($date));
    }
}
