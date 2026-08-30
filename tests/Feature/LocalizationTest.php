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

    public function test_compact_locale_switcher_uses_short_segmented_labels_and_arabic_logo_text_has_no_tracking(): void
    {
        $switcher = file_get_contents(resource_path('views/components/locale-switcher.blade.php'));
        $logo = file_get_contents(resource_path('views/components/app-logo.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('locale-compact-switch', $switcher);
        $this->assertStringContainsString('$localeCode === \'en\' ? \'EN\'', $switcher);
        $this->assertStringContainsString('$localeCode === \'ar\' ? \'ع\'', $switcher);
        $this->assertStringContainsString('aria-label="{{ $localeConfig[\'native\'] }}"', $switcher);
        $this->assertStringContainsString("'locale-compact-switch__label--ar' => \$localeCode === 'ar'", $switcher);
        $this->assertStringNotContainsString('tracking-[0.28em]', $logo);
        $this->assertStringContainsString('.locale-compact-switch {', $styles);
        $this->assertStringContainsString(".locale-compact-switch__label--ar {\n    position: relative;\n    top: -2px;", $styles);
        $this->assertStringContainsString(".locale-compact-switch .account-preference-switch__option + .account-preference-switch__option {\n    border-inline-start: 0;", $styles);
        $this->assertStringContainsString("html[dir='rtl'] .locale-compact-switch .account-preference-switch__option + .account-preference-switch__option {\n    border-right: 1px solid var(--locale-compact-divider);", $styles);
        $this->assertStringContainsString("html:not([dir='rtl']) .locale-compact-switch .account-preference-switch__option + .account-preference-switch__option {\n    border-left: 1px solid var(--locale-compact-divider);", $styles);
        $this->assertStringContainsString("\$displaySubtitle = \$useJustifiedArabicSubtitle ? 'مــنــصــة الــتــعــلــم' : \$subtitle;", $logo);
        $this->assertStringContainsString("'text-[0.72rem]' => \$useJustifiedArabicSubtitle", $logo);
        $this->assertStringContainsString('data-app-logo-kashida-subtitle', $logo);
        $this->assertStringContainsString('aria-label="{{ $subtitle }}"', $logo);
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
        $this->assertSame(1, substr_count($sidebar, "{{ __('ui.common.visit_site') }}"));
        $mobileUserMenu = substr($sidebar, strpos($sidebar, '<flux:header class="app-mobile-header lg:hidden">'));
        $this->assertStringNotContainsString("{{ __('ui.common.visit_site') }}", $mobileUserMenu);
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
