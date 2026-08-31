<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MobileModalLayoutTest extends TestCase
{
    public function test_mobile_modals_clip_shell_overflow_and_stack_form_fields(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.admin-modal .admin-modal__dialog {', $styles);
        $this->assertStringContainsString('.admin-modal .admin-modal__body {', $styles);
        $this->assertStringContainsString('overflow-x: hidden;', $styles);
        $this->assertStringContainsString(".admin-modal__body form [class*='grid-cols-']:not([data-phone-input])", $styles);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) !important;', $styles);
        $this->assertStringContainsString('select:not(.searchable-select__native)', $styles);
        $this->assertStringContainsString('.admin-modal__body .overflow-x-auto {', $styles);
        $this->assertStringContainsString('.print-template-settings-dialog {', $styles);
        $this->assertStringContainsString(".print-template-settings-dialog [class*='grid-cols-']", $styles);
    }

    public function test_phone_input_marks_its_compound_control_as_a_single_field(): void
    {
        $html = Blade::render('<x-phone-input model="phone" value="+963933333333" />');

        $this->assertStringContainsString('data-phone-input', $html);
    }

    public function test_safari_date_values_are_vertically_centered_inside_the_control(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("input[type='date']::-webkit-date-and-time-value", $styles);
        $this->assertStringContainsString("input[type='date']::-webkit-datetime-edit-fields-wrapper", $styles);
        $this->assertStringContainsString('align-items: center;', $styles);
        $this->assertStringContainsString('height: 100%;', $styles);
        $this->assertStringContainsString('line-height: 1.25rem;', $styles);
    }

    public function test_date_controls_use_day_month_year_a_calendar_icon_and_a_localized_empty_placeholder(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('`${match[3]}-${match[2]}-${match[1]}`', $script);
        $this->assertStringContainsString('input.dataset.datePlaceholder?.trim()', $script);
        $this->assertStringContainsString('display.placeholder = dateInputPlaceholder(input)', $script);
        $this->assertStringContainsString("? 'التاريخ' : 'Date'", $script);
        $this->assertStringContainsString("svg.classList.add('formatted-date-input__calendar-icon')", $script);
        $this->assertStringContainsString("clear.classList.add('formatted-date-input__clear-icon')", $script);
        $this->assertStringContainsString("clear.textContent = '×'", $script);
        $this->assertStringContainsString("wrapper.classList.toggle('formatted-date-input--has-value', !isEmpty)", $script);
        $this->assertStringContainsString("input.dispatchEvent(new Event('change', { bubbles: true }))", $script);
        $this->assertStringContainsString("display.classList.toggle('date-input--empty', isEmpty)", $script);
        $this->assertStringContainsString("display.classList.toggle('date-input--filled', !isEmpty)", $script);
        $this->assertStringContainsString("document.addEventListener('pointerdown'", $script);
        $this->assertStringContainsString('closeActiveNativeDatePicker();', $script);
        $this->assertStringContainsString('.formatted-date-input__picker {', $styles);
        $this->assertStringContainsString('.date-control-peer-group :is(', $styles);
        $this->assertStringContainsString('height: 3.125rem !important;', $styles);
        $this->assertStringContainsString('.formatted-date-input--has-value .formatted-date-input__clear-icon {', $styles);
        $this->assertStringContainsString('.formatted-date-input__display.date-input--empty::placeholder', $styles);
        $this->assertStringContainsString('.formatted-date-input__display.date-input--filled {', $styles);
    }

    public function test_dubai_web_font_uses_safari_safe_woff2_faces_and_preloading(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $head = file_get_contents(resource_path('views/partials/head.blade.php'));
        $printLayout = file_get_contents(resource_path('views/components/print/layout.blade.php'));

        $expectedTtfHashes = [
            'Light' => '485baa2c1d99a596a992541e593291730bc4d5729366d19fe61f30727c4c2dce',
            'Regular' => 'e7b232e0d3a6699f9d9023c0b49a9288ead97a33bc5175b9d1619c09f8672298',
            'Medium' => 'aafb58e479afca2c5e3182998b29b56762124c1e01e3dfbcc4d68b874e878f90',
            'Bold' => 'a489e54c44045bf3b24c5079d394c50b2f39a203bb6e3d95263ca6a534216608',
        ];

        foreach (['Light', 'Regular', 'Medium', 'Bold'] as $weight) {
            $this->assertSame($expectedTtfHashes[$weight], hash_file('sha256', public_path("fonts/dubai/Dubai-{$weight}.ttf")));
            $this->assertFileExists(public_path("fonts/dubai/Dubai-{$weight}.woff2"));
        }

        $this->assertStringNotContainsString('@font-face', $styles);
        $this->assertStringContainsString("'DubaiApp2026'", $styles);
        $this->assertStringNotContainsString('font-weight: 500 600', $styles);
        $this->assertStringNotContainsString('font-weight: 700 900', $styles);
        $this->assertStringContainsString("\$dubaiFontVersion = '2017-20220205-r2'", $head);
        $this->assertStringContainsString('data-dubai-font-faces', $head);
        foreach (['light', 'regular', 'medium', 'bold'] as $weight) {
            $this->assertStringContainsString("\$dubaiFontUrl('{$weight}', 'woff2')", $head);
        }
        $this->assertStringContainsString("font-family: 'DubaiApp2026'", $printLayout);
        $this->assertStringNotContainsString('Segoe UI', $printLayout);
        $this->assertStringContainsString('font-family: var(--font-sans);', $styles);
    }

    public function test_dubai_font_faces_are_rendered_with_application_urls_outside_the_vite_stylesheet(): void
    {
        $head = view('partials.head', ['faviconUrl' => '/favicon.ico'])->render();

        $this->assertStringContainsString('data-dubai-font-faces', $head);
        $this->assertStringContainsString("font-family: 'DubaiApp2026'", $head);
        $this->assertStringContainsString(url('/web-fonts/dubai/regular/woff2').'?v=2017-20220205-r2', $head);
    }

    public function test_dubai_font_endpoint_uses_explicit_safari_safe_mime_types(): void
    {
        $woffResponse = $this->get(route('web-fonts.dubai', ['weight' => 'regular', 'format' => 'woff2'], absolute: false))
            ->assertOk()
            ->assertHeader('content-type', 'font/woff2')
            ->assertHeader('access-control-allow-origin', '*');

        foreach (['public', 'max-age=31536000', 'immutable'] as $directive) {
            $this->assertStringContainsString($directive, (string) $woffResponse->headers->get('cache-control'));
        }

        $this->assertFalse($woffResponse->headers->has('set-cookie'));

        $this->get(route('web-fonts.dubai', ['weight' => 'regular', 'format' => 'ttf'], absolute: false))
            ->assertOk()
            ->assertHeader('content-type', 'font/ttf');

        $this->get('/web-fonts/dubai/unknown/woff2')->assertNotFound();
    }

    public function test_mobile_search_popup_uses_the_browser_top_layer_and_locks_the_viewport(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("toolbar.setAttribute('popover', 'manual')", $script);
        $this->assertStringContainsString('toolbar.showPopover()', $script);
        $this->assertStringContainsString("submit.dataset.mobileTableFilterSubmit = ''", $script);
        $this->assertStringContainsString('component?.$refresh?.()', $script);
        $this->assertStringContainsString("document.documentElement.classList.add('mobile-table-filters-active')", $script);
        $this->assertStringContainsString('data-mobile-table-filter-backdrop', $script);
        $this->assertStringContainsString('body.mobile-table-filters-active > .mobile-table-filter-backdrop', $styles);
        $this->assertStringContainsString('transform: translate(-50%, -50%) !important;', $styles);
    }

    public function test_assessments_use_a_scrollable_table_and_finance_charts_stay_inside_the_phone(): void
    {
        $assessmentView = file_get_contents(resource_path('views/livewire/assessments/index.blade.php'));
        $financeView = file_get_contents(resource_path('views/livewire/finance/dashboard.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('assessment-index-mobile', $assessmentView);
        $this->assertStringContainsString('assessment-index-table-scroll overflow-x-auto', $assessmentView);
        $this->assertStringContainsString('.assessment-index-table-scroll {', $styles);
        $this->assertStringContainsString('min-width: 64rem !important;', $styles);
        $this->assertStringContainsString('data-finance-dashboard', $financeView);
        $this->assertStringContainsString('transform="rotate(-90 21 21)"', $financeView);
        $this->assertStringNotContainsString('max-w-full -rotate-90', $financeView);
        $this->assertStringContainsString('.finance-expense-donut {', $styles);
    }
}
