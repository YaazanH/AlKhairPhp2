<?php

namespace App\Services\PrintTemplates;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PrintTemplateLayoutService
{
    public function decode(?string $payload): array
    {
        if (blank($payload)) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function normalize(array $elements, PrintTemplateFieldRegistry $fieldRegistry): array
    {
        return collect($elements)
            ->filter(fn (mixed $element) => is_array($element))
            ->map(fn (array $element, int $index) => $this->normalizeElement($element, $index, $fieldRegistry))
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeElement(array $element, int $index, PrintTemplateFieldRegistry $fieldRegistry): ?array
    {
        $type = in_array(($element['type'] ?? null), ['custom_text', 'dynamic_text', 'dynamic_image', 'barcode'], true)
            ? $element['type']
            : 'custom_text';

        $source = $element['source'] ?? null;
        $field = $element['field'] ?? null;

        if ($type !== 'custom_text' && (! $source || ! $field)) {
            $first = $fieldRegistry->firstFieldFor($type, $source);
            $source = $first['source'] ?? null;
            $field = $first['field'] ?? null;
        }

        if ($type !== 'custom_text' && (! $source || ! $field)) {
            return null;
        }

        $styling = Arr::wrap($element['styling'] ?? []);
        $fontWeight = in_array(($styling['font_weight'] ?? '600'), ['400', '500', '600', '700', '800'], true)
            ? (string) ($styling['font_weight'] ?? '600')
            : '600';
        $textAlign = in_array(($styling['text_align'] ?? 'left'), ['left', 'center', 'right'], true)
            ? ($styling['text_align'] ?? 'left')
            : 'left';
        $objectFit = in_array(($styling['object_fit'] ?? 'cover'), ['contain', 'cover', 'fill'], true)
            ? ($styling['object_fit'] ?? 'cover')
            : 'cover';
        $barcodeFormat = in_array(($styling['barcode_format'] ?? 'code39'), ['code39', 'qrcode'], true)
            ? (string) ($styling['barcode_format'] ?? 'code39')
            : 'code39';

        return [
            'id' => (string) ($element['id'] ?? Str::uuid()),
            'type' => $type,
            'source' => $source,
            'field' => $field,
            'content' => (string) ($element['content'] ?? __('print_templates.builder.defaults.custom_text')),
            'x' => $this->float($element['x'] ?? 5, 0, 500),
            'y' => $this->float($element['y'] ?? ($index * 8) + 5, 0, 500),
            'width' => $this->float($element['width'] ?? ($type === 'dynamic_image' ? 22 : ($type === 'barcode' ? 50 : 45)), 4, 500),
            'height' => $this->float($element['height'] ?? ($type === 'dynamic_image' ? 28 : ($type === 'barcode' ? 14 : 10)), 4, 500),
            'z_index' => (int) $this->float($element['z_index'] ?? ($index + 1), 1, 99),
            'styling' => [
                'font_size' => $this->float($styling['font_size'] ?? 4.2, 1.5, 24),
                'font_weight' => $fontWeight,
                'color' => $this->hexColor($styling['color'] ?? '#102316'),
                'text_align' => $textAlign,
                'border_radius' => $this->float($styling['border_radius'] ?? 0, 0, 16),
                'object_fit' => $objectFit,
                'letter_spacing' => $this->float($styling['letter_spacing'] ?? 0, 0, 3),
                'show_text' => (bool) ($styling['show_text'] ?? true),
                'barcode_format' => $barcodeFormat,
                'line_height' => $this->float($styling['line_height'] ?? 1.2, 0.8, 2.5),
            ],
        ];
    }

    protected function float(mixed $value, float $min, float $max): float
    {
        return max($min, min((float) $value, $max));
    }

    protected function hexColor(mixed $value): string
    {
        $candidate = is_string($value) ? trim($value) : '#102316';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) ? strtolower($candidate) : '#102316';
    }
}
