<?php

namespace Tests\Unit;

use App\Services\SpTodayExchangeRateService;
use Tests\TestCase;

class SpTodayExchangeRateServiceTest extends TestCase
{
    public function test_it_reads_old_syp_buy_and_sell_values_and_rounds_the_average_up(): void
    {
        $html = '<script>self.__next_f.push([1,"{\\"cities\\":{\\"damascus\\":{\\"buy\\":13120,\\"sell\\":13171}}}"])</script>';

        $this->assertSame(13146, app(SpTodayExchangeRateService::class)->averageRateFromHtml($html));
    }

    public function test_it_returns_null_when_the_page_has_no_supported_rate_payload(): void
    {
        $this->assertNull(app(SpTodayExchangeRateService::class)->averageRateFromHtml('<html>No rates</html>'));
    }
}
