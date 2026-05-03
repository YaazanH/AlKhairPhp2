<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FinanceRequest;
use App\Models\PrintTemplate;
use App\Services\IdCards\IdCardPrintLayoutService;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use Illuminate\Contracts\View\View;

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

        abort_unless($financeRequest->status === FinanceRequest::STATUS_ACCEPTED, 404);

        $financeRequest->load(['activity', 'cashBox', 'category', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency']);

        $templates = PrintTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (PrintTemplate $template) => collect(app(PrintTemplateDataSourceService::class)->normalize($template->data_sources ?? []))
                ->contains(fn (array $source) => $source['entity'] === 'finance_request' && $source['mode'] === 'single'))
            ->values();

        return view('print.finance-request', [
            'defaults' => app(IdCardPrintLayoutService::class)->defaults(),
            'organization' => $this->organizationProfile(),
            'request' => $financeRequest,
            'templates' => $templates,
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
