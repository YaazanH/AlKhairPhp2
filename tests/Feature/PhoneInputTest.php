<?php

namespace Tests\Feature;

use App\Support\PhoneCountries;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PhoneInputTest extends TestCase
{
    public function test_phone_input_offers_country_flags_and_defaults_to_syria(): void
    {
        $syria = PhoneCountries::options()->firstWhere('region', 'SY');

        $this->assertSame('+963', $syria['dial_code']);
        $this->assertSame('🇸🇾', $syria['flag']);

        $html = Blade::render('<x-phone-input model="phone" value="" />');

        $this->assertStringContainsString("regionDial: 'SY|+963'", $html);
        $this->assertStringContainsString('SY|+963', $html);
        $this->assertStringContainsString('🇸🇾', $html);
    }
}
