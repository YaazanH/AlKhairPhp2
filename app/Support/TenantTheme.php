<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\Landlord\TenantContext;

class TenantTheme
{
    public const DEFAULT_PRIMARY = '#0b8f43';

    public const DEFAULT_COLORS = [
        'primary_color' => self::DEFAULT_PRIMARY,
        'action_color' => '#0b8f43',
        'light_background_color' => '#e8ebdf',
        'light_surface_color' => '#fbfaf4',
        'light_text_color' => '#112b1c',
        'dark_background_color' => '#04160b',
        'dark_surface_color' => '#072714',
        'dark_text_color' => '#f3fff6',
    ];

    public const PRESET_PRIMARY_COLORS = [
        'original' => '#0b8f43',
        'emerald' => '#047857',
        'teal' => '#0f766e',
        'cyan' => '#0e7490',
        'sky' => '#0369a1',
        'blue' => '#1d4ed8',
        'indigo' => '#4338ca',
        'violet' => '#6d28d9',
        'purple' => '#7e22ce',
        'rose' => '#be123c',
        'crimson' => '#b91c1c',
        'orange' => '#c2410c',
        'amber' => '#a16207',
        'olive' => '#4d7c0f',
        'slate' => '#475569',
        'sand' => '#8b5e34',
    ];

    /** @return array<string, array<string, string>> */
    public static function presets(): array
    {
        $primaries = self::PRESET_PRIMARY_COLORS;
        [$startingHue] = self::hsl(self::DEFAULT_PRIMARY);
        uasort($primaries, function (string $first, string $second) use ($startingHue): int {
            [$firstHue, $firstSaturation] = self::hsl($first);
            [$secondHue, $secondSaturation] = self::hsl($second);

            return (($firstSaturation < 0.2 ? 360 : 0) + fmod($firstHue - $startingHue + 360, 360))
                <=> (($secondSaturation < 0.2 ? 360 : 0) + fmod($secondHue - $startingHue + 360, 360));
        });

        return array_map(self::variantColors(...), $primaries);
    }

    /** @return array<string, string> */
    public static function variantColors(string $primary): array
    {
        $colors = array_map(fn (string $color): string => self::variantColor($color, $primary), self::DEFAULT_COLORS);
        $colors['primary_color'] = $primary;
        $colors['action_color'] = $primary;

        return $colors;
    }

    /** Preserve Mudeer's lightness, saturation relationships and supporting shades. */
    private static function variantColor(string $source, string $primary, bool $neutral = false): string
    {
        if ($primary === self::DEFAULT_PRIMARY) {
            return $source;
        }
        if ($source === self::DEFAULT_PRIMARY) {
            return $primary;
        }

        [$hue, $saturation, $lightness] = self::hsl($source);
        [$primaryHue, $primarySaturation] = self::hsl($primary);
        [$defaultHue, $defaultSaturation] = self::hsl(self::DEFAULT_PRIMARY);
        $hue = $neutral || $hue < 80 ? $primaryHue : fmod($hue + $primaryHue - $defaultHue + 360, 360);
        $saturation = min(1, $saturation * $primarySaturation / $defaultSaturation);
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $intermediate = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $offset = $lightness - $chroma / 2;
        $channels = match (true) {
            $hue < 60 => [$chroma, $intermediate, 0],
            $hue < 120 => [$intermediate, $chroma, 0],
            $hue < 180 => [0, $chroma, $intermediate],
            $hue < 240 => [0, $intermediate, $chroma],
            $hue < 300 => [$intermediate, 0, $chroma],
            default => [$chroma, 0, $intermediate],
        };

        return '#'.implode('', array_map(fn ($channel): string => sprintf('%02x', (int) round(($channel + $offset) * 255)), $channels));
    }

