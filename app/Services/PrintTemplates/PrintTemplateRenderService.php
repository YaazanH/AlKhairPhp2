<?php

namespace App\Services\PrintTemplates;

use App\Models\PrintTemplate;
use App\Services\IdCards\Code39SvgRenderer;
use App\Services\IdCards\QrCodeSvgRenderer;
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;

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

    public function preparePdfPages(array $pages, Mpdf $mpdf): array
    {
        foreach ($pages as &$page) {
            foreach ($page as &$item) {
                foreach ($item['elements'] as &$element) {
                    if (
                        ! in_array($element['type'] ?? null, ['custom_text', 'dynamic_text', 'date_text', 'page_number'], true)
                        || ($element['styling']['text_align'] ?? null) !== 'justify'
                        || ! isset($element['resolved']['value'])
                    ) {
                        continue;
                    }

                    $element['resolved']['value'] = $this->addMeasuredArabicKashidas(
                        preg_replace('/\x{0640}+/u', '', (string) $element['resolved']['value']) ?? (string) $element['resolved']['value'],
                        $element,
                        $mpdf,
                    );
                }
                unset($element);
            }
            unset($item);
        }
        unset($page);

        return $pages;
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
            $value = $this->addArabicKashidas((string) $value, $element);
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
            return Carbon::parse($customDate)->format('d-m-Y');
        }

        return now()->format('d-m-Y');
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

    protected function addArabicKashidas(string $value, array $element): string
    {
        if (! preg_match('/\p{Arabic}/u', $value)) {
            return $value;
        }

        $fontSize = max((float) ($element['styling']['font_size'] ?? 4.2), 1.5);
        $lineCapacity = max((float) ($element['width'] ?? 4) / ($fontSize * 0.55), 1);

        return preg_replace_callback('/[^\r\n]+/u', function (array $line) use ($lineCapacity) {
            $text = $line[0];
            preg_match_all('/[\x{0621}-\x{064A}]/u', $text, $letters);
            preg_match_all('/\s/u', $text, $spaces);
            $visualLength = count($letters[0]) + (count($spaces[0]) * 0.45);

            if ($visualLength >= ($lineCapacity * 0.98)) {
                return $text;
            }

            preg_match_all(
                '/([\x{0626}\x{0628}\x{062A}-\x{062E}\x{0633}-\x{063A}\x{0641}-\x{0647}\x{0649}\x{064A}])(?=[\x{0622}-\x{064A}])/u',
                $text,
                $joins,
                PREG_OFFSET_CAPTURE,
            );

            if ($joins[0] === []) {
                return $text;
            }

            // In Dubai, a tatweel is about 0.08em wide while the average Arabic
            // glyph estimate above is 0.55em. Use that real ratio so the
            // browser preview visibly fills the selected width too.
            $estimatedGap = $lineCapacity - $visualLength;
            $needed = min(count($joins[0]) * 64, 640, max(1, (int) ceil($estimatedGap / (0.08 / 0.55))));
            $positions = collect($joins[0])
                ->map(fn (array $match, int $index) => [
                    'position' => $match[1] + strlen($match[0]),
                    'score' => $this->kashidaJoinScore($match[0], $index, count($joins[0])),
                ])
                ->sortByDesc('score')
                ->pluck('position')
                ->values()
                ->all();
            $insertions = [];
            for ($index = 0; $index < $needed; $index++) {
                $position = $positions[$index % count($positions)];
                $insertions[$position] = ($insertions[$position] ?? 0) + 1;
            }

            krsort($insertions);
            foreach ($insertions as $position => $count) {
                $text = substr($text, 0, $position).str_repeat("\u{0640}", $count).substr($text, $position);
            }

            return $text;
        }, $value) ?? $value;
    }

    protected function addMeasuredArabicKashidas(string $value, array $element, Mpdf $mpdf): string
    {
        if (! preg_match('/\p{Arabic}/u', $value)) {
            return $value;
        }

        $fontSizeMm = max((float) ($element['styling']['font_size'] ?? 4.2), 1.5);
        $fontWeight = (int) ($element['styling']['font_weight'] ?? 400);
        $targetWidth = max((float) ($element['width'] ?? 4) - 0.75, 1);
        $mpdf->SetFont('dubai', $fontWeight >= 600 ? 'B' : '', $fontSizeMm * 72 / 25.4);

        $parts = preg_split('/(\R)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];

        return collect($parts)->map(function (string $text) use ($mpdf, $targetWidth) {
            if (preg_match('/^\R$/u', $text) || ! preg_match('/\p{Arabic}/u', $text)) {
                return $text;
            }

            $baseWidth = $mpdf->GetStringWidth($text);
            if ($baseWidth >= $targetWidth) {
                return $text;
            }

            $positions = $this->arabicKashidaPositions($text);
            if ($positions === []) {
                return $text;
            }

            $maximum = min(count($positions) * 64, 640);
            $low = 0;
            $high = $maximum;
            $best = $text;

            while ($low <= $high) {
                $count = intdiv($low + $high, 2);
                $candidate = $this->insertDistributedKashidas($text, $positions, $count);

                if ($mpdf->GetStringWidth($candidate) <= $targetWidth) {
                    $best = $candidate;
                    $low = $count + 1;
                } else {
                    $high = $count - 1;
                }
            }

            return $best;
        })->implode('');
    }

    protected function arabicKashidaPositions(string $text): array
    {
        preg_match_all(
            '/([\x{0626}\x{0628}\x{062A}-\x{062E}\x{0633}-\x{063A}\x{0641}-\x{0647}\x{0649}\x{064A}])(?=[\x{0622}-\x{064A}])/u',
            $text,
            $joins,
            PREG_OFFSET_CAPTURE,
        );

        return collect($joins[0] ?? [])
            ->map(fn (array $match, int $index) => [
                'position' => $match[1] + strlen($match[0]),
                'score' => $this->kashidaJoinScore($match[0], $index, count($joins[0])),
            ])
            ->sortByDesc('score')
            ->pluck('position')
            ->values()
            ->all();
    }

    protected function insertDistributedKashidas(string $text, array $positions, int $count): string
    {
        $insertions = [];

        for ($index = 0; $index < $count; $index++) {
            $position = $positions[$index % count($positions)];
            $insertions[$position] = ($insertions[$position] ?? 0) + 1;
        }

        krsort($insertions);
        foreach ($insertions as $position => $amount) {
            $text = substr($text, 0, $position).str_repeat("\u{0640}", $amount).substr($text, $position);
        }

        return $text;
    }

    protected function kashidaJoinScore(string $letter, int $index, int $total): float
    {
        $stylisticPriority = match (true) {
            preg_match('/[\x{0633}-\x{063A}\x{0635}\x{0636}]/u', $letter) === 1 => 3,
            preg_match('/[\x{0637}-\x{063A}\x{0641}\x{0642}]/u', $letter) === 1 => 2,
            preg_match('/[\x{0628}\x{062A}-\x{062E}\x{0643}-\x{0646}\x{064A}]/u', $letter) === 1 => 1,
            default => 0,
        };
        $middleDistance = abs(($index + 0.5) - ($total / 2));

        return ($stylisticPriority * 100) - $middleDistance + ($index / 1000);
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
