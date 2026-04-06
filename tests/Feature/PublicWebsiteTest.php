<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteMenuItem;
use App\Models\WebsitePage;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

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

        $this->withSession(['locale' => 'ar'])
            ->get('/')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('مسجد الخير');
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
}