    /** @return array{float, float, float} */
    private static function hsl(string $hex): array
    {
        [$red, $green, $blue] = array_map(fn ($offset): float => hexdec(substr($hex, $offset, 2)) / 255, [1, 3, 5]);
        $maximum = max($red, $green, $blue);
        $minimum = min($red, $green, $blue);
        $delta = $maximum - $minimum;
        $lightness = ($maximum + $minimum) / 2;
        if ($delta == 0) {
            return [0.0, 0.0, $lightness];
        }
        $hue = match ($maximum) {
            $red => fmod(($green - $blue) / $delta, 6),
            $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        };

        return [fmod($hue * 60 + 360, 360), $delta / (1 - abs(2 * $lightness - 1)), $lightness];
    }

    private ?array $palette = null;

    private ?string $primaryColor = null;

    private ?array $colors = null;

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function isTenantRequest(): bool
    {
        return $this->tenantContext->hasTenant();
    }

    public function primaryColor(): string
    {
        if (! $this->isTenantRequest()) {
            return self::DEFAULT_PRIMARY;
        }

        if ($this->primaryColor !== null) {
            return $this->primaryColor;
        }

        return $this->primaryColor = $this->colors()['primary_color'];
    }

    /** @return array<string, string> */
    public function colors(): array
    {
        if (! $this->isTenantRequest()) {
            return self::DEFAULT_COLORS;
        }

        if ($this->colors !== null) {
            return $this->colors;
        }

        $configured = AppSetting::groupValues('theme');

        $colors = collect(self::DEFAULT_COLORS)
            ->mapWithKeys(fn (string $default, string $key): array => [
                $key => $this->normalize((string) $configured->get($key)) ?? $default,
            ])
            ->all();

        // Older variants stored only an accent change over the green surfaces.
        $legacyAccentOnly = $colors['primary_color'] !== self::DEFAULT_PRIMARY
            && collect($colors)->except(['primary_color', 'action_color'])->all()
                === collect(self::DEFAULT_COLORS)->except(['primary_color', 'action_color'])->all();
        if ($legacyAccentOnly) {
            $variant = self::variantColors($colors['primary_color']);
            if ($colors['action_color'] !== self::DEFAULT_PRIMARY) {
                $variant['action_color'] = $colors['action_color'];
            }
            $colors = $variant;
        }

        return $this->colors = $colors;
    }

    public function palette(?string $primary = null): array
    {
        if ($primary === null && $this->palette !== null) {
            return $this->palette;
        }

        $primary = $this->normalize($primary ?? $this->primaryColor()) ?? self::DEFAULT_PRIMARY;
        $rgb = $this->toRgb($primary);
        $lightAccent = $this->ensureContrast($rgb, [251, 250, 244]);
        $darkAccent = $this->ensureContrast($rgb, [4, 22, 11]);
        $foreground = $this->readableForeground($rgb);
        $result = [
            'primary' => $primary,
            'primary_rgb' => implode(' ', $rgb),
            'foreground' => $this->toHex($foreground),
            'light_accent' => $this->toHex($lightAccent),
            'dark_accent' => $this->toHex($darkAccent),
            'light_hover' => $this->toHex($this->mix($rgb, [0, 0, 0], 0.14)),
            'dark_hover' => $this->toHex($this->mix($rgb, [255, 255, 255], 0.14)),
            'light_soft' => $this->toHex($this->mix($rgb, [255, 255, 255], 0.86)),
            'dark_soft_rgb' => implode(' ', $rgb),
            'shades' => [
                50 => $this->toHex($this->mix($rgb, [255, 255, 255], 0.94)),
                100 => $this->toHex($this->mix($rgb, [255, 255, 255], 0.87)),
                200 => $this->toHex($this->mix($rgb, [255, 255, 255], 0.74)),
                300 => $this->toHex($this->mix($rgb, [255, 255, 255], 0.54)),
                400 => $this->toHex($this->mix($rgb, [255, 255, 255], 0.28)),
                500 => $primary,
                600 => $this->toHex($this->mix($rgb, [0, 0, 0], 0.12)),
                700 => $this->toHex($this->mix($rgb, [0, 0, 0], 0.25)),
                800 => $this->toHex($this->mix($rgb, [0, 0, 0], 0.40)),
                900 => $this->toHex($this->mix($rgb, [0, 0, 0], 0.55)),
                950 => $this->toHex($this->mix($rgb, [0, 0, 0], 0.70)),
            ],
        ];

        if ($primary === $this->primaryColor()) {
            $this->palette = $result;
        }

        return $result;
    }

