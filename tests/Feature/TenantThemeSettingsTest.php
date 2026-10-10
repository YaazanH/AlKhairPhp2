<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectToTenantSetup;
use App\Models\AppSetting;
use App\Models\Landlord\Tenant;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Services\SidebarNavigationService;
use App\Support\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_tenant_administrator_can_save_and_reset_the_tenant_colour(): void
    {
        $administrator = User::factory()->create(['is_tenant_administrator' => true]);
        $this->selectTenant();

        $this->actingAs($administrator)
            ->put(route('settings.theme.update'), ['primary_color' => '#7452D6'])
            ->assertRedirect()
            ->assertSessionHas('status', __('theme.saved'));

        $this->assertSame('#7452d6', AppSetting::groupValues('theme')->get('primary_color'));

        $this->withoutMiddleware(RedirectToTenantSetup::class)
            ->actingAs($administrator)
            ->get(route('settings.theme.edit'))
            ->assertOk()
            ->assertSee('data-tenant-theme', false)
            ->assertSee('--tenant-primary: #7452d6', false);

        $this->actingAs($administrator)
            ->delete(route('settings.theme.reset'))
            ->assertRedirect()
            ->assertSessionHas('status', __('theme.reset_done'));

        $this->assertSame(TenantTheme::DEFAULT_PRIMARY, app(TenantTheme::class)->primaryColor());
        $this->assertDatabaseMissing('app_settings', ['group' => 'theme', 'key' => 'primary_color']);
    }

    public function test_tenant_administrator_can_customize_light_dark_surface_text_and_action_colours(): void
    {
        $administrator = User::factory()->create(['is_tenant_administrator' => true]);
        $this->selectTenant();
        $colors = [
            'primary_color' => '#7452d6',
            'action_color' => '#d97706',
            'light_background_color' => '#f7f4ed',
            'light_surface_color' => '#ffffff',
            'light_text_color' => '#252018',
            'dark_background_color' => '#10131a',
            'dark_surface_color' => '#1b2130',
            'dark_text_color' => '#f4f7ff',
        ];

        $this->actingAs($administrator)
            ->put(route('settings.theme.update'), $colors)
            ->assertRedirect()
            ->assertSessionHas('status', __('theme.saved'));

        foreach ($colors as $key => $value) {
            $this->assertSame($value, AppSetting::groupValues('theme')->get($key));
        }

        $this->withoutMiddleware(RedirectToTenantSetup::class)
            ->actingAs($administrator)
            ->get(route('settings.theme.edit'))
            ->assertOk()
            ->assertSee('--tenant-action: #d97706', false)
            ->assertSee('--app-bg: #f7f4ed', false)
            ->assertSee('--app-panel: #ffffff', false)
            ->assertSee('--app-text: #252018', false)
            ->assertSee('name="dark_background_color"', false);
    }

    public function test_theme_rejects_text_without_enough_surface_contrast(): void
    {
        $administrator = User::factory()->create(['is_tenant_administrator' => true]);
        $this->selectTenant();

        $this->actingAs($administrator)
            ->from(route('settings.theme.edit'))
            ->put(route('settings.theme.update'), [
                'light_background_color' => '#ffffff',
                'light_surface_color' => '#f8f8f8',
                'light_text_color' => '#eeeeee',
            ])
            ->assertRedirect(route('settings.theme.edit'))
            ->assertSessionHasErrors('light_text_color');
    }

    public function test_tenant_administrator_can_access_theme_settings_without_a_duplicate_sidebar_item(): void
    {
        $administrator = User::factory()->create(['is_tenant_administrator' => true]);
        $this->selectTenant();

        $themeItem = collect(app(SidebarNavigationService::class)->sidebarFor($administrator))
            ->pluck('items')
            ->flatten(1)
            ->firstWhere('key', 'tenant_theme_settings');

        $this->assertNull($themeItem);

        $this->actingAs($administrator)
            ->get(route('settings.theme.edit'))
            ->assertOk()
            ->assertSee(route('settings.theme.edit'), false);
    }

    public function test_settings_manager_can_discover_and_change_the_tenant_theme(): void
    {
        $manager = User::factory()->create(['is_tenant_administrator' => false]);
        Permission::findOrCreate('settings.manage');
        $manager->givePermissionTo('settings.manage');
        $this->selectTenant();

        $themeItem = collect(app(SidebarNavigationService::class)->sidebarFor($manager))
            ->pluck('items')
            ->flatten(1)
            ->firstWhere('key', 'tenant_theme_settings');

        $this->assertNull($themeItem);

        $this->actingAs($manager)
            ->get(route('settings.organization'))
            ->assertOk()
            ->assertSee(route('settings.theme.edit'), false);

        $this->actingAs($manager)
            ->put(route('settings.theme.update'), ['primary_color' => '#7452d6'])
            ->assertRedirect()
            ->assertSessionHas('status', __('theme.saved'));

        $this->assertSame('#7452d6', AppSetting::groupValues('theme')->get('primary_color'));
    }

    public function test_user_without_settings_authority_cannot_change_the_tenant_theme(): void
    {
        $user = User::factory()->create(['is_tenant_administrator' => false]);
        $this->selectTenant();

        $this->actingAs($user)
            ->put(route('settings.theme.update'), ['primary_color' => '#7452d6'])
            ->assertForbidden();

        $this->assertDatabaseMissing('app_settings', ['group' => 'theme', 'key' => 'primary_color']);
    }

    public function test_theme_rejects_an_invalid_colour_with_a_clear_message(): void
    {
        $administrator = User::factory()->create(['is_tenant_administrator' => true]);
        $this->selectTenant();

        $this->actingAs($administrator)
            ->from(route('settings.theme.edit'))
            ->put(route('settings.theme.update'), ['primary_color' => 'green'])
            ->assertRedirect(route('settings.theme.edit'))
            ->assertSessionHasErrors(['primary_color' => __('theme.validation.format')]);
    }

    private function selectTenant(): void
    {
        app(TenantContext::class)->set(new Tenant([
            'uuid' => (string) Str::uuid(),
            'name' => 'Theme Tenant',
            'slug' => 'theme-tenant',
            'database_name' => 'testing',
            'status' => Tenant::STATUS_ACTIVE,
        ]));
    }
}
