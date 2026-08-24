<?php

namespace App\Http\Controllers\PrintTemplates;

use App\Http\Controllers\Controller;
use App\Models\PrintTemplate;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use App\Services\PrintTemplates\PrintTemplateLayoutService;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PrintTemplateController extends Controller
{
    public function __construct(
        protected PrintTemplateLayoutService $layoutService,
        protected PrintTemplateRenderService $renderService,
        protected PrintTemplateFieldRegistry $fieldRegistry,
        protected PrintTemplateDataSourceService $dataSourceService,
    ) {}

    public function index(): View
    {
        return view('print-templates.templates.index', [
            'templates' => PrintTemplate::query()->latest()->get(),
        ]);
    }

    public function create(): View
    {
        $courseReport = request()->boolean('course_report');
        $studentCard = request()->boolean('student_card') && ! $courseReport;
        $template = new PrintTemplate([
            'name' => __('print_templates.templates.defaults.name'),
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'paper_size' => 'a4',
            'orientation' => 'portrait',
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
            'rounded_corners' => false,
            'data_sources' => $courseReport
                ? [['key' => 'course_student', 'entity' => 'course_student', 'mode' => 'multiple']]
                : [['key' => 'student', 'entity' => 'student', 'mode' => $studentCard ? 'multiple' : 'single']],
            'layout_json' => $this->defaultLayout(),
            'is_active' => true,
            'is_student_card' => $studentCard,
            'is_report_card' => $courseReport,
        ]);

        return view('print-templates.templates.form', $this->formPayload($template));
    }

    public function store(Request $request): RedirectResponse
    {
        $template = new PrintTemplate;
        $template->fill($this->validatedPayload($request, $template));
        $template->save();

        return redirect()
            ->route('print-templates.templates.edit', $template)
            ->with('status', __('print_templates.templates.messages.created'));
    }

    public function edit(PrintTemplate $template): View
    {
        return view('print-templates.templates.form', $this->formPayload($template));
    }

    public function update(Request $request, PrintTemplate $template): RedirectResponse
    {
        $template->fill($this->validatedPayload($request, $template));
        $template->save();

        return redirect()
            ->route('print-templates.templates.edit', $template)
            ->with('status', __('print_templates.templates.messages.updated'));
    }

    public function destroy(PrintTemplate $template): RedirectResponse
    {
        if ($template->background_image) {
            Storage::disk('public')->delete($template->background_image);
        }

        $this->staticImagePaths($template->layout_json ?? [])
            ->each(fn (string $path) => Storage::disk('public')->delete($path));

        $template->delete();

        return redirect()
            ->route('print-templates.templates.index')
            ->with('status', __('print_templates.templates.messages.deleted'));
    }

    public function copy(PrintTemplate $template): RedirectResponse
    {
        $duplicate = $template->replicate();
        $duplicate->name = $template->name.' '.__('print_templates.templates.copy_suffix');
        // A copied template is a draft variant, not a second report-card template.
        $duplicate->is_report_card = false;
        $duplicate->background_image = $this->duplicateStorageFile($template->background_image, 'print-templates/backgrounds');
        $duplicate->layout_json = $this->duplicateStaticImageLayout($template->layout_json ?? []);
        $duplicate->push();

        return redirect()
            ->route('print-templates.templates.edit', $duplicate)
            ->with('status', __('print_templates.templates.messages.copied'));
    }

    protected function formPayload(PrintTemplate $template): array
    {
        return [
            'template' => $template,
            'entityOptions' => $this->fieldRegistry->entityOptions(),
            'fieldOptions' => $this->renderService->fieldOptions(),
            'samplePayloads' => $this->renderService->samplePayloads(),
            'paperSizes' => PrintTemplate::paperSizes(),
            'dataSourcesJson' => json_encode($template->data_sources ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'layoutJson' => json_encode($template->layout_json ?: $this->defaultLayout(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];
    }

    protected function validatedPayload(Request $request, PrintTemplate $template): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'width_mm' => ['required', 'numeric', 'min:20', 'max:500'],
            'height_mm' => ['required', 'numeric', 'min:20', 'max:500'],
            'paper_size' => ['nullable', Rule::in(array_keys(PrintTemplate::paperSizes()))],
            'orientation' => ['nullable', Rule::in(['portrait', 'landscape'])],
            'margin_top_mm' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'margin_right_mm' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'margin_bottom_mm' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'margin_left_mm' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'gap_x_mm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'gap_y_mm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'rounded_corners' => ['nullable', 'boolean'],
            'layout_json' => ['nullable', 'string'],
            'data_sources_json' => ['nullable', 'string'],
            'background_image' => ['nullable', 'image', 'max:10240'],
            'static_images' => ['nullable', 'array'],
            'static_images.*' => ['nullable', 'image', 'max:4096'],
            'remove_background_image' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_student_card' => ['nullable', 'boolean'],
            'is_report_card' => ['nullable', 'boolean'],
        ]);

        $dataSources = $this->dataSourceService->normalize(
            $this->layoutService->decode($validated['data_sources_json'] ?? '[]')
        );

        $isStudentCard = (bool) ($validated['is_student_card'] ?? false);
        $isReportCard = (bool) ($validated['is_report_card'] ?? false);

        if ($isStudentCard && $isReportCard) {
            throw ValidationException::withMessages([
                'is_report_card' => __('print_templates.templates.validation.exclusive_card_types'),
            ]);
        }

        if ($isReportCard && PrintTemplate::query()->where('is_report_card', true)->when($template->exists, fn ($query) => $query->whereKeyNot($template->id))->exists()) {
            throw ValidationException::withMessages(['is_report_card' => __('print_templates.templates.validation.only_one_report_card')]);
        }

        if ($isStudentCard && ! collect($dataSources)->contains(fn (array $source) => $source['entity'] === 'student' && $source['mode'] === 'multiple')) {
            throw ValidationException::withMessages([
                'is_student_card' => __('print_templates.templates.validation.student_card_requires_students'),
            ]);
        }

        if ($isReportCard && ! collect($dataSources)->contains(fn (array $source) => $source['entity'] === 'course_student' && $source['mode'] === 'multiple')) {
            throw ValidationException::withMessages([
                'is_report_card' => __('print_templates.templates.validation.report_card_requires_course_students'),
            ]);
        }

        $decodedLayout = $this->applyStaticImageUploads(
            $request,
            $this->layoutService->decode($validated['layout_json'] ?? '[]'),
        );

        $layout = $this->layoutService->normalize($decodedLayout, $this->fieldRegistry);

        $this->staticImagePaths($template->layout_json ?? [])
            ->diff($this->staticImagePaths($layout))
            ->each(fn (string $path) => Storage::disk('public')->delete($path));

        if (($validated['remove_background_image'] ?? false) && $template->background_image) {
            Storage::disk('public')->delete($template->background_image);
            $template->background_image = null;
        }

        if ($request->hasFile('background_image')) {
            if ($template->background_image) {
                Storage::disk('public')->delete($template->background_image);
            }

            $template->background_image = $request->file('background_image')->store('print-templates/backgrounds', 'public');
        }

        return [
            'name' => $validated['name'],
            'width_mm' => (float) $validated['width_mm'],
            'height_mm' => (float) $validated['height_mm'],
            'paper_size' => $validated['paper_size'] ?? $template->paper_size ?? 'a4',
            'orientation' => $validated['orientation'] ?? $template->orientation ?? 'portrait',
            'margin_top_mm' => (float) ($validated['margin_top_mm'] ?? $template->margin_top_mm ?? 10),
            'margin_right_mm' => (float) ($validated['margin_right_mm'] ?? $template->margin_right_mm ?? 10),
            'margin_bottom_mm' => (float) ($validated['margin_bottom_mm'] ?? $template->margin_bottom_mm ?? 10),
            'margin_left_mm' => (float) ($validated['margin_left_mm'] ?? $template->margin_left_mm ?? 10),
            'gap_x_mm' => (float) ($validated['gap_x_mm'] ?? $template->gap_x_mm ?? 6),
            'gap_y_mm' => (float) ($validated['gap_y_mm'] ?? $template->gap_y_mm ?? 6),
            'rounded_corners' => (bool) ($validated['rounded_corners'] ?? false),
            'background_image' => $template->background_image,
            'data_sources' => $dataSources,
            'layout_json' => $layout,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'is_student_card' => $isStudentCard,
            'is_report_card' => $isReportCard,
        ];
    }

    protected function applyStaticImageUploads(Request $request, array $layout): array
    {
        return collect($layout)
            ->map(function (mixed $element) use ($request) {
                if (! is_array($element) || ($element['type'] ?? null) !== 'static_image') {
                    return $element;
                }

                $elementId = (string) ($element['id'] ?? '');

                if ($elementId !== '' && $request->hasFile("static_images.{$elementId}")) {
                    $element['content'] = $request->file("static_images.{$elementId}")
                        ->store('print-templates/elements', 'public');
                }

                return $element;
            })
            ->all();
    }

    protected function staticImagePaths(array $layout): Collection
    {
        return collect($layout)
            ->filter(fn (mixed $element) => is_array($element) && ($element['type'] ?? null) === 'static_image' && filled($element['content'] ?? null))
            ->map(fn (array $element) => (string) $element['content'])
            ->unique()
            ->values();
    }

    protected function duplicateStaticImageLayout(array $layout): array
    {
        $pathMap = [];

        return collect($layout)
            ->map(function (mixed $element) use (&$pathMap) {
                if (! is_array($element) || ($element['type'] ?? null) !== 'static_image' || blank($element['content'] ?? null)) {
                    return $element;
                }

                $currentPath = (string) $element['content'];

                if (! array_key_exists($currentPath, $pathMap)) {
                    $pathMap[$currentPath] = $this->duplicateStorageFile($currentPath, 'print-templates/elements');
                }

                $element['content'] = $pathMap[$currentPath];

                return $element;
            })
            ->all();
    }

    protected function duplicateStorageFile(?string $path, string $directory): ?string
    {
        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $duplicatePath = trim($directory, '/').'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');

        Storage::disk('public')->copy($path, $duplicatePath);

        return $duplicatePath;
    }

    protected function defaultLayout(): array
    {
        return [
            [
                'id' => 'title',
                'type' => 'custom_text',
                'content' => __('print_templates.builder.defaults.title_content'),
                'x' => 8,
                'y' => 8,
                'width' => 68,
                'height' => 10,
                'z_index' => 1,
                'styling' => [
                    'font_size' => 5.2,
                    'font_weight' => '800',
                    'color' => '#102316',
                    'text_align' => 'center',
                ],
            ],
            [
                'id' => 'student-name',
                'type' => 'dynamic_text',
                'source' => 'student',
                'field' => 'full_name',
                'x' => 8,
                'y' => 22,
                'width' => 68,
                'height' => 8,
                'z_index' => 2,
                'styling' => [
                    'font_size' => 4.2,
                    'font_weight' => '700',
                    'color' => '#102316',
                    'text_align' => 'center',
                ],
            ],
        ];
    }
}