    public function canProduceReadablePalette(string $primary): bool
    {
        if ($this->normalize($primary) === null) {
            return false;
        }

        $palette = $this->palette($primary);

        return $this->contrast($this->toRgb($palette['primary']), $this->toRgb($palette['foreground'])) >= 4.5
            && $this->contrast($this->toRgb($palette['light_accent']), [251, 250, 244]) >= 4.5
            && $this->contrast($this->toRgb($palette['dark_accent']), [4, 22, 11]) >= 4.5;
    }

    /** @param array<string, string> $colors
     * @return array<string, string>
     */
    public function readabilityErrors(array $colors): array
    {
        $colors = array_replace(self::DEFAULT_COLORS, $colors);
        foreach ($colors as $key => $color) {
            if ($this->normalize($color) === null) {
                return [$key => __('theme.validation.format')];
            }
        }

        $errors = [];
        foreach (['light', 'dark'] as $appearance) {
            $textKey = $appearance.'_text_color';
            $text = $this->toRgb($colors[$textKey]);

            foreach ([$appearance.'_background_color', $appearance.'_surface_color'] as $surfaceKey) {
                if ($this->contrast($text, $this->toRgb($colors[$surfaceKey])) < 4.5) {
                    $errors[$textKey] = __('theme.validation.text_contrast', [
                        'appearance' => __('theme.'.$appearance.'_mode'),
                    ]);
                    break;
                }
            }
        }

        return $errors;
    }

    public function isCustom(): bool
    {
        return $this->colors() !== self::DEFAULT_COLORS;
    }

