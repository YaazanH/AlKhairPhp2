<?php

namespace Tests\Unit;

use App\Support\PercentageFormatter;
use PHPUnit\Framework\TestCase;

class PercentageFormatterTest extends TestCase
{
    public function test_it_preserves_only_one_meaningful_decimal_place(): void
    {
        $this->assertSame('88%', PercentageFormatter::format(88));
        $this->assertSame('88%', PercentageFormatter::format('88.00'));
        $this->assertSame('88.5%', PercentageFormatter::format('88.50'));
        $this->assertSame('88.6%', PercentageFormatter::format('88.55'));
    }
}
