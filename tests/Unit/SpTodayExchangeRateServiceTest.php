<?php

namespace Tests\Unit;

use App\Services\SpTodayExchangeRateService;
use Tests\TestCase;

class SpTodayExchangeRateServiceTest extends TestCase
{
    public function test_it_converts_the_old_syp_quote_to_the_new_pound_and_rounds_up(): void
    {
        $html = '<script>self.__next_f.push([1,"{\\"cities\\":{\\"damascus\\":{\\"buy\\":13120,\\"sell\\":13171}}}"])</script>';

        $this->assertSame(132, app(SpTodayExchangeRateService::class)->averageRateFromHtml($html));
    }

    public function test_it_returns_null_when_the_page_has_no_supported_rate_payload(): void
    {
        $this->assertNull(app(SpTodayExchangeRateService::class)->averageRateFromHtml('<html>No rates</html>'));
    }
}
