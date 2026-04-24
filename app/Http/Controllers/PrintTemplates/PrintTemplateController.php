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
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PrintTemplateController extends Controller
{
    public function __construct(
        protected PrintTemplateLayoutService $layoutService,
        protected PrintTemplateRenderService $renderService,
        protected PrintTemplateFieldRegistry $fieldRegistry,
        protected PrintTemplateDataSourceService $dataSourceService,
    ) {
    }

    public function index(): View
    {
        return view('print-templates.templates.index', [
            'templates' => PrintTemplate::query()->latest()->get(),
        ]);
    }

    public function create(): View
    {
        $template = new PrintTemplate([
            'name' => __('print_templates.templates.defaults.name'),
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'data_sources' => [
                ['key' => 'student', 'entity' => 'student', 'mode' => 'multiple'],
            ],
            'layout_json' => $this->defaultLayout(),
            'is_active' => true,
        ]);

        return view('print-templates.templates.form', $this->formPayload($template));
    }

    public function store(Request $request): RedirectResponse
    {
        $template = new PrintTemplate();
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

        $template->delete();

        return redirect()
            ->route('print-templates.templates.index')
            ->with('status', __('print_templates.templates.messages.deleted'));
    }

    protected function formPayload(PrintTemplate $template): array
    {
        return [
            'template' => $template,
            'entityOptions' => $this->fieldRegistry->entityOptions(),
            'fieldOptions' => $this->renderService->fieldOptions(),
            'samplePayloads' => $this->renderService->samplePayloads(),
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
            'layout_json' => ['nullable', 'string'],
            'data_sources_json' => ['nullable', 'string'],
            'background_image' => ['nullable', 'image', 'max:4096'],
            'remove_background_image' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $dataSources = $this->dataSourceService->normalize(
            $this->layoutService->decode($validated['data_sources_json'] ?? '[]')
        );

        $layout = $this->layoutService->normalize(
            $this->layoutService->decode($validated['layout_json'] ?? '[]'),
            $this->fieldRegistry,
        );

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
            'background_image' => $template->background_image,
            'data_sources' => $dataSources,
            'layout_json' => $layout,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
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
