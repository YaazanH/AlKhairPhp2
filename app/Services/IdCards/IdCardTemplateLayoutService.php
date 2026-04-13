<?php

namespace App\Services\IdCards;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class IdCardTemplateLayoutService
{
    public function decode(?string $payload): array
    {
        if (blank($payload)) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function normalize(array $elements, StudentCardFieldRegistry $fieldRegistry): array
    {
        return collect($elements)
            ->filter(fn (mixed $element) => is_array($element))
            ->map(fn (array $element, int $index) => $this->normalizeElement($element, $index, $fieldRegistry))
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeElement(array $element, int $index, StudentCardFieldRegistry $fieldRegistry): ?array
    {
        $type = in_array(($element['type'] ?? null), ['text', 'image', 'barcode'], true)
            ? $element['type']
            : 'text';

        $availableField = $fieldRegistry->firstFieldFor($type);
        $field = $element['field'] ?? $availableField;

        if (! $field) {
            return null;
        }

        $styling = Arr::wrap($element['styling'] ?? []);
        $fontWeight = in_array(($styling['font_weight'] ?? '600'), ['400', '500', '600', '700'], true)
            ? (string) ($styling['font_weight'] ?? '600')
            : '600';
        $textAlign = in_array(($styling['text_align'] ?? 'left'), ['left', 'center', 'right'], true)
            ? ($styling['text_align'] ?? 'left')
            : 'left';
        $objectFit = in_array(($styling['object_fit'] ?? 'cover'), ['contain', 'cover', 'fill'], true)
            ? ($styling['object_fit'] ?? 'cover')
            : 'cover';

        return [
            'id' => (string) ($element['id'] ?? Str::uuid()),
            'type' => $type,
            'field' => $field,
            'x' => $this->float($element['x'] ?? 5, 0, 500),
            'y' => $this->float($element['y'] ?? ($index * 5) + 5, 0, 500),
            'width' => $this->float($element['width'] ?? ($type === 'image' ? 22 : 30), 4, 500),
            'height' => $this->float($element['height'] ?? ($type === 'image' ? 28 : ($type === 'barcode' ? 12 : 8)), 4, 500),
            'z_index' => (int) $this->float($element['z_index'] ?? ($index + 1), 1, 99),
            'styling' => [
                'font_size' => $this->float($styling['font_size'] ?? 4.2, 2, 20),
                'font_weight' => $fontWeight,
                'color' => $this->hexColor($styling['color'] ?? '#102316'),
                'text_align' => $textAlign,
                'border_radius' => $this->float($styling['border_radius'] ?? 0, 0, 16),
                'object_fit' => $objectFit,
                'letter_spacing' => $this->float($styling['letter_spacing'] ?? 0, 0, 2),
                'show_text' => (bool) ($styling['show_text'] ?? true),
                'barcode_format' => 'code39',
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
