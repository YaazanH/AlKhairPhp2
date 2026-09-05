<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requested_arabic_curricula_and_enrolment_titles_are_used(): void
    {
        app()->setLocale('ar');

        $this->assertSame('المناهج', __('curricula.title'));
        $this->assertSame('التسجيل في الدورة', __('crud.enrollments.hero.title'));
    }

    public function test_english_translation_catalogue_uses_uk_spelling(): void
    {
        app()->setLocale('en');

        $forbiddenUsSpellings = '/\\b(?:organization|organizations|organizational|enrollment|enrollments|memorization|memorized|memorizing|color|colors|center|centered|centering|customization|customize|customized|authorized|unauthorized|authorization|optimized|program|programs|catalog|catalogs|uncategorized|itemized|finalized|neighborhood|neighborhoods|toward|behavior|behaviors|favorite|favorites|gray|canceled|canceling|traveled|traveling|traveler|travelers|labeled|labeling|modeled|modeling|analyze|analyzed|analyzing|categorize|categorized|categorizing|recognize|recognized|recognizing|fulfill|fulfillment)\\b/i';

        foreach (glob(lang_path('en/*.php')) as $translationFile) {
            $translations = require $translationFile;
            $values = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($translations));

            foreach ($values as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    $forbiddenUsSpellings,
                    $value,
                    basename($translationFile).': '.$value,
                );
            }
        }

        $this->assertSame('Organisation', __('ui.nav.organization'));
        $this->assertSame('Enrolments', __('ui.nav.enrollments'));
        $this->assertSame('Memorisation', __('ui.nav.memorization'));
        $this->assertSame('Colour', __('id_cards.templates.form.element.color'));
        $this->assertSame('Centre', __('id_cards.templates.form.text_alignments.center'));
        $this->assertSame('Website Customisation', __('site.admin.website.title'));
    }

    public function test_arabic_and_english_translation_catalogues_have_matching_keys(): void
    {
        $arabicFiles = array_map('basename', glob(lang_path('ar/*.php')));
        $englishFiles = array_map('basename', glob(lang_path('en/*.php')));
        sort($arabicFiles);
        sort($englishFiles);

        $this->assertSame($arabicFiles, $englishFiles, 'Arabic and English translation files must match.');

        foreach ($arabicFiles as $file) {
            $arabicKeys = array_keys($this->flattenTranslationKeys(require lang_path("ar/{$file}")));
            $englishKeys = array_keys($this->flattenTranslationKeys(require lang_path("en/{$file}")));
            sort($arabicKeys);
            sort($englishKeys);

            $this->assertSame($arabicKeys, $englishKeys, "Translation keys do not match in {$file}.");
        }
    }

    public function test_uk_spelling_migration_updates_existing_builtin_copy(): void
    {
        $pointTypeId = DB::table('point_types')->insertGetId([
            'name' => 'Memorization Page',
            'code' => 'memorization-page',
            'category' => 'Automatic',
            'default_points' => 0,
            'allow_manual_entry' => false,
            'allow_negative' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('point_policies')->insert([
            'point_type_id' => $pointTypeId,
            'name' => 'Memorization Page Reward',
            'source_type' => 'memorization',
            'trigger_key' => 'pages_count',
            'points' => 1,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pageId = DB::table('website_pages')->insertGetId([
            'slug' => 'uk-copy-test',
            'title' => json_encode(['en' => 'Programs', 'ar' => 'البرامج'], JSON_THROW_ON_ERROR),
            'sections' => json_encode([
                ['body' => ['en' => 'Memorization programs for the neighborhood.', 'ar' => 'برامج الحفظ لأهل الحي.']],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_01_000000_convert_builtin_english_copy_to_uk_spelling.php');
        $migration->up();

        $this->assertSame('Memorisation Page', DB::table('point_types')->where('id', $pointTypeId)->value('name'));
        $this->assertSame('Memorisation Page Reward', DB::table('point_policies')->where('point_type_id', $pointTypeId)->value('name'));

        $page = DB::table('website_pages')->find($pageId);
        $this->assertSame('Programmes', json_decode($page->title, true, 512, JSON_THROW_ON_ERROR)['en']);
        $this->assertSame(
            'Memorisation programmes for the neighbourhood.',
            json_decode($page->sections, true, 512, JSON_THROW_ON_ERROR)[0]['body']['en'],
        );
    }

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
        $this->assertStringContainsString('data-app-logo-period-lockup', $logo);
        $this->assertStringContainsString('data-app-logo-period-title', $logo);
        $this->assertStringContainsString('data-app-logo-period-subtitle', $logo);
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
        $sidebarNavigationMenu = file_get_contents(resource_path('views/livewire/sidebar-navigation-menu.blade.php'));
        $this->assertStringNotContainsString('preserveScrollPosition', $sidebar);
        $this->assertStringNotContainsString('x-on:mousedown.prevent', $sidebar);
        $this->assertStringContainsString('class="app-sidebar-account-menu', $sidebar);
        $this->assertStringContainsString('x-on:click="open = ! open"', $sidebar);
        $this->assertStringContainsString('icon="user-circle"', $sidebar);
        $this->assertSame(1, substr_count($sidebar, "{{ __('ui.common.visit_site') }}"));
        $mobileUserMenu = substr($sidebar, strpos($sidebar, '<flux:header class="app-mobile-header lg:hidden">'));
        $this->assertStringNotContainsString("{{ __('ui.common.visit_site') }}", $mobileUserMenu);
        $this->assertStringNotContainsString("route('finance.reports.index')", $mobileUserMenu);
        $this->assertStringNotContainsString('<flux:profile', $mobileUserMenu);
        $this->assertStringContainsString('<x-mobile-header-mark class="mobile-header-mark text-neutral-200" />', $mobileUserMenu);
        $this->assertStringContainsString('protected const MOBILE_HIDDEN_ITEM_KEYS', $sidebarNavigationMenu);
        $this->assertStringContainsString("\$group['has_mobile_items'] ? '' : 'max-lg:hidden'", $sidebarNavigationMenu);
        $this->assertStringContainsString('data-app-sidebar-navigation-mobile-empty', $sidebarNavigationMenu);
        $this->assertStringContainsString('ArabicMonthFormatter::monthYearWithHijri(now())', $sidebar);
        $this->assertStringContainsString(':justify-subtitle-to-title="true"', $sidebar);
        $this->assertArrayHasKey('finance_reports', app(\App\Services\SidebarNavigationService::class)->defaultItems());
        $this->assertStringNotContainsString('$desktopDropdownAlign', $sidebar);

        $preferences = file_get_contents(resource_path('views/components/account-menu-preferences.blade.php'));
        $this->assertStringContainsString('<flux:icon.moon', $preferences);
        $this->assertStringContainsString('<flux:icon.sun', $preferences);
        $this->assertStringContainsString('<flux:icon.computer-desktop', $preferences);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.app-sidebar-scroll-region::-webkit-scrollbar', $styles);
        $this->assertStringContainsString('scrollbar-width: none;', $styles);
        $this->assertStringContainsString('.mobile-header-mark {', $styles);
        $this->assertStringContainsString("html[dir='rtl'] .mobile-header-mark {", $styles);
        $this->assertStringContainsString('margin-inline-end: 0.375rem;', $styles);
        $this->assertStringNotContainsString('margin-inline-end: 1.5rem;', $styles);
        $this->assertStringNotContainsString('html:not(.dark) .mobile-header-mark {', $styles);
        $this->assertStringContainsString('.app-logo-period-subtitle {', $styles);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('function synchronizeAppLogoPeriodTypography()', $script);
        $this->assertStringContainsString('subtitle.style.width = `${targetWidth}px`;', $script);
        $this->assertStringContainsString('document.fonts?.ready.then(scheduleAppLogoPeriodTypographySync);', $script);

        $mobileHeaderMark = file_get_contents(resource_path('views/components/mobile-header-mark.blade.php'));
        $this->assertStringContainsString('viewBox="0 0 324.39 489.47"', $mobileHeaderMark);
        $this->assertStringContainsString('fill="currentColor"', $mobileHeaderMark);
    }

    /** @return array<string, mixed> */
    private function flattenTranslationKeys(array $translations, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flattened += $this->flattenTranslationKeys($value, $path);

                continue;
            }

            $flattened[$path] = $value;
        }

        return $flattened;
    }
}
