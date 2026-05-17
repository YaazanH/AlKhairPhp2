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
        $type = in_array(($element['type'] ?? null), ['custom_text', 'dynamic_text', 'dynamic_image', 'barcode', 'shape', 'date_text', 'page_number'], true)
            ? $element['type']
            : 'custom_text';

        $source = $element['source'] ?? null;
        $field = $element['field'] ?? null;

        if (in_array($type, ['dynamic_text', 'dynamic_image', 'barcode'], true) && (! $source || ! $field)) {
            $first = $fieldRegistry->firstFieldFor($type, $source);
            $source = $first['source'] ?? null;
            $field = $first['field'] ?? null;
        }

        if (in_array($type, ['dynamic_text', 'dynamic_image', 'barcode'], true) && (! $source || ! $field)) {
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
        $shapeType = in_array(($styling['shape_type'] ?? 'rectangle'), ['rectangle', 'circle', 'triangle'], true)
            ? (string) ($styling['shape_type'] ?? 'rectangle')
            : 'rectangle';
        $dateMode = in_array(($styling['date_mode'] ?? 'today'), ['today', 'custom'], true)
            ? (string) ($styling['date_mode'] ?? 'today')
            : 'today';

        $defaultContent = match ($type) {
            'date_text' => __('print_templates.builder.defaults.date_content'),
            'page_number' => __('print_templates.builder.defaults.page_number_content'),
            default => __('print_templates.builder.defaults.custom_text'),
        };
        $defaultWidth = match ($type) {
            'dynamic_image' => 22,
            'barcode' => 50,
            'shape' => 18,
            default => 45,
        };
        $defaultHeight = match ($type) {
            'dynamic_image' => 28,
            'barcode' => 14,
            'shape' => 18,
            default => 10,
        };

        return [
            'id' => (string) ($element['id'] ?? Str::uuid()),
            'type' => $type,
            'source' => in_array($type, ['dynamic_text', 'dynamic_image', 'barcode'], true) ? $source : null,
            'field' => in_array($type, ['dynamic_text', 'dynamic_image', 'barcode'], true) ? $field : null,
            'content' => (string) ($element['content'] ?? $defaultContent),
            'x' => $this->float($element['x'] ?? 5, 0, 500),
            'y' => $this->float($element['y'] ?? ($index * 8) + 5, 0, 500),
            'width' => $this->float($element['width'] ?? $defaultWidth, 4, 500),
            'height' => $this->float($element['height'] ?? $defaultHeight, 4, 500),
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
                'shape_type' => $shapeType,
                'fill_opacity' => $this->float($styling['fill_opacity'] ?? 0.18, 0, 1),
                'date_mode' => $dateMode,
                'custom_date' => blank($styling['custom_date'] ?? null) ? null : (string) $styling['custom_date'],
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
