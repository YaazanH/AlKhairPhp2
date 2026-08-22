<?php

namespace App\Http\Controllers\PrintTemplates;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FinanceRequest;
use App\Models\Group;
use App\Models\PrintTemplate;
use App\Models\StudentCardPrint;
use App\Services\IdCards\IdCardPrintLayoutService;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PrintTemplatePrintController extends Controller
{
    public function __construct(
        protected IdCardPrintLayoutService $printLayoutService,
        protected PrintTemplateRenderService $renderService,
        protected PrintTemplateFieldRegistry $fieldRegistry,
        protected PrintTemplateDataSourceService $dataSourceService,
    ) {}

    public function create(): View
    {
        return $this->buildSetupView(false);
    }

    public function createStudentCards(): View
    {
        return $this->buildSetupView(true);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        return $this->buildPreview($request, false);
    }

    public function previewStudentCards(Request $request): View|RedirectResponse
    {
        return $this->buildPreview($request, true);
    }

    public function recordStudentCardPrints(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('id-cards.print'), 403);

        $validated = $this->validateStudentCardPrintRequest($request);

        $template = PrintTemplate::query()->findOrFail($validated['template_id']);
        abort_unless($template->is_student_card && $this->hasStudentRepeatingSource($template), 404);

        $printedAt = Carbon::now();

        StudentCardPrint::query()->insert(
            collect($validated['student_ids'])
                ->map(fn (mixed $studentId) => (int) $studentId)
                ->filter(fn (int $studentId) => $studentId > 0)
                ->unique()
                ->values()
                ->map(fn (int $studentId) => [
                    'student_id' => $studentId,
                    'course_id' => $validated['course_id'],
                    'print_template_id' => $template->id,
                    'printed_by' => $request->user()?->id,
                    'printed_at' => $printedAt,
                    'created_at' => $printedAt,
                    'updated_at' => $printedAt,
                ])
                ->all()
        );

        return response()->json([
            'recorded' => true,
            'printed_at' => $printedAt->toIso8601String(),
        ]);
    }

    public function clearStudentCardPrints(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('id-cards.print'), 403);

        $validated = $this->validateStudentCardPrintRequest($request);

        $template = PrintTemplate::query()->findOrFail($validated['template_id']);
        abort_unless($template->is_student_card && $this->hasStudentRepeatingSource($template), 404);

        $studentIds = collect($validated['student_ids'])
            ->map(fn (mixed $studentId) => (int) $studentId)
            ->filter(fn (int $studentId) => $studentId > 0)
            ->unique()
            ->values();

        StudentCardPrint::query()
            ->whereIn('student_id', $studentIds)
            ->where('course_id', $validated['course_id'])
            ->delete();

        return response()->json([
            'cleared' => true,
            'student_ids' => $studentIds->all(),
        ]);
    }

    protected function authorizeFinanceRequestPrint(Request $request, array $sources): void
    {
        $financeSource = collect($sources)
            ->first(fn (array $source) => in_array($source['entity'], ['finance_request', 'revenue'], true) && $source['mode'] === 'single');

        abort_unless($financeSource, 403);

        $financeRequest = FinanceRequest::query()->findOrFail((int) $request->input('sources.'.$financeSource['entity'].'.single'));

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

    protected function validateStudentCardPrintRequest(Request $request): array
    {
        $validated = $request->validate([
            'template_id' => ['required', 'exists:print_templates,id'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ]);

        $validated['course_id'] = $validated['course_id']
            ?? Course::query()->where('is_default', true)->where('is_active', true)->value('id');

        return $validated;
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

                if ($entity === 'course_student') {
                    $notes = (array) $request->input('special_notes', []);
                    foreach ($models as $model) {
                        $model->setAttribute('report_card_special_note', trim((string) ($notes[$model->getKey()] ?? '')));
                    }
                }

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

        foreach ($fixedContext as $sourceEntity => $sourceModel) {
            foreach ($fixedContext as $targetEntity => $targetModel) {
                if ($sourceEntity === $targetEntity) {
                    continue;
                }

                if (! $this->fieldRegistry->modelsAreRelated($sourceEntity, $sourceModel, $targetEntity, $targetModel)) {
                    return back()
                        ->withErrors(['sources' => __('print_templates.print.errors.select_related_records')])
                        ->withInput();
                }
            }
        }

        if (! $repeatingSource) {
            return collect([$fixedContext]);
        }

        foreach ($fixedContext as $sourceEntity => $sourceModel) {
            $repeatingModels = $repeatingModels
                ->filter(fn ($model) => $this->fieldRegistry->modelsAreRelated($sourceEntity, $sourceModel, $repeatingSource['entity'], $model))
                ->values();
        }

        if ($repeatingModels->isEmpty()) {
            return back()
                ->withErrors(["sources.{$repeatingSource['entity']}.multiple" => __('print_templates.print.errors.select_related_repeating', ['entity' => $this->fieldRegistry->entities()[$repeatingSource['entity']]['label']])])
                ->withInput();
        }

        return $repeatingModels
            ->map(fn ($model) => $fixedContext + [$repeatingSource['entity'] => $model])
            ->values();
    }

    protected function buildSetupView(bool $studentCardMode): View
    {
        $courseId = request()->integer('course_id') ?: Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $courseReportMode = ! $studentCardMode && request()->filled('course_id');
        $courseStudentIds = $studentCardMode && $courseId
            ? Enrollment::query()
                ->where('status', 'active')
                ->whereHas('group', fn ($query) => $query->where('course_id', $courseId)->where('is_active', true))
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
            : collect();
        $entities = collect($this->fieldRegistry->entities())
            ->mapWithKeys(fn (array $definition, string $entity) => [
                $entity => [
                    'label' => $definition['label'],
                    'options' => collect($this->fieldRegistry->optionsFor($entity))
                        ->when($courseId && $entity === 'course_student', fn ($options) => $options->where('meta.course_id', $courseId))
                        ->when($studentCardMode && $entity === 'student', fn ($options) => $options
                            ->where('meta.status', 'active')
                            ->filter(fn (array $option) => $courseStudentIds->contains((int) $option['id'])))
                        ->when($studentCardMode && $courseId && in_array($entity, ['student', 'course_student'], true), function ($options) use ($courseId) {
                            $printedStudentIds = StudentCardPrint::query()->where('course_id', $courseId)->pluck('student_id')->map(fn ($id) => (int) $id);

                            return $options->map(function (array $option) use ($printedStudentIds): array {
                                $studentId = (int) ($option['meta']['student_ids'][0] ?? $option['id']);
                                $option['meta']['card_printed'] = $printedStudentIds->contains($studentId);

                                return $option;
                            });
                        })
                        ->values()->all(),
                ],
            ])
            ->all();

        $templates = PrintTemplate::query()
            ->where('is_active', true)
            ->where('is_student_card', $studentCardMode)
            ->when($courseReportMode, fn ($query) => $query->where('is_report_card', true))
            ->orderBy('name')
            ->get()
            ->filter(fn (PrintTemplate $template) => ! $studentCardMode || $this->hasStudentRepeatingSource($template))
            ->values();

        return view('print-templates.print.setup', [
            'cancelUrl' => route('print-templates.templates.index'),
            'defaults' => $templates->first()?->printLayoutConfig() ?? $this->printLayoutService->defaults(),
            'emptyStateCreateUrl' => route('print-templates.templates.create', ['student_card' => $studentCardMode ? 1 : 0]),
            'emptyStateDescription' => $studentCardMode
                ? __('print_templates.print.empty.student_cards_description')
                : __('print_templates.print.empty.templates_description'),
            'emptyStateTitle' => $studentCardMode
                ? __('print_templates.print.empty.student_cards_title')
                : __('print_templates.print.empty.templates_title'),
            'entities' => $entities,
            'courseReportMode' => $courseReportMode,
            'pageTitle' => $studentCardMode ? __('id_cards.print.title') : ($courseReportMode ? __('course_end.print_window_title') : __('print_templates.print.title')),
            'pageSubtitle' => $studentCardMode ? __('id_cards.print.subtitle') : ($courseReportMode ? '' : __('print_templates.print.subtitle')),
            'previewRoute' => $studentCardMode ? route('id-cards.print.preview') : route('print-templates.print.preview'),
            'studentCardMode' => $studentCardMode,
            'selectedCourseId' => $courseId,
            'activeCourses' => Course::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'studentFilters' => [
                'groups' => Group::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
            'templateConfigs' => $templates
                ->mapWithKeys(fn (PrintTemplate $template) => [
                    (string) $template->id => [
                        'sources' => $this->dataSourceService->normalize($template->data_sources ?? []),
                        'layout' => $template->printLayoutConfig(),
                        'paper_label' => __('print_templates.templates.form.paper_sizes.'.$template->paper_size).' · '.__('print_templates.templates.form.orientations.'.$template->orientation),
                    ],
                ])
                ->all(),
            'templates' => $templates,
        ]);
    }

    protected function buildPreview(Request $request, bool $studentCardMode): View|RedirectResponse
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
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ]);

        if ($studentCardMode) {
            $validated['course_id'] = $validated['course_id']
                ?? Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        }

        $template = PrintTemplate::query()->findOrFail($validated['template_id']);
        $validated = array_replace($validated, $template->printLayoutConfig());
        $sources = $this->dataSourceService->normalize($template->data_sources ?? []);

        abort_if($template->is_student_card !== $studentCardMode, 404);

        if ($studentCardMode && ! $this->hasStudentRepeatingSource($template)) {
            return back()
                ->withErrors(['template_id' => __('print_templates.print.errors.student_card_requires_students')])
                ->withInput();
        }

        if (collect($sources)->contains(fn (array $source) => in_array($source['entity'], ['finance_request', 'revenue'], true) && $source['mode'] === 'single')) {
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
                'page_too_small' => $studentCardMode ? __('id_cards.print.warnings.page_too_small') : __('print_templates.print.warnings.page_too_small'),
                'tight_fit' => $studentCardMode ? __('id_cards.print.warnings.tight_fit') : __('print_templates.print.warnings.tight_fit'),
                'unused_space' => $studentCardMode ? __('id_cards.print.warnings.unused_space') : __('print_templates.print.warnings.unused_space'),
            ],
        );

        $pages = collect($layout['pages'])
            ->map(fn ($pageContexts, $pageIndex) => collect($pageContexts)
                ->values()
                ->map(fn (array $context, int $index) => $this->renderService->render($template, $context, $index + 1, $pageIndex + 1))
                ->all())
            ->all();

        $studentIds = $studentCardMode
            ? $contexts
                ->map(fn (array $context) => $context['student']->id ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];

        return view('print-templates.print.preview', [
            'backButtonLabel' => $studentCardMode ? __('id_cards.print.preview.buttons.back') : __('print_templates.print.preview.buttons.back'),
            'backUrl' => $studentCardMode
                ? route('id-cards.print.create', ['template' => $template->id])
                : route('print-templates.print.create', ['template' => $template->id]),
            'layout' => $layout,
            'pageSubtitle' => $studentCardMode ? __('id_cards.print.preview.subtitle') : __('print_templates.print.preview.subtitle'),
            'pageTitle' => $studentCardMode ? __('id_cards.print.preview.title') : __('print_templates.print.preview.title'),
            'pages' => $pages,
            'printButtonLabel' => $studentCardMode ? __('id_cards.print.preview.buttons.print') : __('print_templates.print.preview.buttons.print'),
            'studentCardPrintTracking' => $studentCardMode && $studentIds !== [] ? [
                'route' => route('id-cards.print.record'),
                'student_ids' => $studentIds,
                'template_id' => $template->id,
                'course_id' => $validated['course_id'],
            ] : null,
            'summaryLabels' => $studentCardMode
                ? [
                    'template' => __('id_cards.print.preview.summary.template'),
                    'items' => __('id_cards.print.preview.summary.cards'),
                    'grid' => __('id_cards.print.preview.summary.grid'),
                    'page_size' => __('id_cards.print.preview.summary.page_size'),
                ]
                : [
                    'template' => __('print_templates.print.preview.summary.template'),
                    'items' => __('print_templates.print.preview.summary.items'),
                    'grid' => __('print_templates.print.preview.summary.grid'),
                    'page_size' => __('print_templates.print.preview.summary.page_size'),
                ],
            'template' => $template,
            'totalItems' => $contexts->count(),
        ]);
    }

    protected function hasStudentRepeatingSource(PrintTemplate $template): bool
    {
        return collect($this->dataSourceService->normalize($template->data_sources ?? []))
            ->contains(fn (array $source) => $source['entity'] === 'student' && $source['mode'] === 'multiple');
    }
}
