<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_validation_messages_are_friendly_in_english_and_arabic(): void
    {
        User::factory()->create(['username' => 'ya.ham']);

        app()->setLocale('en');

        $englishMessage = Validator::make(
            ['username' => 'ya.ham'],
            ['username' => ['required', 'unique:users,username']]
        )->errors()->first('username');

        $this->assertSame('The value entered for username is already in use. Please choose another one.', $englishMessage);

        app()->setLocale('ar');

        $arabicMessage = Validator::make(
            ['username' => 'ya.ham'],
            ['username' => ['required', 'unique:users,username']]
        )->errors()->first('username');

        $this->assertSame('القيمة المدخلة في حقل اسم المستخدم مستخدمة بالفعل. يرجى اختيار قيمة أخرى.', $arabicMessage);
    }

    public function test_arabic_validation_never_exposes_raw_english_attribute_names(): void
    {
        app()->setLocale('ar');

        $knownAttributeMessage = Validator::make(
            ['transaction_lookup_no' => ''],
            ['transaction_lookup_no' => ['required']]
        )->errors()->first('transaction_lookup_no');

        $unknownAttributeMessage = Validator::make(
            ['futureInternalField' => ''],
            ['futureInternalField' => ['required']]
        )->errors()->first('futureInternalField');

        $nestedAttributeMessage = Validator::make(
            ['rows' => [['email' => '']]],
            ['rows.*.email' => ['required']]
        )->errors()->first('rows.0.email');

        $this->assertSame('يرجى إدخال رقم الحركة.', $knownAttributeMessage);
        $this->assertSame('يرجى إدخال هذا الحقل.', $unknownAttributeMessage);
        $this->assertSame('يرجى إدخال البريد الإلكتروني.', $nestedAttributeMessage);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z_]/', $knownAttributeMessage.$unknownAttributeMessage.$nestedAttributeMessage);
    }

    public function test_current_standard_validation_rules_have_complete_arabic_messages(): void
    {
        app()->setLocale('ar');

        $validator = Validator::make(
            [
                'backupTime' => '25:99',
                'school_timezone' => 'Not/A-Timezone',
                'currency_rate_input' => 0,
            ],
            [
                'backupTime' => ['date_format:H:i'],
                'school_timezone' => ['timezone:all'],
                'currency_rate_input' => ['numeric', 'gt:0'],
            ]
        );

        $messages = $validator->errors()->all();

        $this->assertNotEmpty($messages);
        $this->assertDoesNotMatchRegularExpression('/validation\.[a-z_]+|[A-Za-z_]{2,}/', implode(' ', $messages));
    }

    public function test_finance_validation_errors_are_translated_in_both_locales(): void
    {
        $keys = [
            'cash_box_delete_linked',
            'protected_currency_delete',
            'currency_delete_linked',
            'category_delete_linked',
            'cash_box_deactivate_with_balance',
            'cash_box_currency_remove_with_balance',
            'base_local_currency_must_differ',
            'protected_currency_deactivate',
            'currency_deactivate_with_balance',
            'base_currency_replacement_required',
            'local_currency_replacement_required',
        ];

        foreach ($keys as $key) {
            $this->assertNotSame("finance.validation.{$key}", trans("finance.validation.{$key}", locale: 'en'));
            $this->assertNotSame("finance.validation.{$key}", trans("finance.validation.{$key}", locale: 'ar'));
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z_]{2,}/', trans("finance.validation.{$key}", locale: 'ar'));
        }

        $financeSettingsSource = file_get_contents(resource_path('views/livewire/settings/finance.blade.php'));
        $this->assertDoesNotMatchRegularExpression("/addError\\([^,]+,\\s*['\"][A-Za-z]/", $financeSettingsSource);
    }
}