    public function cssVariables(): string
    {
        if (! $this->isCustom()) {
            // Keep the original Mudeer surfaces, opacity, controls and accents.
            // Generating supporting shades from eight base colours changes them.
            return <<<'CSS'
:root { --tenant-primary: #0b8f43; --tenant-primary-rgb: 11 143 67; --tenant-primary-hover: #087a39; --tenant-on-primary: #f5fff7; --tenant-action: #0b8f43; --tenant-action-hover: #087a39; --tenant-on-action: #f5fff7; --tenant-accent-text: #126d3b; --tenant-accent-soft: #d4e6d8; --color-accent: #0b8f43; --color-accent-content: #0b8f43; --color-accent-foreground: #f5fff7; --app-bg: #e8ebdf; --app-panel: #fbfaf4; --app-panel-soft: #e8eee2; --app-panel-strong: #fffdf7; --app-border: rgba(32, 91, 55, 0.26); --app-control-border: #8aa891; --app-text: #112b1c; --app-muted: #48614f; --app-control-background: #fffdf7; --app-page-gradient-start: #fbf7ef; --app-accent: #126d3b; --app-accent-soft: #d4e6d8; }
.dark { --tenant-accent-text: #0b8f43; --tenant-accent-soft: rgba(11, 143, 67, 0.16); --app-bg: #04160b; --app-panel: rgba(7, 39, 20, 0.92); --app-panel-soft: rgba(10, 49, 25, 0.86); --app-panel-strong: rgba(5, 28, 14, 0.98); --app-border: rgba(151, 219, 174, 0.14); --app-text: #f3fff6; --app-muted: #accdb5; --app-control-background: rgba(31, 24, 19, 0.88); --app-page-gradient-start: #072714; --app-accent: #0b8f43; --app-accent-soft: rgba(11, 143, 67, 0.16); }
CSS;
        }

        $colors = $this->colors();
        $declarations = [];
        $source = json_decode(file_get_contents(resource_path('themes/mudeer-palette.json')), true, flags: JSON_THROW_ON_ERROR);
        $bindings = array_flip(array_diff_key(self::DEFAULT_COLORS, array_flip(['primary_color', 'action_color'])));
        foreach ($source['colors'] as $hex => $definition) {
            foreach ($definition['roles'] as $role) {
                $primary = $colors[$role === 'action' ? 'action_color' : 'primary_color'];
                $value = self::variantColor($definition['hex'], $primary, $definition['neutral']);
                if ($role === 'brand' && isset($bindings[$definition['hex']])) {
                    $value = $colors[$bindings[$definition['hex']]];
                }
                $declarations[] = "--mudeer-{$role}-{$hex}: {$value};";
                $declarations[] = "--mudeer-{$role}-rgb-{$hex}: ".implode(', ', $this->toRgb($value)).';';
            }
        }
        $palette = $this->palette($colors['primary_color']);
        foreach ($this->palette($colors['action_color'])['shades'] as $shade => $value) {
            $declarations[] = "--theme-action-{$shade}: {$value};";
            $declarations[] = "--theme-on-action-{$shade}: ".$this->toHex($this->readableForeground($this->toRgb($value))).';';
        }
        $foreground = $this->toHex($this->readableForeground($this->toRgb($colors['action_color'])));
        $tokens = implode(' ', $declarations);
        $darkSurface = implode(', ', $this->toRgb($colors['dark_surface_color']));

        return ":root { {$tokens} --tenant-primary: {$colors['primary_color']}; --tenant-primary-rgb: {$palette['primary_rgb']}; --tenant-primary-hover: {$palette['light_hover']}; --tenant-on-primary: {$palette['foreground']}; --tenant-action: {$colors['action_color']}; --tenant-on-action: {$foreground}; --tenant-action-hover: {$palette['light_hover']}; --tenant-accent-text: {$palette['light_accent']}; --app-bg: {$colors['light_background_color']}; --app-panel: {$colors['light_surface_color']}; --app-text: {$colors['light_text_color']}; } .dark { --tenant-primary-hover: {$palette['dark_hover']}; --tenant-accent-text: {$palette['dark_accent']}; --app-bg: {$colors['dark_background_color']}; --app-panel: rgba({$darkSurface}, 0.92); --app-text: {$colors['dark_text_color']}; }";
    }

    private function normalize(string $color): ?string
    {
        $color = strtolower(trim($color));

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null;
    }

    private function ensureContrast(array $color, array $background): array
    {
        if ($this->contrast($color, $background) >= 4.5) {
            return $color;
        }

        $target = $this->relativeLuminance($background) > 0.5 ? [0, 0, 0] : [255, 255, 255];
        for ($amount = 0.05; $amount <= 1; $amount += 0.05) {
            $candidate = $this->mix($color, $target, $amount);
            if ($this->contrast($candidate, $background) >= 4.5) {
                return $candidate;
            }
        }

        return $target;
    }

    private function readableForeground(array $background): array
    {
        $white = [255, 255, 255];
        $black = [0, 0, 0];

        return $this->contrast($background, $white) >= $this->contrast($background, $black) ? $white : $black;
    }

    private function contrast(array $first, array $second): float
    {
        $lighter = max($this->relativeLuminance($first), $this->relativeLuminance($second));
        $darker = min($this->relativeLuminance($first), $this->relativeLuminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(array $rgb): float
    {
        $channels = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function mix(array $color, array $target, float $amount): array
    {
        return array_map(
            static fn (int $channel, int $targetChannel): int => (int) round($channel + ($targetChannel - $channel) * $amount),
            $color,
            $target,
        );
    }

    private function toRgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...$rgb);
    }
}
