<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_switch_to_arabic_and_receive_rtl_auth_pages(): void
    {
        $this->from('/login')
            ->get(route('locale.switch', 'ar'))
            ->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('تسجيل الدخول');
    }

    public function test_authenticated_users_receive_localized_navigation_when_arabic_is_selected(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'username' => 'arabic-manager',
            'phone' => '7999001',
        ]);

        $user->assignRole('manager');

        $this->withSession(['locale' => 'ar'])
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('app-sidebar-shell border-l', false)
            ->assertSee('app-sidebar-scroll-region', false)
            ->assertSee('account-preference-switch--language', false)
            ->assertSee('account-preference-switch--appearance', false)
            ->assertSee(__('ui.common.my_account'))
            ->assertDontSee('lg:order-2', false)
            ->assertDontSee('lg:order-1', false)
            ->assertSee('الصفحة الرئيسية')
            ->assertSee('التقارير');

        $sidebar = file_get_contents(resource_path('views/components/layouts/app/sidebar.blade.php'));
        $this->assertStringNotContainsString('preserveScrollPosition', $sidebar);
        $this->assertStringNotContainsString('x-on:mousedown.prevent', $sidebar);
        $this->assertStringContainsString('class="app-sidebar-account-menu', $sidebar);
        $this->assertStringContainsString('x-on:click="open = ! open"', $sidebar);
        $this->assertStringContainsString('icon="user-circle"', $sidebar);
        $this->assertStringNotContainsString('$desktopDropdownAlign', $sidebar);

        $preferences = file_get_contents(resource_path('views/components/account-menu-preferences.blade.php'));
        $this->assertStringContainsString('<flux:icon.moon', $preferences);
        $this->assertStringContainsString('<flux:icon.sun', $preferences);
        $this->assertStringContainsString('<flux:icon.computer-desktop', $preferences);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.app-sidebar-scroll-region::-webkit-scrollbar', $styles);
        $this->assertStringContainsString('scrollbar-width: none;', $styles);
    }
}
