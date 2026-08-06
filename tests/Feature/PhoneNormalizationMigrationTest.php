<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhoneNormalizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_local_numbers_are_backfilled_with_syria_as_the_default_country(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['phone' => '0933 333 333']);
        AppSetting::query()->updateOrCreate(
            ['group' => 'general', 'key' => 'school_phone'],
            ['type' => 'string', 'value' => '011 555 1234'],
        );

        $migration = require database_path('migrations/2026_08_06_000200_normalize_phone_numbers.php');
        $migration->up();

        $this->assertSame('+963933333333', DB::table('users')->where('id', $user->id)->value('phone'));
        $this->assertSame(
            '+963 11 555 1234',
            DB::table('app_settings')->where('group', 'general')->where('key', 'school_phone')->value('value'),
        );
    }
}
