<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrowserFontWeightTest extends TestCase
{
    public function test_browser_ui_maps_typography_to_the_available_dubai_faces(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $head = file_get_contents(resource_path('views/partials/head.blade.php'));

        foreach ([
            '--font-weight-light: 300;',
            '--font-weight-normal: 400;',
            '--font-weight-medium: 500;',
            '--font-weight-semibold: 500;',
            '--font-weight-bold: 700;',
            '--font-weight-extrabold: 700;',
            '--font-weight-black: 700;',
        ] as $mapping) {
            $this->assertStringContainsString($mapping, $styles);
        }

        $this->assertDoesNotMatchRegularExpression('/font-weight:\s*(?:100|200|600|800|900)\s*;/', $styles);

        foreach ([300, 400, 500, 700] as $weight) {
            $this->assertStringContainsString("font-weight: {$weight};", $head);
        }

        $this->assertStringContainsString('body {', $styles);
        $this->assertStringContainsString('font-weight: 400;', $styles);
        $this->assertStringContainsString('small, .text-xs {', $styles);
        $this->assertStringContainsString(':is(h1, h2, h3, h4, h5, h6).font-semibold', $styles);
        $this->assertStringContainsString(':is(.text-lg, .text-xl, .text-2xl, .text-3xl, .text-4xl, .text-5xl).font-semibold', $styles);
    }
}
