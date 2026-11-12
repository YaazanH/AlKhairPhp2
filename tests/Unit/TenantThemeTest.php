<?php

namespace Tests\Unit;

use App\Support\TenantTheme;
use Tests\TestCase;

class TenantThemeTest extends TestCase
{
    public function test_palette_derives_accessible_light_dark_and_foreground_colours(): void
    {
        $theme = app(TenantTheme::class);

        foreach (['#f4d40b', '#ffffff', '#000000', '#7452d6', '#0b8f43'] as $colour) {
            $palette = $theme->palette($colour);

            $this->assertTrue($theme->canProduceReadablePalette($colour));
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['foreground']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['light_accent']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['dark_accent']);
            $this->assertCount(11, $palette['shades']);
        }
    }

    public function test_invalid_colour_cannot_create_a_palette(): void
    {
        $this->assertFalse(app(TenantTheme::class)->canProduceReadablePalette('green'));
    }

    public function test_sixteen_presets_include_the_original_v1_theme_as_an_available_variant(): void
    {
        $presets = TenantTheme::presets();

        $this->assertCount(16, $presets);
        $this->assertSame(TenantTheme::DEFAULT_COLORS, $presets['original']);
        $this->assertSame(array_keys(TenantTheme::DEFAULT_COLORS), array_keys($presets['blue']));
    }

    public function test_css_variables_do_not_replace_semantic_success_colours(): void
    {
        $variables = app(TenantTheme::class)->cssVariables();

        $this->assertStringContainsString('--tenant-primary:', $variables);
        $this->assertStringContainsString('--tenant-action:', $variables);
        $this->assertStringContainsString('--app-bg:', $variables);
        $this->assertStringContainsString('--app-panel:', $variables);
        $this->assertStringContainsString('--app-text:', $variables);
        $this->assertStringContainsString('--tenant-accent-text:', $variables);
        $this->assertStringNotContainsString('--color-emerald-', $variables);
    }

    public function test_custom_text_must_contrast_with_page_and_surface_colours(): void
    {
        $theme = app(TenantTheme::class);
        $colors = TenantTheme::DEFAULT_COLORS;
        $colors['light_text_color'] = '#eeeeee';
        $colors['light_background_color'] = '#ffffff';
        $colors['light_surface_color'] = '#f8f8f8';

        $this->assertArrayHasKey('light_text_color', $theme->readabilityErrors($colors));

        $colors['light_text_color'] = '#111111';

        $this->assertSame([], $theme->readabilityErrors($colors));
    }

    public function test_colour_variants_recolour_both_appearances_and_remain_readable(): void
    {
        $theme = app(TenantTheme::class);
        foreach (TenantTheme::presets() as $key => $colors) {
            $this->assertSame([], $theme->readabilityErrors($colors), $key);
            if ($key !== 'original') {
                $this->assertNotSame(TenantTheme::DEFAULT_COLORS['light_background_color'], $colors['light_background_color'], $key);
                $this->assertNotSame(TenantTheme::DEFAULT_COLORS['dark_surface_color'], $colors['dark_surface_color'], $key);
            }
        }
        $keys = array_keys(TenantTheme::presets());
        $this->assertSame('original', $keys[0]);
        $this->assertSame('slate', $keys[array_key_last($keys)]);
        $this->assertLessThan(array_search('blue', $keys), array_search('original', $keys));
        $this->assertLessThan(array_search('rose', $keys), array_search('blue', $keys));
    }
}
