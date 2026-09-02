<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FinanceRequest;
use App\Models\PrintPageSize;
use App\Models\PrintTemplate;
use App\Services\IdCards\IdCardPrintLayoutService;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Mpdf\Mpdf;

class FinanceRequestPrintController extends Controller
{
    public function __invoke(FinanceRequest $financeRequest): View|Response
    {
        abort_unless(
            match ($financeRequest->type) {
                'pull' => request()->user()?->can('finance.pull-requests.print'),
                'expense' => request()->user()?->can('finance.expense-requests.print'),
                default => request()->user()?->can('finance.revenue-requests.print'),
            },
            403,
        );

        abort_unless(in_array($financeRequest->status, [FinanceRequest::STATUS_ACCEPTED, FinanceRequest::STATUS_SETTLED], true), 404);

        $financeRequest->load(['activity', 'cashBox', 'category', 'invoice', 'pullRequestKind', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency']);

        $templates = PrintTemplate::query()
            ->where('is_active', true)
            ->where('is_student_card', false)
            ->orderBy('name')
            ->get()
            ->filter(fn (PrintTemplate $template) => collect(app(PrintTemplateDataSourceService::class)->normalize($template->data_sources ?? []))
                ->contains(fn (array $source) => in_array($source['entity'], $financeRequest->type === FinanceRequest::TYPE_PULL || $financeRequest->type === FinanceRequest::TYPE_EXPENSE ? ['finance_request'] : ['finance_request', 'revenue'], true) && $source['mode'] === 'single'))
            ->values();

        $defaultTemplate = $this->defaultTemplateFor($financeRequest, $templates);

        $isIncome = in_array($financeRequest->type, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true);

        if ($defaultTemplate && $isIncome && request()->boolean('pdf')) {
            return $this->pdfWithTemplate($financeRequest, $defaultTemplate);
        }

        if ($defaultTemplate && ($isIncome || ! request()->boolean('choose'))) {
            return $this->previewWithTemplate($financeRequest, $defaultTemplate);
        }

        return view('print.finance-request', [
            'defaultTemplate' => $defaultTemplate,
            'defaultPageSize' => $this->defaultPageSize(),
            'defaults' => $this->defaultPageSize()?->layoutConfig() ?? app(IdCardPrintLayoutService::class)->defaults(),
            'organization' => $this->organizationProfile(),
            'pageSizes' => PrintPageSize::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'request' => $financeRequest,
            'templates' => $templates,
        ]);
    }

    protected function defaultTemplateFor(FinanceRequest $financeRequest, Collection $templates): ?PrintTemplate
    {
        $templateId = (int) (AppSetting::groupValues('finance')->get($this->defaultTemplateSettingKey($financeRequest->type)) ?: 0);

        if ($templateId <= 0) {
            return in_array($financeRequest->type, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true)
                ? $templates->first()
                : null;
        }

        $configured = $templates->firstWhere('id', $templateId);

        if ($configured) {
            return $configured;
        }

        return in_array($financeRequest->type, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true)
            ? $templates->first()
            : null;
    }

    protected function defaultTemplateSettingKey(string $type): string
    {
        return match ($type) {
            FinanceRequest::TYPE_EXPENSE => 'default_expense_print_template_id',
            FinanceRequest::TYPE_REVENUE => 'default_revenue_print_template_id',
            FinanceRequest::TYPE_RETURN => 'default_return_print_template_id',
            default => 'default_pull_print_template_id',
        };
    }

    protected function previewWithTemplate(FinanceRequest $financeRequest, PrintTemplate $template): View
    {
        $payload = $this->renderedTemplatePayload($financeRequest, $template);

        return view('print-templates.print.preview', $payload + [
            'autoPrint' => request()->boolean('auto_print'),
            'backUrl' => route('finance.requests.print', ['financeRequest' => $financeRequest, 'choose' => 1]),
        ]);
    }

    protected function pdfWithTemplate(FinanceRequest $financeRequest, PrintTemplate $template): Response
    {
        $payload = $this->renderedTemplatePayload($financeRequest, $template);
        $config = $payload['layout']['config'];
        $mpdf = new Mpdf(PdfOptions::make([
            'autoLangToFont' => false,
            'autoScriptToLang' => false,
            'format' => [(float) $config['page_width_mm'], (float) $config['page_height_mm']],
            'margin_top' => 0,
            'margin_right' => 0,
            'margin_bottom' => 0,
            'margin_left' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]));
        $mpdf->autoLangToFont = false;
        $mpdf->autoScriptToLang = false;
        $mpdf->autoArabic = true;
        $mpdf->useSubstitutions = true;
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');
        $mpdf->WriteHTML(view('print-templates.print.pdf', $payload + [
            'pdfAssetResolver' => fn (?string $source): ?string => $this->pdfAssetSource($source),
        ])->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('exports.pdf.income_record'),
                $financeRequest->request_no ?: $financeRequest->id,
            ], 'income-record-'.$financeRequest->id.'.pdf'),
        ]);
    }

    protected function renderedTemplatePayload(FinanceRequest $financeRequest, PrintTemplate $template): array
    {
        $defaults = $template->printLayoutConfig();
        $contextKey = in_array($financeRequest->type, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true) ? 'revenue' : 'finance_request';
        $contexts = collect([[$contextKey => $financeRequest]]);
        $layout = app(IdCardPrintLayoutService::class)->paginateDimensions(
            $template->width_mm,
            $template->height_mm,
            $contexts,
            $defaults + ['copy_count' => 1],
            [
                'page_too_small' => __('print_templates.print.warnings.page_too_small'),
                'tight_fit' => __('print_templates.print.warnings.tight_fit'),
                'unused_space' => __('print_templates.print.warnings.unused_space'),
            ],
        );

        $pages = collect($layout['pages'])
            ->map(fn ($pageContexts, $pageIndex) => collect($pageContexts)
                ->values()
                ->map(fn (array $context, int $index) => app(PrintTemplateRenderService::class)->render($template, $context, $index + 1, $pageIndex + 1))
                ->all())
            ->all();

        return [
            'layout' => $layout,
            'pages' => $pages,
            'template' => $template,
            'totalItems' => $contexts->count(),
        ];
    }

    protected function pdfAssetSource(?string $source): ?string
    {
        if (blank($source) || str_starts_with($source, 'data:')) {
            return $source;
        }

        if (str_starts_with($source, '/storage/')) {
            $path = storage_path('app/public/'.ltrim(substr($source, strlen('/storage/')), '/'));

            return is_file($path) ? $path : $source;
        }

        if (str_starts_with($source, '/')) {
            $path = public_path(ltrim($source, '/'));

            return is_file($path) ? $path : $source;
        }

        return $source;
    }

    protected function organizationProfile(): array
    {
        $settings = AppSetting::query()
            ->where('group', 'general')
            ->whereIn('key', ['school_address', 'school_email', 'school_name', 'school_phone'])
            ->pluck('value', 'key');

        return [
            'address' => (string) ($settings['school_address'] ?? ''),
            'email' => (string) ($settings['school_email'] ?? ''),
            'name' => (string) ($settings['school_name'] ?? config('app.name', 'Alkhair')),
            'phone' => (string) ($settings['school_phone'] ?? ''),
        ];
    }

    protected function defaultPageSize(): ?PrintPageSize
    {
        return PrintPageSize::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->first()
            ?: PrintPageSize::query()->orderBy('id')->first();
    }
}
