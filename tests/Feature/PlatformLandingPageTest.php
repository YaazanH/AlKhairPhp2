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
            ->assertSee('property="og:image" content="'.asset('images/platform-brand/alkhair-platform-social-card-v2.png').'"', false)
            ->assertSee('property="og:image:type" content="image/png"', false)
            ->assertSee('property="og:image:width" content="2400"', false)
            ->assertSee('property="og:image:height" content="1260"', false)
            ->assertSee('name="twitter:image" content="'.asset('images/platform-brand/alkhair-platform-social-card-v2.png').'"', false)
            ->assertSee('rel="icon" href="'.asset('images/platform-brand/alkhair-platform-icon-v2.png').'"', false)
            ->assertSee('class="landing-brand__mark"', false)
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
            ->assertSee(asset('images/platform-landing/daily-overview-v2.webp'), false)
            ->assertSee(asset('images/platform-landing/learner-progress-v2.webp'), false)
            ->assertSee(asset('images/platform-landing/revenue-finance-v2.webp'), false)
            ->assertSee(asset('images/platform-landing/parent-student-portal-v2.webp'), false)
            ->assertSee(asset('images/platform-landing/report-builder-v2.webp'), false);
    }

    public function test_public_landing_only_lists_active_packages_marked_for_public_visibility(): void
    {
        Plan::query()->create([
            'code' => 'public_package',
            'name' => 'Public Package',
            'is_active' => true,
            'is_public' => true,
        ]);
        Plan::query()->create([
            'code' => 'private_package',
            'name' => 'Private Working Package',
            'is_active' => true,
            'is_public' => false,
        ]);
        Plan::query()->create([
            'code' => 'inactive_public_package',
            'name' => 'Inactive Public Package',
            'is_active' => false,
            'is_public' => true,
        ]);

        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get('http://localhost/')
            ->assertOk()
            ->assertSee('Public Package')
            ->assertDontSee('Private Working Package')
            ->assertDontSee('Inactive Public Package');
    }

    public function test_default_landing_explains_revenue_and_internal_package_capabilities_in_public_language(): void
    {
        $core = Feature::query()->create(['code' => Feature::CORE, 'name' => 'Core', 'is_core' => true, 'is_active' => true]);
        $finance = Feature::query()->create(['code' => Feature::FINANCE, 'name' => 'Finance', 'is_active' => true]);
        $printing = Feature::query()->create(['code' => Feature::CUSTOM_PRINTING, 'name' => 'Custom Printing', 'is_active' => true]);
        $plan = Plan::query()->create(['code' => 'core_finance_printing', 'name' => 'Core + Finance + Custom Printing', 'is_active' => true]);
        $plan->features()->sync([$core->id, $finance->id, $printing->id]);

        $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get('http://localhost/')
            ->assertOk()
            ->assertSee('Revenue you can understand')
            ->assertSee('Revenue and finance in one view')
            ->assertSee('Parent and student portals')
            ->assertSee('Core + Finance + Custom Printing')
            ->assertSee('Students')
            ->assertSee('Teachers')
            ->assertSee('Classes and Enrollment')
            ->assertSee('Income and Revenue')
            ->assertSee('Expenses and Withdrawals')
            ->assertSee('Custom Print Templates')
            ->assertDontSee('Complete Operations')
            ->assertDontSee('core_finance_printing');
    }

    public function test_legacy_published_content_gains_new_revenue_and_family_stories_without_losing_existing_copy(): void
    {
        $defaults = PlatformLandingContent::defaults();
        $legacy = $defaults;
        $legacy['feature_items'] = [$defaults['feature_items'][0], $defaults['feature_items'][1], $defaults['feature_items'][3]];
        $legacyFamilyStory = $defaults['showcase_items'][3];
        $legacyFamilyStory['title'] = ['en' => 'Families stay in the loop', 'ar' => 'العائلات على اطلاع دائم'];
        $legacyFamilyStory['body'] = ['en' => 'Old family communication copy.', 'ar' => 'نص قديم لتواصل العائلة.'];
        $legacy['showcase_items'] = [$defaults['showcase_items'][0], $defaults['showcase_items'][1], $legacyFamilyStory, $defaults['showcase_items'][4]];
        $legacy['showcase_items'][0]['title']['en'] = 'Our customised daily overview';

        $normalized = PlatformLandingContent::normalize($legacy);

        $this->assertCount(4, $normalized['feature_items']);
        $this->assertCount(5, $normalized['showcase_items']);
        $this->assertSame('Our customised daily overview', $normalized['showcase_items'][0]['title']['en']);
        $this->assertTrue(collect($normalized['feature_items'])->contains(fn (array $item) => $item['title']['en'] === 'Revenue you can understand'));
        $this->assertTrue(collect($normalized['showcase_items'])->contains(fn (array $item) => $item['title']['en'] === 'Revenue and finance in one view'));
        $this->assertTrue(collect($normalized['showcase_items'])->contains(fn (array $item) => $item['title']['en'] === 'Parent and student portals'));
        $this->assertSame(array_fill_keys(PlatformLandingContent::SOCIAL_PLATFORMS, null), $normalized['social_links']);
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

    public function test_social_links_are_validated_and_follow_the_draft_publish_workflow(): void
    {
        $owner = $this->administrator('social-editor@example.test');
        $content = PlatformLandingContent::defaults();
        $content['social_links']['instagram'] = 'https://www.instagram.com/alkhair.platform';
        $content['social_links']['whatsapp'] = 'https://wa.me/963900000000';

        $this->actingAs($owner, 'platform')->put(route('platform.landing.update'), $this->formPayload($content))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('https://www.instagram.com/alkhair.platform', PlatformLandingPage::query()->sole()->draft_content['social_links']['instagram']);
        $this->get('http://localhost/')
            ->assertDontSee('https://www.instagram.com/alkhair.platform', false)
            ->assertDontSee('landing-footer__social', false);

        $this->actingAs($owner, 'platform')->post(route('platform.landing.publish'))->assertRedirect();
        $this->get('http://localhost/')
            ->assertSee('landing-footer__social', false)
            ->assertSee('href="https://www.instagram.com/alkhair.platform"', false)
            ->assertSee('href="https://wa.me/963900000000"', false)
            ->assertSee('rel="noopener noreferrer"', false);
        $this->withHeader('Accept-Language', 'ar-SY,ar;q=0.9')->get('http://localhost/')
            ->assertSee('تابع منصة الخير');

        $invalid = PlatformLandingContent::defaults();
        $invalid['social_links']['facebook'] = 'javascript:alert(1)';
        $this->actingAs($owner, 'platform')->put(route('platform.landing.update'), $this->formPayload($invalid))
            ->assertSessionHasErrors('content.social_links.facebook');
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
