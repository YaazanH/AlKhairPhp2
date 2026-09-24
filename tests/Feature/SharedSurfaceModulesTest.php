<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\User;
use App\Models\WebsitePage;
use App\Services\Landlord\TenantContext;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use App\Services\PrintTemplates\StandardStudentCardTemplate;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedSurfaceModulesTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(RoleSeeder::class);
        $this->plan = Plan::create(['code' => 'shared-surfaces', 'name' => 'Shared surfaces', 'is_active' => true]);
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'slug' => 'shared-surfaces', 'name' => 'Shared surfaces', 'status' => 'active']);
        $tenant->subscription()->create(['plan_id' => $this->plan->id, 'status' => 'active']);
        app(TenantContext::class)->set($tenant);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_tenant_without_public_website_uses_login_and_keeps_public_content_private(): void
    {
        $this->modules(['students']);
        Storage::fake('public');
        Storage::disk('public')->put('website/pages/private.jpg', 'private');
        Storage::disk('public')->put('teachers/photos/private.jpg', 'private');
        WebsitePage::create(['slug' => 'private-page', 'title' => ['en' => 'Retained page'], 'is_published' => true]);

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/pages/private-page')->assertForbidden();
        $this->get('/storage/website/pages/private.jpg')->assertNotFound();

        $manager = $this->admin();
        $this->get(route('settings.website'))->assertForbidden();
        $this->get('/storage/teachers/photos/private.jpg')->assertForbidden();
        $this->assertDatabaseHas('website_pages', ['slug' => 'private-page', 'is_published' => true]);

        $keys = collect(app(SidebarNavigationService::class)->sidebarFor($manager))
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('key'))
            ->all();
        $this->assertNotContains('public_website_settings', $keys);
    }

    public function test_enabled_public_website_serves_home_pages_and_website_media(): void
    {
        $this->modules(['public_website']);
        Storage::fake('public');
        Storage::disk('public')->put('website/pages/public.jpg', 'public');
        AppSetting::storeValue('website', 'site_name', 'Enabled Mosque');
        WebsitePage::create(['slug' => 'home', 'title' => ['en' => 'Enabled Mosque'], 'is_home' => true, 'is_published' => true, 'hero_media_path' => 'website/pages/public.jpg']);
        WebsitePage::create(['slug' => 'about', 'title' => ['en' => 'About us'], 'is_published' => true]);
        Storage::disk('public')->put('website/pages/unpublished.jpg', 'private');

        $this->get('/')->assertOk()->assertSee('Enabled Mosque');
        $this->get('/pages/about')->assertOk()->assertSee('About us');
        $this->get('/storage/website/pages/public.jpg')->assertOk();
        $this->get('/storage/website/pages/unpublished.jpg')->assertNotFound();
    }

    public function test_standard_student_cards_work_without_custom_templates(): void
    {
        $this->modules(['students', 'id_cards']);
        $this->admin();
        $student = Student::create(['first_name' => 'Standard', 'last_name' => 'Card', 'birth_date' => '2015-01-01', 'status' => 'active']);

        $this->get(route('id-cards.print.create'))
            ->assertOk()
            ->assertSee('Standard Card');

        $standard = app(StandardStudentCardTemplate::class)->get();
        $this->assertTrue($standard->is_system);
        $this->get(route('print-templates.templates.index'))->assertForbidden();

        $custom = PrintTemplate::create([
            'name' => 'Unavailable custom card',
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [['entity' => 'student', 'mode' => 'multiple']],
            'layout_json' => [],
            'is_active' => true,
            'is_student_card' => true,
        ]);

        $payload = [
            'sources' => ['student' => ['multiple' => [$student->id]]],
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
            'copy_count' => 1,
        ];

        $this->post(route('id-cards.print.preview'), ['template_id' => $standard->id] + $payload)
            ->assertOk()
            ->assertSee('Standard Card');
        $this->post(route('id-cards.print.preview'), ['template_id' => $custom->id] + $payload)
            ->assertNotFound();
    }

    public function test_template_registry_hides_disabled_entities_and_fields(): void
    {
        $this->modules(['students', 'id_cards', 'custom_templates']);

        $registry = app(PrintTemplateFieldRegistry::class);
        $this->assertArrayHasKey('student', $registry->entities());
        $this->assertArrayHasKey('user', $registry->entities());
        $this->assertArrayNotHasKey('parent', $registry->entities());
        $this->assertArrayNotHasKey('teacher', $registry->entities());
        $this->assertArrayNotHasKey('activity', $registry->entities());
        $this->assertArrayNotHasKey('finance_request', $registry->entities());
        $this->assertArrayNotHasKey('parent_name', $registry->definitions()['student']);
        $this->assertArrayNotHasKey('group_name', $registry->definitions()['student']);
    }

    public function test_custom_templates_are_independent_from_id_cards(): void
    {
        $this->modules(['custom_templates']);
        $manager = $this->admin();

        $this->get(route('print-templates.templates.index'))->assertOk();
        $this->get(route('id-cards.print.create'))->assertForbidden();

        $keys = collect(app(SidebarNavigationService::class)->sidebarFor($manager))
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('key'))
            ->all();
        $this->assertContains('print_templates', $keys);
        $this->assertNotContains('id_card_print', $keys);
    }

    public function test_template_user_source_excludes_people_from_disabled_modules(): void
    {
        $this->modules(['custom_templates']);
        $admin = User::factory()->create(['name' => 'Platform Operator']);
        $studentUser = User::factory()->create(['name' => 'Hidden Student']);
        Student::create([
            'user_id' => $studentUser->id,
            'first_name' => 'Hidden',
            'last_name' => 'Student',
            'birth_date' => '2015-01-01',
            'status' => 'active',
        ]);

        $ids = collect(app(PrintTemplateFieldRegistry::class)->optionsFor('user'))->pluck('id');

        $this->assertTrue($ids->contains($admin->id));
        $this->assertFalse($ids->contains($studentUser->id));
    }

    private function modules(array $codes): void
    {
        $ids = collect($codes)->map(fn (string $code) => Feature::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true])->id);
        $this->plan->features()->sync($ids);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }
}
