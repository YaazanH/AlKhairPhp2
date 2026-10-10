<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\SaasPlatformSetting;
use App\Services\Landlord\SupportRequestConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSupportSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_platform_owner_can_configure_problem_reasons_severity_and_impact_options(): void
    {
        $owner = PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);
        $options = SupportRequestConfiguration::defaults();
        $options[SupportRequestConfiguration::REASONS][0]['label_ar'] = 'عطل تقني';
        $options[SupportRequestConfiguration::PRIORITIES][0]['enabled'] = false;

        $this->actingAs($owner, 'platform')
            ->put(route('platform.support.settings.update'), ['support_request_options' => $options])
            ->assertRedirect()
            ->assertSessionHas('status', __('support.messages.settings_updated'));

        $this->assertSame('عطل تقني', SaasPlatformSetting::current()->fresh()->support_request_options['reasons'][0]['label_ar']);

        app()->setLocale('ar');
        $configuration = app(SupportRequestConfiguration::class);
        $this->assertSame('عطل تقني', $configuration->options(SupportRequestConfiguration::REASONS)['technical_issue']);
        $this->assertArrayNotHasKey('normal', $configuration->options(SupportRequestConfiguration::PRIORITIES));
        $this->assertArrayHasKey('normal', $configuration->options(SupportRequestConfiguration::PRIORITIES, false));
    }
}
