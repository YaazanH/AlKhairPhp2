<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public int $year;
    public string $quarter = '';
    public string $ledger_cash_box_id = '';
    public string $ledger_currency_id = '';
    public string $ledger_date_from = '';
    public string $ledger_date_to = '';
    public string $ledger_template_id = '';

    public function mount(): void
    {
        $this->authorizePermission('finance.reports.view');

        $this->year = (int) now()->year;
        $this->quarter = (string) now()->quarter;
        $this->ledger_date_from = now()->startOfYear()->toDateString();
        $this->ledger_date_to = now()->toDateString();
        $this->ledger_template_id = (string) app(FinanceReportService::class)->defaultLedgerTemplate()->id;
        $this->selectDefaultLedgerCashBox();
    }

    public function updatedLedgerCashBoxId(): void
    {
        $this->selectDefaultLedgerCurrency();
    }

    public function with(): array
    {
        $financeService = app(FinanceService::class);

        return [
            'generatedReports' => FinanceGeneratedReport::query()
                ->where('report_type', 'ledger')
                ->with('generatedBy')
                ->latest()
                ->limit(12)
                ->get(),
            'ledgerCashBoxes' => $financeService->accessibleCashBoxes(auth()->user())->get(),
            'ledgerCurrencies' => $this->ledgerCurrencies(),
            'ledgerTemplates' => FinanceReportTemplate::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'report' => app(FinanceReportService::class)->report($this->year, $this->quarter !== '' ? (int) $this->quarter : null),
        ];
    }

    protected function selectDefaultLedgerCashBox(): void
    {
        $cashBox = app(FinanceService::class)->defaultCashBoxForUser(auth()->user());

        $this->ledger_cash_box_id = $cashBox ? (string) $cashBox->id : '';
        $this->selectDefaultLedgerCurrency();
    }

    protected function selectDefaultLedgerCurrency(): void
    {
        if ($this->ledger_cash_box_id === '') {
            $this->ledger_currency_id = '';

            return;
        }

        $currencies = $this->ledgerCurrencies();

        if ($this->ledger_currency_id !== '' && $currencies->contains('id', (int) $this->ledger_currency_id)) {
            return;
        }

        $this->ledger_currency_id = (string) ($currencies->first()?->id ?: '');
    }

    protected function ledgerCurrencies(): Collection
    {
        if ($this->ledger_cash_box_id === '') {
            return collect();
        }

        return app(FinanceService::class)
            ->currenciesForCashBox((int) $this->ledger_cash_box_id)
            ->get();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.reports.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.reports.subtitle') }}</p>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:min-w-[24rem]">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.year') }}</label>
                    <input wire:model.live="year" type="number" min="2000" max="2100" class="w-full rounded-xl px-4 py-3 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.quarter') }}</label>
                    <select wire:model.live="quarter" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('finance.options.all_year') }}</option>
                        <option value="1">Q1</option>
                        <option value="2">Q2</option>
                        <option value="3">Q3</option>
                        <option value="4">Q4</option>
                    </select>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-kpi-grid">
        <article class="stat-card"><div class="kpi-label">{{ __('finance.fields.income') }}</div><div class="metric-value mt-3">{{ app(FinanceService::class)->formatCurrencyAmount($report['summary']['income'], $report['summary']['local_currency']) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('finance.fields.expense') }}</div><div class="metric-value mt-3">{{ app(FinanceService::class)->formatCurrencyAmount($report['summary']['expense'], $report['summary']['local_currency']) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('finance.fields.net') }}</div><div class="metric-value mt-3">{{ app(FinanceService::class)->formatCurrencyAmount($report['summary']['net'], $report['summary']['local_currency']) }}</div></article>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.pending_pull_requests') }}</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.request') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.requester') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.pull_kind') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.amount') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($report['pending_pull_requests'] as $request)<tr><td class="px-5 py-3">{{ $request->request_no }}</td><td class="px-5 py-3">{{ $request->requestedBy?->name ?: '-' }}</td><td class="px-5 py-3">{{ $request->pullRequestKind?->name ?: '-' }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($request->requested_amount, $request->requestedCurrency) }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('finance.empty.no_pending_pull_requests') }}</td></tr>@endforelse</tbody></table></div>
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.cash_balances') }}</div><div class="admin-grid-meta__summary">{{ __('finance.reports.cash_balances_subtitle') }}</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.cash_box') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.currency') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.balance') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.local_equivalent') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($report['balances'] as $boxBalance)@foreach ($boxBalance['currencies'] as $row)<tr><td class="px-5 py-3">{{ $boxBalance['cash_box']->name }}</td><td class="px-5 py-3">{{ $row['currency']->code }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['balance'], $row['currency']) }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['local_equivalent'], $report['summary']['local_currency']) }}</td></tr>@endforeach@endforeach</tbody></table></div>
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.quarter_totals') }}</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.quarter') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.period') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.revenue_total') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.expense_total') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($report['quarter_totals'] as $quarter)<tr><td class="px-5 py-3">Q{{ $quarter['quarter'] }}</td><td class="px-5 py-3">{{ $quarter['start']->format('Y-m-d') }} - {{ $quarter['end']->format('Y-m-d') }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($quarter['income'], $report['summary']['local_currency']) }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($quarter['expense'], $report['summary']['local_currency']) }}</td></tr>@endforeach</tbody></table></div>
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.totals_by_currency') }}</div><div class="admin-grid-meta__summary">{{ __('finance.reports.totals_by_currency_subtitle') }}</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.currency') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.income') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.expense') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.net') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($report['summary_by_currency'] as $row)<tr><td class="px-5 py-3">{{ $row['currency']?->code }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['income'], $row['currency']) }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['expense'], $row['currency']) }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['net'], $row['currency']) }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('finance.empty.no_transactions') }}</td></tr>@endforelse</tbody></table></div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.category_totals') }}</div><div class="admin-grid-meta__summary">{{ __('finance.reports.category_totals_subtitle') }}</div></div></div>
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.income') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.expense') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.net') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($report['category_totals'] as $row)<tr><td class="px-5 py-3">{{ $row['category'] }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['income'], $report['summary']['local_currency']) }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['expense'], $report['summary']['local_currency']) }}</td><td class="px-5 py-3">{{ app(FinanceService::class)->formatCurrencyAmount($row['net'], $report['summary']['local_currency']) }}</td></tr>@endforeach</tbody></table></div>
    </section>

    @can('finance.reports.export')
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('finance.reports.generated_reports') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('finance.reports.generated_reports_subtitle') }}</div>
                </div>
                <span class="badge-soft">{{ number_format($generatedReports->count()) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left">{{ __('finance.fields.template') }}</th>
                            <th class="px-5 py-3 text-left">{{ __('finance.fields.period') }}</th>
                            <th class="px-5 py-3 text-left">{{ __('finance.fields.cash_box') }}</th>
                            <th class="px-5 py-3 text-left">{{ __('finance.common.currency') }}</th>
                            <th class="px-5 py-3 text-left">{{ __('finance.fields.user') }}</th>
                            <th class="px-5 py-3 text-left">{{ __('finance.fields.date') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @forelse ($generatedReports as $generatedReport)
                            @php
                                $savedTemplate = data_get($generatedReport->report_data, 'template.name', data_get($generatedReport->report_data, 'template.title', __('finance.report_templates.default_title')));
                                $savedStart = data_get($generatedReport->filters, 'date_from', data_get($generatedReport->report_data, 'start'));
                                $savedEnd = data_get($generatedReport->filters, 'date_to', data_get($generatedReport->report_data, 'end'));
                            @endphp
                            <tr>
                                <td class="px-5 py-3">
                                    <div class="font-medium text-white">{{ $savedTemplate }}</div>
                                    <div class="text-xs text-neutral-500">{{ data_get($generatedReport->report_data, 'template.title') }}</div>
                                </td>
                                <td class="px-5 py-3">{{ $savedStart }} - {{ $savedEnd }}</td>
                                <td class="px-5 py-3">{{ data_get($generatedReport->filters, 'cash_box_name', data_get($generatedReport->report_data, 'cash_box.name', '-')) }}</td>
                                <td class="px-5 py-3">{{ data_get($generatedReport->filters, 'currency_code', data_get($generatedReport->report_data, 'currency.code', '-')) }}</td>
                                <td class="px-5 py-3">{{ $generatedReport->generatedBy?->name ?: (data_get($generatedReport->report_data, 'issuer_name') ?: '-') }}</td>
                                <td class="px-5 py-3">{{ $generatedReport->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-3">
                                    <div class="admin-action-cluster admin-action-cluster--end">
                                        <a href="{{ route('finance.reports.generated.show', $generatedReport) }}" target="_blank" rel="noopener" class="pill-link pill-link--compact">{{ __('finance.reports.review_saved_report') }}</a>
                                        <a href="{{ route('finance.reports.generated.show', ['generatedReport' => $generatedReport, 'format' => 'xlsx']) }}" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.reports.export_saved_xlsx') }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('finance.empty.no_generated_reports') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endcan

    @can('finance.reports.export')
        @php
            $ledgerReady = $ledger_cash_box_id !== '' && $ledger_currency_id !== '' && $ledger_date_from !== '' && $ledger_date_to !== '';
            $ledgerQuery = [
                'cash_box_id' => $ledger_cash_box_id,
                'currency_id' => $ledger_currency_id,
                'date_from' => $ledger_date_from,
                'date_to' => $ledger_date_to,
                'template_id' => $ledger_template_id ?: null,
            ];
        @endphp
        <section class="surface-panel p-5 lg:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="eyebrow">{{ __('finance.reports.ledger_export') }}</div>
                    <h2 class="font-display mt-3 text-2xl text-white">{{ __('finance.reports.ledger_export_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-neutral-300">{{ __('finance.reports.ledger_export_subtitle') }}</p>
                </div>
                @can('finance.report-templates.manage')
                    <a href="{{ route('settings.finance.report-templates') }}" class="pill-link pill-link--compact">{{ __('finance.reports.manage_templates') }}</a>
                @endcan
            </div>

            <div class="mt-5 grid gap-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label>
                    <select wire:model.live="ledger_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        @forelse ($ledgerCashBoxes as $cashBox)
                            <option value="{{ $cashBox->id }}">{{ $cashBox->name }}</option>
                        @empty
                            <option value="">{{ __('finance.empty.no_cash_boxes') }}</option>
                        @endforelse
                    </select>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.common.currency') }}</label>
                        <select wire:model.live="ledger_currency_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            @forelse ($ledgerCurrencies as $currency)
                                <option value="{{ $currency->id }}">{{ $currency->code }} - {{ $currency->name }}</option>
                            @empty
                                <option value="">{{ __('finance.empty.no_cash_box_currencies') }}</option>
                            @endforelse
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.reports.template') }}</label>
                        <select wire:model.live="ledger_template_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            @foreach ($ledgerTemplates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}{{ $template->is_default ? ' - '.__('finance.report_templates.default') : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.from_date') }}</label>
                        <input wire:model.live="ledger_date_from" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.to_date') }}</label>
                        <input wire:model.live="ledger_date_to" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($ledgerReady)
                    <a href="{{ route('finance.reports.ledger.export', array_merge($ledgerQuery, ['format' => 'xlsx'])) }}" class="pill-link pill-link--accent">{{ __('finance.reports.export_ledger_xlsx') }}</a>
                    <a href="{{ route('finance.reports.ledger.export', array_merge($ledgerQuery, ['format' => 'pdf'])) }}" target="_blank" rel="noopener" class="pill-link">{{ __('finance.reports.export_ledger_pdf') }}</a>
                @else
                    <span class="pill-link opacity-60">{{ __('finance.reports.choose_box_currency_first') }}</span>
                @endif
            </div>
        </section>
    @endcan
</div>
