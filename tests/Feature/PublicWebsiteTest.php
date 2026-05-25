<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\WebsiteMenuItem;
use App\Models\WebsitePage;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'en']);
        app()->setLocale('en');
    }

    public function test_public_homepage_renders_seeded_content_and_navigation(): void
    {
        $this->seed(WebsiteSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertSee('Masjid AlKhair')
            ->assertSee('Programs')
            ->assertSee('Visit Us');
    }

    public function test_public_homepage_is_localized_in_arabic(): void
    {
        $this->seed(WebsiteSeeder::class);

        $this->withSession(['locale' => 'ar', 'locale_user_selected' => true])
            ->get('/')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('مسجد الخير');
    }

    public function test_public_homepage_uses_website_logo_for_social_preview(): void
    {
        $this->seed(WebsiteSeeder::class);

        AppSetting::storeValue('website', 'logo_path', 'website/branding/logo.png');

        $logoUrl = asset('storage/website/branding/logo.png');

        $this->get('/')
            ->assertOk()
            ->assertSee('property="og:image"', false)
            ->assertSee('content="'.$logoUrl.'"', false)
            ->assertSee('name="twitter:image"', false);
    }

    public function test_public_homepage_can_be_put_under_maintenance_without_blocking_dashboard_route(): void
    {
        $this->seed(WebsiteSeeder::class);

        AppSetting::storeValue('website', 'maintenance_enabled', true, 'boolean');
        AppSetting::storeValue('website', 'maintenance_title', ['en' => 'Maintenance window', 'ar' => 'نافذة صيانة'], 'array');
        AppSetting::storeValue('website', 'maintenance_message', ['en' => 'We will be back soon.', 'ar' => 'سنعود قريباً.'], 'array');

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('Maintenance window')
            ->assertSee('We will be back soon.');

        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_website_management_requires_permission_and_manager_can_customize_pages(): void
    {
        $this->seed([RoleSeeder::class, WebsiteSeeder::class]);

        $manager = User::factory()->create([
            'username' => 'website-manager',
            'phone' => '79995501',
        ]);
        $manager->assignRole('manager');

        $teacher = User::factory()->create([
            'username' => 'website-teacher',
            'phone' => '79995502',
        ]);
        $teacher->assignRole('teacher');

        $this->get(route('settings.website'))->assertRedirect(route('login'));

        $this->actingAs($manager);
        $this->get(route('settings.website'))->assertOk();
        $this->get(route('settings.website.pages'))->assertOk();
        $this->get(route('settings.website.navigation'))->assertOk();

        Volt::test('settings.website')
            ->set('site_name', 'Community Masjid')
            ->call('saveWebsite')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'website',
            'key' => 'site_name',
            'value' => 'Community Masjid',
        ]);

        Volt::test('settings.website-pages')
            ->set('slug', 'community')
            ->set('title_en', 'Community')
            ->set('title_ar', 'المجتمع')
            ->set('sections.0.type', 'rich_text')
            ->set('sections.0.heading_en', 'Community page')
            ->set('sections.0.heading_ar', 'صفحة المجتمع')
            ->set('sections.0.body_en', 'Community details')
            ->set('sections.0.body_ar', 'تفاصيل المجتمع')
            ->call('savePage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('website_pages', [
            'slug' => 'community',
            'is_home' => false,
        ]);

        $this->get('/pages/community')
            ->assertOk()
            ->assertSee('Community page')
            ->assertSee('Community details');

        Volt::test('settings.website-navigation')
            ->set('label_en', 'Learn With Us')
            ->set('label_ar', 'تعلّم معنا')
            ->set('sort_order', '5')
            ->call('saveItem')
            ->assertHasNoErrors();

        $parentId = WebsiteMenuItem::query()
            ->whereJsonContains('label->en', 'Learn With Us')
            ->value('id');

        Volt::test('settings.website-navigation')
            ->set('parent_id', $parentId)
            ->set('website_page_id', WebsitePage::query()->where('slug', 'community')->value('id'))
            ->set('sort_order', '10')
            ->call('saveItem')
            ->assertHasNoErrors();

        $this->get('/')
            ->assertOk()
            ->assertSee('Learn With Us')
            ->assertSee('Community');

        auth()->logout();

        $this->actingAs($teacher);
        $this->get(route('settings.website'))->assertForbidden();
        $this->get(route('settings.website.pages'))->assertForbidden();
        $this->get(route('settings.website.navigation'))->assertForbidden();
    }

    public function test_website_page_builder_can_store_and_render_image_sections(): void
    {
        Storage::fake('public');
        $this->seed([RoleSeeder::class, WebsiteSeeder::class]);

        $manager = User::factory()->create([
            'username' => 'website-image-manager',
            'phone' => '79995503',
        ]);
        $manager->assignRole('manager');

        $this->actingAs($manager);

        Volt::test('settings.website-pages')
            ->set('slug', 'community-gallery')
            ->set('title_en', 'Community Gallery')
            ->set('title_ar', 'معرض المجتمع')
            ->set('sections.0.type', 'image')
            ->set('sections.0.heading_en', 'Community moments')
            ->set('sections.0.heading_ar', 'لحظات المجتمع')
            ->set('sections.0.body_en', 'Highlights from our public programs.')
            ->set('sections.0.body_ar', 'لقطات من برامجنا العامة.')
            ->set('section_image_uploads.0', UploadedFile::fake()->image('community-gallery.jpg', 1200, 800))
            ->call('savePage')
            ->assertHasNoErrors();

        $page = WebsitePage::query()->where('slug', 'community-gallery')->firstOrFail();
        $imagePath = (string) data_get($page->sections, '0.image_path');

        $this->assertNotSame('', $imagePath);
        Storage::disk('public')->assertExists($imagePath);

        $this->get('/pages/community-gallery')
            ->assertOk()
            ->assertSee('Community moments')
            ->assertSee('Highlights from our public programs.')
            ->assertSee('storage/'.ltrim($imagePath, '/'), false);
    }
}
