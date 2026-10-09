<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\Landlord\TenantContext;

class TenantTheme
{
    public const DEFAULT_PRIMARY = '#0b8f43';

    private ?array $palette = null;

    private ?string $primaryColor = null;

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

        $configured = AppSetting::groupValues('theme')->get('primary_color');

        return $this->primaryColor = $this->normalize((string) $configured) ?? self::DEFAULT_PRIMARY;
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

    public function cssVariables(): string
    {
        $palette = $this->palette();

        // Brand colour and semantic colours are deliberately separate. Replacing
        // Tailwind's emerald scale made success/status text inherit arbitrary
        // tenant shades that were not readable on their surrounding surfaces.
        return ":root { --tenant-primary: {$palette['primary']}; --tenant-primary-rgb: {$palette['primary_rgb']}; --tenant-primary-hover: {$palette['light_hover']}; --tenant-on-primary: {$palette['foreground']}; --tenant-accent-text: {$palette['light_accent']}; --tenant-accent-soft: {$palette['light_soft']}; --color-accent: {$palette['primary']}; --color-accent-content: {$palette['light_accent']}; --color-accent-foreground: {$palette['foreground']}; --app-accent: {$palette['light_accent']}; --app-accent-soft: {$palette['light_soft']}; } .dark { --tenant-primary-hover: {$palette['dark_hover']}; --tenant-accent-text: {$palette['dark_accent']}; --tenant-accent-soft: rgb({$palette['dark_soft_rgb']} / 0.16); --color-accent: {$palette['primary']}; --color-accent-content: {$palette['dark_accent']}; --color-accent-foreground: {$palette['foreground']}; --app-accent: {$palette['dark_accent']}; --app-accent-soft: rgb({$palette['dark_soft_rgb']} / 0.16); }";
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
