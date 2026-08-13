<?php

namespace App\Services\PrintTemplates;

use App\Models\PrintTemplate;
use App\Services\IdCards\Code39SvgRenderer;
use App\Services\IdCards\QrCodeSvgRenderer;
use Illuminate\Support\Carbon;

class PrintTemplateRenderService
{
    public function __construct(
        protected PrintTemplateFieldRegistry $fieldRegistry,
        protected PrintTemplateLayoutService $layoutService,
        protected Code39SvgRenderer $barcodeRenderer,
        protected QrCodeSvgRenderer $qrCodeRenderer,
    ) {}

    public function render(PrintTemplate $template, array $context = [], int $copyNumber = 1, int $pageNumber = 1): array
    {
        $elements = collect($this->layoutService->normalize($template->layout_json ?? [], $this->fieldRegistry))
            ->map(fn (array $element) => $this->renderElement($template, $context, $element, $pageNumber))
            ->sortBy('z_index')
            ->values()
            ->all();

        return [
            'template' => $template,
            'context' => $context,
            'copy_number' => $copyNumber,
            'page_number' => $pageNumber,
            'elements' => $elements,
        ];
    }

    public function fieldOptions(): array
    {
        return [
            'dynamic_text' => $this->fieldRegistry->selectableFields('dynamic_text'),
            'dynamic_image' => $this->fieldRegistry->selectableFields('dynamic_image'),
            'barcode' => $this->fieldRegistry->selectableFields('barcode'),
        ];
    }

    public function samplePayloads(): array
    {
        return collect($this->fieldRegistry->definitions())
            ->mapWithKeys(function (array $fields, string $entity) {
                return [
                    $entity => collect($fields)
                        ->mapWithKeys(fn (array $definition, string $field) => [$field => __('print_templates.builder.sample_values.'.$field)])
                        ->all(),
                ];
            })
            ->all();
    }

    protected function renderElement(PrintTemplate $template, array $context, array $element, int $pageNumber): array
    {
        $maxWidth = max($template->width_mm - $element['x'], 4);
        $maxHeight = max($template->height_mm - $element['y'], 4);
        $element['width'] = min($element['width'], $maxWidth);
        $element['height'] = min($element['height'], $maxHeight);

        $value = match ($element['type']) {
            'custom_text' => $this->normalizedTextValue($this->fieldRegistry->replacePlaceholders($element['content'], $context)),
            'date_text' => $this->normalizedTextValue($this->replaceRuntimeTokens($element['content'], [
                'date' => $this->resolvedDateValue($element),
            ])),
            'page_number' => $this->normalizedTextValue($this->replaceRuntimeTokens($element['content'], [
                'page_number' => (string) $pageNumber,
            ])),
            default => $this->fieldRegistry->resolve($context, $element['source'], $element['field']),
        };

        if (
            in_array($element['type'], ['custom_text', 'dynamic_text', 'date_text', 'page_number'], true)
            && ($element['styling']['text_align'] ?? null) === 'justify'
            && is_scalar($value)
        ) {
            $value = $this->addArabicKashidas((string) $value);
        }

        return match ($element['type']) {
            'dynamic_image' => $element + [
                'resolved' => [
                    'src' => is_string($value) ? $value : null,
                    'fallback' => __('print_templates.renderer.missing_image'),
                    'alt' => __('print_templates.renderer.image_alt'),
                ],
            ],
            'static_image' => $element + [
                'resolved' => [
                    'src' => $this->staticImageUrl($element['content'] ?? null),
                    'fallback' => __('print_templates.renderer.missing_image'),
                    'alt' => __('print_templates.renderer.image_alt'),
                ],
            ],
            'barcode' => $element + [
                'resolved' => [
                    'value' => (string) $value,
                    'format' => $element['styling']['barcode_format'] ?? 'code39',
                    'svg' => $this->renderBarcode((string) $value, $element),
                ],
            ],
            'shape' => $element + [
                'resolved' => [
                    'fill' => $element['styling']['color'],
                    'opacity' => $element['styling']['fill_opacity'],
                    'shape_type' => $element['styling']['shape_type'],
                ],
            ],
            default => $element + [
                'resolved' => [
                    'value' => is_scalar($value) ? (string) $value : __('print_templates.common.not_available'),
                ],
            ],
        };
    }

    protected function resolvedDateValue(array $element): string
    {
        $customDate = $element['styling']['custom_date'] ?? null;

        if (($element['styling']['date_mode'] ?? 'today') === 'custom' && $customDate) {
            return Carbon::parse($customDate)->format('Y-m-d');
        }

        return now()->format('Y-m-d');
    }

    protected function replaceRuntimeTokens(string $content, array $tokens): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function (array $matches) use ($tokens) {
            $key = $matches[1];

            return $tokens[$key] ?? $matches[0];
        }, $content) ?? $content;
    }

    protected function normalizedTextValue(string $value): string
    {
        $value = preg_replace("/^\R+/u", '', $value) ?? $value;

        return preg_replace("/^\h+/mu", '', $value) ?? $value;
    }

    protected function addArabicKashidas(string $value): string
    {
        if (! preg_match('/\p{Arabic}/u', $value)) {
            return $value;
        }

        // Stretch only Arabic letters that connect to the following letter. This
        // keeps justified headings decorative without inserting visible spaces.
        return preg_replace(
            '/([\x{0626}\x{0628}\x{062A}-\x{062E}\x{0633}-\x{063A}\x{0641}-\x{0647}\x{064A}])(?=[\x{0622}-\x{064A}])/u',
            '$1ـ',
            $value,
        ) ?? $value;
    }

    protected function renderBarcode(string $value, array $element): ?string
    {
        $options = [
            'width' => $element['width'],
            'height' => $element['height'],
            'font_size' => min($element['styling']['font_size'], 3.2),
            'show_text' => $element['styling']['show_text'],
        ];

        return ($element['styling']['barcode_format'] ?? 'code39') === 'qrcode'
            ? $this->qrCodeRenderer->render($value, $options)
            : $this->barcodeRenderer->render($value, $options);
    }

    protected function staticImageUrl(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (str_starts_with($value, '/') || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return '/storage/'.ltrim($value, '/');
    }
}
