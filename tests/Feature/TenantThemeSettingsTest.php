<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectToTenantSetup;
use App\Models\AppSetting;
use App\Models\Landlord\Tenant;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Support\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_non_tenant_administrator_cannot_change_the_tenant_theme(): void
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
