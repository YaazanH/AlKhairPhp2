<?php

namespace App\Services\IdCards;

use App\Models\IdCardTemplate;
use App\Models\Student;

class IdCardRenderService
{
    public function __construct(
        protected StudentCardFieldRegistry $fieldRegistry,
        protected Code39SvgRenderer $barcodeRenderer,
        protected IdCardTemplateLayoutService $layoutService,
    ) {
    }

    public function render(IdCardTemplate $template, Student $student): array
    {
        $student->loadMissing(['gradeLevel', 'parentProfile', 'quranCurrentJuz', 'enrollments.group']);

        $elements = collect($this->layoutService->normalize($template->layout_json ?? [], $this->fieldRegistry))
            ->map(fn (array $element) => $this->renderElement($template, $student, $element))
            ->sortBy('z_index')
            ->values()
            ->all();

        return [
            'template' => $template,
            'student' => $student,
            'elements' => $elements,
        ];
    }

    public function samplePayload(Student $student): array
    {
        return $this->fieldRegistry->previewPayload($student);
    }

    public function fieldOptions(): array
    {
        return [
            'text' => $this->fieldRegistry->selectableFields('text'),
            'image' => $this->fieldRegistry->selectableFields('image'),
            'barcode' => $this->fieldRegistry->selectableFields('barcode'),
        ];
    }

    protected function renderElement(IdCardTemplate $template, Student $student, array $element): array
    {
        $maxWidth = max($template->width_mm - $element['x'], 4);
        $maxHeight = max($template->height_mm - $element['y'], 4);
        $element['width'] = min($element['width'], $maxWidth);
        $element['height'] = min($element['height'], $maxHeight);

        $value = $this->fieldRegistry->resolve($student, $element['field']);

        return match ($element['type']) {
            'image' => $element + [
                'resolved' => [
                    'src' => is_string($value) ? $value : null,
                    'fallback' => __('id_cards.renderer.missing_photo'),
                    'alt' => $student->full_name,
                ],
            ],
            'barcode' => $element + [
                'resolved' => [
                    'value' => (string) $value,
                    'svg' => $this->barcodeRenderer->render((string) $value, [
                        'width' => $element['width'],
                        'height' => $element['height'],
                        'font_size' => min($element['styling']['font_size'], 3.2),
                        'show_text' => $element['styling']['show_text'],
                    ]),
                ],
            ],
            default => $element + [
                'resolved' => [
                    'value' => is_scalar($value) ? (string) $value : __('id_cards.common.not_available'),
                ],
            ],
        };
    }
}
