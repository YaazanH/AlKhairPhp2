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

    public function test_css_variables_do_not_replace_semantic_success_colours(): void
    {
        $variables = app(TenantTheme::class)->cssVariables();

        $this->assertStringContainsString('--tenant-primary:', $variables);
        $this->assertStringContainsString('--tenant-accent-text:', $variables);
        $this->assertStringNotContainsString('--color-emerald-', $variables);
    }
}
