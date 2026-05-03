<?php

namespace App\Http\Controllers\PrintTemplates;

use App\Http\Controllers\Controller;
use App\Models\FinanceRequest;
use App\Models\PrintTemplate;
use App\Services\IdCards\IdCardPrintLayoutService;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PrintTemplatePrintController extends Controller
{
    public function __construct(
        protected IdCardPrintLayoutService $printLayoutService,
        protected PrintTemplateRenderService $renderService,
        protected PrintTemplateFieldRegistry $fieldRegistry,
        protected PrintTemplateDataSourceService $dataSourceService,
    ) {
    }

    public function create(): View
    {
        $entities = collect($this->fieldRegistry->entities())
            ->mapWithKeys(fn (array $definition, string $entity) => [
                $entity => [
                    'label' => $definition['label'],
                    'options' => $this->fieldRegistry->optionsFor($entity),
                ],
            ])
            ->all();

        $templates = PrintTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('print-templates.print.setup', [
            'templates' => $templates,
            'templateConfigs' => $templates
                ->mapWithKeys(fn (PrintTemplate $template) => [
                    (string) $template->id => [
                        'sources' => $this->dataSourceService->normalize($template->data_sources ?? []),
                    ],
                ])
                ->all(),
            'entities' => $entities,
            'defaults' => $this->printLayoutService->defaults(),
        ]);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'exists:print_templates,id'],
            'copy_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'page_width_mm' => ['required', 'numeric', 'min:80', 'max:500'],
            'page_height_mm' => ['required', 'numeric', 'min:80', 'max:500'],
            'margin_top_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_right_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_bottom_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_left_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'gap_x_mm' => ['required', 'numeric', 'min:0', 'max:30'],
            'gap_y_mm' => ['required', 'numeric', 'min:0', 'max:30'],
        ]);

        $template = PrintTemplate::query()->findOrFail($validated['template_id']);
        $sources = $this->dataSourceService->normalize($template->data_sources ?? []);

        if (collect($sources)->contains(fn (array $source) => $source['entity'] === 'finance_request' && $source['mode'] === 'single')) {
            $this->authorizeFinanceRequestPrint($request, $sources);
        } else {
            abort_unless($request->user()?->can('id-cards.print'), 403);
        }

        $contexts = $this->contextsFromRequest($request, $sources, (int) ($validated['copy_count'] ?? 1));

        if ($contexts instanceof RedirectResponse) {
            return $contexts;
        }

        $layout = $this->printLayoutService->paginateDimensions(
            $template->width_mm,
            $template->height_mm,
            $contexts,
            $validated,
            [
                'page_too_small' => __('print_templates.print.warnings.page_too_small'),
                'tight_fit' => __('print_templates.print.warnings.tight_fit'),
                'unused_space' => __('print_templates.print.warnings.unused_space'),
            ],
        );

        $pages = collect($layout['pages'])
            ->map(fn ($pageContexts) => collect($pageContexts)
                ->values()
                ->map(fn (array $context, int $index) => $this->renderService->render($template, $context, $index + 1))
                ->all())
            ->all();

        return view('print-templates.print.preview', [
            'template' => $template,
            'pages' => $pages,
            'layout' => $layout,
            'totalItems' => $contexts->count(),
        ]);
    }

    protected function authorizeFinanceRequestPrint(Request $request, array $sources): void
    {
        abort_unless(
            collect($sources)->contains(fn (array $source) => $source['entity'] === 'finance_request' && $source['mode'] === 'single'),
            403,
        );

        $financeRequest = FinanceRequest::query()->findOrFail((int) $request->input('sources.finance_request.single'));

        abort_unless($financeRequest->status === FinanceRequest::STATUS_ACCEPTED, 403);

        abort_unless(
            match ($financeRequest->type) {
                FinanceRequest::TYPE_PULL => $request->user()?->can('finance.pull-requests.print'),
                FinanceRequest::TYPE_EXPENSE => $request->user()?->can('finance.expense-requests.print'),
                default => $request->user()?->can('finance.revenue-requests.print'),
            },
            403,
        );
    }

    protected function contextsFromRequest(Request $request, array $sources, int $copyCount): Collection|RedirectResponse
    {
        if ($sources === []) {
            return collect(range(1, $copyCount))->map(fn () => []);
        }

        $fixedContext = [];
        $repeatingSource = $this->dataSourceService->repeatingSource($sources);
        $repeatingModels = collect();

        foreach ($sources as $source) {
            $entity = $source['entity'];

            if ($source['mode'] === 'multiple') {
                $ids = array_values((array) $request->input("sources.{$entity}.multiple", []));
                $models = $this->fieldRegistry->findMany($entity, $ids);

                if ($models === []) {
                    return back()
                        ->withErrors(["sources.{$entity}.multiple" => __('print_templates.print.errors.select_repeating', ['entity' => $this->fieldRegistry->entities()[$entity]['label']])])
                        ->withInput();
                }

                $repeatingModels = collect($models);

                continue;
            }

            $id = (int) $request->input("sources.{$entity}.single", 0);
            $models = $this->fieldRegistry->findMany($entity, [$id]);

            if ($models === []) {
                return back()
                    ->withErrors(["sources.{$entity}.single" => __('print_templates.print.errors.select_single', ['entity' => $this->fieldRegistry->entities()[$entity]['label']])])
                    ->withInput();
            }

            $fixedContext[$entity] = $models[0];
        }

        if (! $repeatingSource) {
            return collect([$fixedContext]);
        }

        if ($repeatingSource['entity'] === 'student' && isset($fixedContext['parent'])) {
            $parentId = (int) $fixedContext['parent']->getKey();
            $repeatingModels = $repeatingModels
                ->filter(fn ($student) => (int) $student->parent_id === $parentId)
                ->values();

            if ($repeatingModels->isEmpty()) {
                return back()
                    ->withErrors(['sources.student.multiple' => __('print_templates.print.errors.select_related_students')])
                    ->withInput();
            }
        }

        return $repeatingModels
            ->map(fn ($model) => $fixedContext + [$repeatingSource['entity'] => $model])
            ->values();
    }
}
