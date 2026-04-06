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
}
