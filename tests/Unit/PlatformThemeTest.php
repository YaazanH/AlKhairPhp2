<?php

namespace Tests\Unit;

use Tests\TestCase;

class PlatformThemeTest extends TestCase
{
    public function test_platform_light_and_dark_palette_pairs_meet_wcag_text_contrast(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);

        $light = $this->paletteFrom($css, '.platform-body');
        $dark = $this->paletteFrom($css, 'html.dark .platform-body');

        foreach ([$light, $dark] as $palette) {
            foreach (['text', 'muted', 'subtle', 'accent', 'danger', 'warning', 'info', 'violet'] as $foreground) {
                $this->assertContrastAtLeast(
                    4.5,
                    $palette[$foreground],
                    $palette['surface'],
                    "The Platform {$foreground} colour must remain readable on its surface."
                );
            }

            $this->assertContrastAtLeast(
                4.5,
                $palette['on-accent'],
                $palette['accent'],
                'Platform action text must remain readable on action buttons.'
            );
        }

        $this->assertContrastAtLeast(
            4.5,
            $light['accent'],
            $light['accent-soft'],
            'Platform status text must remain readable on its light accent badge.'
        );
    }

    /**
     * @return array<string, string>
     */
    private function paletteFrom(string $css, string $selector): array
    {
        $matched = preg_match_all(
            '/'.preg_quote($selector, '/').'\s*\{(?<body>.*?)^\}/ms',
            $css,
            $blocks,
            PREG_SET_ORDER
        );

        $this->assertGreaterThan(0, $matched, "Missing {$selector} palette block.");

        $block = collect($blocks)->first(
            fn (array $candidate): bool => str_contains($candidate['body'], '--platform-text:')
        );

        $this->assertNotNull($block, "Missing semantic variables in the {$selector} palette block.");

        preg_match_all(
            '/--platform-(?<name>[a-z-]+):\s*(?<value>#[0-9a-f]{6});/i',
            $block['body'],
            $variables,
            PREG_SET_ORDER
        );

        return collect($variables)->mapWithKeys(
            fn (array $variable): array => [$variable['name'] => strtolower($variable['value'])]
        )->all();
    }

    private function assertContrastAtLeast(float $minimum, string $foreground, string $background, string $message): void
    {
        $lighter = max($this->luminance($foreground), $this->luminance($background));
        $darker = min($this->luminance($foreground), $this->luminance($background));

        $this->assertGreaterThanOrEqual($minimum, ($lighter + 0.05) / ($darker + 0.05), $message);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(
            static function (int $offset) use ($hex): float {
                $channel = hexdec(substr($hex, $offset, 2)) / 255;

                return $channel <= 0.04045
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4;
            },
            [1, 3, 5]
        );

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }
}
