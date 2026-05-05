<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FinanceRequest;
use App\Models\PrintTemplate;
use App\Services\IdCards\IdCardPrintLayoutService;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class FinanceRequestPrintController extends Controller
{
    public function __invoke(FinanceRequest $financeRequest): View
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
            ->orderBy('name')
            ->get()
            ->filter(fn (PrintTemplate $template) => collect(app(PrintTemplateDataSourceService::class)->normalize($template->data_sources ?? []))
                ->contains(fn (array $source) => $source['entity'] === 'finance_request' && $source['mode'] === 'single'))
            ->values();

        $defaultTemplate = $this->defaultTemplateFor($financeRequest, $templates);

        if (! request()->boolean('choose') && $defaultTemplate) {
            return $this->previewWithTemplate($financeRequest, $defaultTemplate);
        }

        return view('print.finance-request', [
            'defaultTemplate' => $defaultTemplate,
            'defaults' => app(IdCardPrintLayoutService::class)->defaults(),
            'organization' => $this->organizationProfile(),
            'request' => $financeRequest,
            'templates' => $templates,
        ]);
    }

    protected function defaultTemplateFor(FinanceRequest $financeRequest, Collection $templates): ?PrintTemplate
    {
        $templateId = (int) (AppSetting::groupValues('finance')->get($this->defaultTemplateSettingKey($financeRequest->type)) ?: 0);

        if ($templateId <= 0) {
            return null;
        }

        return $templates->firstWhere('id', $templateId);
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
        $defaults = app(IdCardPrintLayoutService::class)->defaults();
        $contexts = collect([['finance_request' => $financeRequest]]);
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
            ->map(fn ($pageContexts) => collect($pageContexts)
                ->values()
                ->map(fn (array $context, int $index) => app(PrintTemplateRenderService::class)->render($template, $context, $index + 1))
                ->all())
            ->all();

        return view('print-templates.print.preview', [
            'backUrl' => route('finance.requests.print', ['financeRequest' => $financeRequest, 'choose' => 1]),
            'layout' => $layout,
            'pages' => $pages,
            'template' => $template,
            'totalItems' => $contexts->count(),
        ]);
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
}
