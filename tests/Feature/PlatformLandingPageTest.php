<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformLandingEnquiry;
use App\Models\Landlord\PlatformLandingPage;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Support\PlatformLandingContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformLandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        config()->set('tenancy.base_domain', 'localhost');
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_base_domain_shows_bilingual_platform_landing_page_using_browser_language(): void
    {
        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get('http://localhost/')
            ->assertOk()
            ->assertSee('Give your team clarity')
            ->assertSee('Request a guided demo')
            ->assertSee(route('platform.login'), false);

        $this->withHeader('Accept-Language', 'ar-SY,ar;q=0.9')->get('http://localhost/')
            ->assertOk()
            ->assertSee('امنح فريقك الوضوح')
            ->assertSee('dir="rtl"', false);
    }

    public function test_public_visitor_can_send_a_simple_enquiry(): void
    {
        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->post('http://localhost/platform-enquiries', [
            'name' => 'Mariam Hassan',
            'organisation_name' => 'Al Noor Centre',
            'email' => 'mariam@example.test',
            'phone' => '+963 900 000 000',
            'message' => 'We would like a demo.',
        ])->assertRedirect('http://localhost#contact');

        $this->assertDatabaseHas('platform_landing_enquiries', [
            'email' => 'mariam@example.test',
            'organisation_name' => 'Al Noor Centre',
            'locale' => 'en',
        ], 'landlord');
        $this->assertSame(1, PlatformLandingEnquiry::query()->count());
    }

    public function test_public_packages_show_full_details_and_product_story_uses_distinct_default_images(): void
    {
        $features = collect(range(1, 6))->map(fn (int $index): Feature => Feature::query()->create([
            'code' => 'capability_'.$index,
            'name' => 'Capability '.$index,
            'is_active' => true,
        ]));
        $plan = Plan::query()->create([
            'code' => 'complete',
            'name' => 'Complete',
            'description' => 'A complete package for growing organisations.',
            'is_active' => true,
            'price_syp' => 250000,
            'billing_period_days' => 30,
        ]);
        $plan->features()->sync($features->pluck('id'));

        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get('http://localhost/')
            ->assertOk()
            ->assertSee('See full package details')
            ->assertSee('Capability 6')
            ->assertDontSee('10 GB')
            ->assertSee(asset('images/platform-landing/daily-overview.webp'), false)
            ->assertSee(asset('images/platform-landing/learner-progress.webp'), false)
            ->assertSee(asset('images/platform-landing/report-builder.webp'), false);
    }

    public function test_manage_and_publish_permissions_are_separate_and_public_content_changes_only_after_publish(): void
    {
        $owner = $this->administrator('owner@example.test');
        $manager = $this->administrator('editor@example.test');
        $role = PlatformRole::query()->create(['name' => 'Landing editor']);
        $role->permissions()->sync([PlatformPermission::query()->where('code', 'manage.landing-page')->sole()->id]);
        $manager->roles()->sync([$role->id]);

        $content = PlatformLandingContent::defaults();
        $content['hero_title']['en'] = 'A newly drafted headline';
        $payload = $this->formPayload($content);

        $this->actingAs($manager, 'platform')->put(route('platform.landing.update'), $payload)->assertRedirect();
        $this->actingAs($manager, 'platform')->post(route('platform.landing.publish'))->assertForbidden();
        $this->get('http://localhost/')->assertDontSee('A newly drafted headline');

        $this->actingAs($owner, 'platform')->post(route('platform.landing.publish'))->assertRedirect();
        $this->get('http://localhost/')->assertSee('A newly drafted headline');
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'platform_landing_published'], 'landlord');
    }

    public function test_publish_identifies_incomplete_language_content_and_revision_can_be_restored(): void
    {
        $owner = $this->administrator('owner@example.test');
        $page = PlatformLandingPage::query()->create(['key' => 'main', 'draft_content' => PlatformLandingContent::defaults()]);
        $this->actingAs($owner, 'platform')->post(route('platform.landing.publish'))->assertRedirect();
        $first = $page->fresh()->publishedRevision;

        $content = PlatformLandingContent::defaults();
        $content['hero_title']['en'] = 'Second public headline';
        $this->actingAs($owner, 'platform')->put(route('platform.landing.update'), $this->formPayload($content))->assertRedirect();
        $this->actingAs($owner, 'platform')->post(route('platform.landing.publish'))->assertRedirect();
        $this->get('http://localhost/')->assertSee('Second public headline');

        $this->actingAs($owner, 'platform')->post(route('platform.landing.restore', $first))->assertRedirect();
        $this->get('http://localhost/')->assertSee('Give your team clarity')->assertDontSee('Second public headline');

        $invalid = PlatformLandingContent::defaults();
        $invalid['hero_title']['ar'] = '';
        $this->actingAs($owner, 'platform')->put(route('platform.landing.update'), $this->formPayload($invalid))->assertRedirect();
        $this->actingAs($owner, 'platform')->post(route('platform.landing.publish'))->assertSessionHasErrors('hero_title.ar');
    }

    public function test_editor_can_upload_and_publicly_serve_a_real_product_screenshot(): void
    {
        Storage::fake('public');
        $owner = $this->administrator('owner@example.test');
        $payload = $this->formPayload(PlatformLandingContent::defaults());
        $payload['showcase_images'] = [0 => UploadedFile::fake()->image('dashboard.webp', 1200, 700)];

        $this->actingAs($owner, 'platform')->put(route('platform.landing.update'), $payload)->assertRedirect();
        $page = PlatformLandingPage::query()->sole();
        $path = $page->draft_content['showcase_items'][0]['image_path'];
        Storage::disk('public')->assertExists($path);

        $this->actingAs($owner, 'platform')->post(route('platform.landing.publish'))->assertRedirect();
        $this->get(route('platform-site.media', ['path' => $path]))->assertOk();
        $this->get('http://localhost/')->assertSee(route('platform-site.media', ['path' => $path]), false);
    }

    private function administrator(string $email): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Platform Admin', 'email' => $email, 'password' => 'secret-password', 'is_active' => true]);
    }

    private function formPayload(array $content): array
    {
        return [
            'content' => $content,
            'section_order' => collect($content['section_order'])->mapWithKeys(fn ($section, $index) => [$section => $index + 1])->all(),
            'enabled_sections' => collect($content['enabled_sections'])->filter()->map(fn () => '1')->all(),
        ];
    }
}
