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

        return $this->colors = collect(self::DEFAULT_COLORS)
            ->mapWithKeys(fn (string $default, string $key): array => [
                $key => $this->normalize((string) $configured->get($key)) ?? $default,
            ])
            ->all();
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

    public function cssVariables(): string
    {
        $colors = $this->colors();
        $palette = $this->palette($colors['primary_color']);
        $actionRgb = $this->toRgb($colors['action_color']);
        $actionForeground = $this->toHex($this->readableForeground($actionRgb));
        $actionHoverTarget = $actionForeground === '#000000' ? [255, 255, 255] : [0, 0, 0];
        $actionHover = $this->toHex($this->mix($actionRgb, $actionHoverTarget, 0.14));
        $light = $this->appearancePalette($colors, 'light');
        $dark = $this->appearancePalette($colors, 'dark');

        // Brand colour and semantic colours are deliberately separate. Replacing
        // Tailwind's emerald scale made success/status text inherit arbitrary
        // tenant shades that were not readable on their surrounding surfaces.
        return ":root { --tenant-primary: {$palette['primary']}; --tenant-primary-rgb: {$palette['primary_rgb']}; --tenant-primary-hover: {$palette['light_hover']}; --tenant-on-primary: {$palette['foreground']}; --tenant-action: {$colors['action_color']}; --tenant-action-hover: {$actionHover}; --tenant-on-action: {$actionForeground}; --tenant-accent-text: {$light['accent']}; --tenant-accent-soft: {$light['accent_soft']}; --color-accent: {$colors['action_color']}; --color-accent-content: {$light['accent']}; --color-accent-foreground: {$actionForeground}; {$this->appearanceCss($light)} } .dark { --tenant-primary-hover: {$palette['dark_hover']}; --tenant-action-hover: {$actionHover}; --tenant-accent-text: {$dark['accent']}; --tenant-accent-soft: {$dark['accent_soft']}; --color-accent: {$colors['action_color']}; --color-accent-content: {$dark['accent']}; --color-accent-foreground: {$actionForeground}; {$this->appearanceCss($dark)} }";
    }

    /** @param array<string, string> $colors
     * @return array<string, string>
     */
    private function appearancePalette(array $colors, string $appearance): array
    {
        $background = $this->toRgb($colors[$appearance.'_background_color']);
        $surface = $this->toRgb($colors[$appearance.'_surface_color']);
        $text = $this->toRgb($colors[$appearance.'_text_color']);
        $primary = $this->toRgb($colors['primary_color']);
        $muted = $this->mix($text, $background, 0.22);

        if ($this->contrast($muted, $background) < 4.5 || $this->contrast($muted, $surface) < 4.5) {
            $muted = $text;
        }

        return [
            'background' => $this->toHex($background),
            'surface' => $this->toHex($surface),
            'surface_soft' => $this->toHex($this->mix($surface, $background, 0.34)),
            'surface_strong' => $this->toHex($this->mix($surface, $text, 0.04)),
            'border' => $this->toHex($this->mix($text, $surface, 0.72)),
            'text' => $this->toHex($text),
            'muted' => $this->toHex($muted),
            'accent' => $this->toHex($this->ensureContrastOnSurfaces($primary, [$background, $surface], $text)),
            'accent_soft' => $this->toHex($this->mix($primary, $surface, 0.86)),
        ];
    }

    /** @param array<string, string> $palette */
    private function appearanceCss(array $palette): string
    {
        return "--app-bg: {$palette['background']}; --app-panel: {$palette['surface']}; --app-panel-soft: {$palette['surface_soft']}; --app-panel-strong: {$palette['surface_strong']}; --app-border: {$palette['border']}; --app-control-border: {$palette['border']}; --app-text: {$palette['text']}; --app-muted: {$palette['muted']}; --app-control-background: {$palette['surface']}; --app-page-gradient-start: {$palette['surface']}; --app-accent: {$palette['accent']}; --app-accent-soft: {$palette['accent_soft']};";
    }

    /** @param array<int, array<int, int>> $surfaces
     * @param  array<int, int>  $fallback
     * @return array<int, int>
     */
    private function ensureContrastOnSurfaces(array $color, array $surfaces, array $fallback): array
    {
        $isReadable = fn (array $candidate): bool => collect($surfaces)
            ->every(fn (array $surface): bool => $this->contrast($candidate, $surface) >= 4.5);

        if ($isReadable($color)) {
            return $color;
        }

        foreach ([[0, 0, 0], [255, 255, 255]] as $target) {
            for ($amount = 0.05; $amount <= 1; $amount += 0.05) {
                $candidate = $this->mix($color, $target, $amount);
                if ($isReadable($candidate)) {
                    return $candidate;
                }
            }
        }

        return $fallback;
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
