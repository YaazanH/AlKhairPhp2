<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\User;
use App\Support\PhoneCountries;
use App\Support\PhoneNumberFormatter;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PhoneInputTest extends TestCase
{
    public function test_phone_input_shows_only_the_selected_code_until_the_country_menu_is_opened(): void
    {
        $syria = PhoneCountries::options()->firstWhere('region', 'SY');

        $this->assertSame('+963', $syria['dial_code']);
        $this->assertSame('🇸🇾', $syria['flag']);
        $this->assertNotEmpty($syria['pattern']);

        $html = Blade::render('<x-phone-input model="phone" value="+12025550123" />');

        $this->assertStringContainsString("region: 'US'", $html);
        $this->assertStringContainsString('x-text="selectedDial"', $html);
        $this->assertStringContainsString('flagcdn.com/32x24', $html);
        $this->assertStringContainsString('x-text="country.name"', $html);
        $this->assertStringContainsString('x-text="country.dial_code"', $html);
        $this->assertStringContainsString('phone-country-option', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertFalse(PhoneCountries::options()->contains('region', 'IL'));
        $this->assertFalse(PhoneCountries::options()->contains('region', 'VG'));
        $this->assertFalse(PhoneCountries::options()->contains('region', 'TA'));
        $this->assertTrue(PhoneCountries::options()->contains('region', 'SH'));
        $this->assertSame('هونغ كونغ', PhoneCountries::options()->firstWhere('region', 'HK')['name']);
    }

    public function test_phone_numbers_are_normalized_and_formatted_by_country(): void
    {
        $this->assertSame('+963933333333', PhoneNumberFormatter::normalize('0933 333 333'));
        $this->assertSame('+963 933 333 333', PhoneNumberFormatter::format('0933 333 333'));
        $this->assertSame('+1 (202) 555-0123', PhoneNumberFormatter::format('+1 202 555 0123'));
        $this->assertSame('+49 1512 3456789', PhoneNumberFormatter::format('0049 1512 3456789'));
    }

    public function test_phone_model_attributes_store_e164_and_display_official_formatting(): void
    {
        $user = new User(['phone' => '0933 333 333']);
        $parent = new ParentProfile(['father_phone' => '٠٩٤٤٥٥٥٠٠٠']);

        $this->assertSame('+963933333333', $user->getAttributes()['phone']);
        $this->assertSame('+963 933 333 333', $user->phone);
        $this->assertSame('+963944555000', $parent->getAttributes()['father_phone']);
        $this->assertSame('+963 944 555 000', $parent->father_phone);
    }
}
