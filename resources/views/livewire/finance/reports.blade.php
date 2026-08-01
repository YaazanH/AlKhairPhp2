<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public string $ledger_period_mode = 'quarter';
    public int $ledger_year;
    public string $ledger_quarter = '';
    public string $ledger_cash_box_id = '';
    public string $ledger_currency_id = '';
    public string $ledger_date_from = '';
    public string $ledger_date_to = '';
    public string $ledger_template_id = '';

    public function mount(): void
    {
        $this->authorizePermission('finance.reports.view');

        $this->ledger_year = (int) now()->year;
        $this->ledger_quarter = (string) now()->quarter;
        $this->syncLedgerQuarterDates();
        $this->ledger_template_id = (string) app(FinanceReportService::class)->defaultLedgerTemplate()->id;
        $this->selectDefaultLedgerCashBox();
    }

    public function updatedLedgerCashBoxId(): void
    {
        $this->selectDefaultLedgerCurrency();
    }

    public function updatedLedgerPeriodMode(): void
    {
        if ($this->ledger_period_mode === 'quarter') {
            $this->syncLedgerQuarterDates();
        }
    }

    public function updatedLedgerYear(): void
    {
        if ($this->ledger_period_mode === 'quarter') {
            $this->syncLedgerQuarterDates();
        }
    }

    public function updatedLedgerQuarter(): void
    {
        if ($this->ledger_period_mode === 'quarter') {
            $this->syncLedgerQuarterDates();
        }
    }

    public function with(): array
    {
        $financeService = app(FinanceService::class);
        $generatedReportsEnabled = FinanceGeneratedReport::storageIsReady();

        return [
            'generatedReportsEnabled' => $generatedReportsEnabled,
            'generatedReports' => $generatedReportsEnabled
                ? FinanceGeneratedReport::query()
                    ->where('report_type', 'ledger')
                    ->with('generatedBy')
                    ->latest()
                    ->limit(12)
                    ->get()
                : collect(),
            'ledgerCashBoxes' => $financeService->accessibleCashBoxes(auth()->user())->get(),
            'ledgerCurrencies' => $this->ledgerCurrencies(),
            'ledgerTemplates' => FinanceReportTemplate::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function deleteGeneratedReport(int $generatedReportId): void
    {
        $this->authorizePermission('finance.reports.export');

        if (! FinanceGeneratedReport::storageIsReady()) {
            return;
        }

        $generatedReport = FinanceGeneratedReport::query()
            ->where('report_type', 'ledger')
            ->find($generatedReportId);

        if (! $generatedReport) {
            return;
        }

        $pdfPath = FinanceGeneratedReport::pdfStorageIsReady()
            ? (string) ($generatedReport->pdf_path ?? '')
            : '';

        if ($pdfPath !== '') {
            Storage::disk('local')->delete($pdfPath);
        }

        $generatedReport->delete();

        session()->flash('status', __('finance.reports.saved_report_deleted'));
    }

    protected function selectDefaultLedgerCashBox(): void
    {
        $cashBox = app(FinanceService::class)->defaultCashBoxForUser(auth()->user());

        $this->ledger_cash_box_id = $cashBox ? (string) $cashBox->id : '';
        $this->selectDefaultLedgerCurrency();
    }

    protected function syncLedgerQuarterDates(): void
    {
        $quarter = max(1, min(4, (int) $this->ledger_quarter));
        $start = \Illuminate\Support\Carbon::create($this->ledger_year, (($quarter - 1) * 3) + 1, 1)->startOfDay();
        $this->ledger_date_from = $start->toDateString();
        $this->ledger_date_to = $start->copy()->addMonths(2)->endOfMonth()->toDateString();
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
    <section class="page-hero p-6 lg:p-8" style="order: 1">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.reports.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.reports.subtitle') }}</p>
            </div>
            @can('finance.report-templates.manage')
                <a href="{{ route('settings.finance.report-templates') }}" class="pill-link pill-link--accent">{{ __('finance.reports.manage_templates') }}</a>
            @endcan
        </div>
    </section>

    @can('finance.reports.export')
        @if ($generatedReportsEnabled)
            <section class="surface-table" style="order: 3">
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
                                <th class="px-5 py-3 text-left">{{ __('finance.common.date') }}</th>
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
                                    <td class="px-5 py-3">{{ $savedStart ? \Illuminate\Support\Carbon::parse($savedStart)->format('d-m-Y') : '-' }} - {{ $savedEnd ? \Illuminate\Support\Carbon::parse($savedEnd)->format('d-m-Y') : '-' }}</td>
                                    <td class="px-5 py-3">{{ data_get($generatedReport->filters, 'cash_box_name', data_get($generatedReport->report_data, 'cash_box.name', '-')) }}</td>
                                    <td class="px-5 py-3">{{ data_get($generatedReport->filters, 'currency_code', data_get($generatedReport->report_data, 'currency.code', '-')) }}</td>
                                    <td class="px-5 py-3">{{ $generatedReport->generatedBy?->name ?: (data_get($generatedReport->report_data, 'issuer_name') ?: '-') }}</td>
                                    <td class="px-5 py-3">{{ $generatedReport->created_at?->format('d-m-Y H:i') }}</td>
                                    <td class="px-5 py-3">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            <a href="{{ route('finance.reports.generated.show', $generatedReport) }}" target="_blank" rel="noopener" class="pill-link pill-link--compact">{{ __('finance.reports.review_saved_report') }}</a>
                                            <a href="{{ route('finance.reports.generated.show', ['generatedReport' => $generatedReport, 'format' => 'xlsx']) }}" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.reports.export_saved_xlsx') }}</a>
                                            <button type="button" wire:click="deleteGeneratedReport({{ $generatedReport->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('finance.reports.delete_saved_report') }}</button>
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
        @else
            <section class="surface-panel p-5 lg:p-6" style="order: 3">
                <div class="rounded-3xl border border-amber-400/25 bg-amber-500/10 px-4 py-4 text-sm text-amber-100">
                    {{ __('finance.reports.generated_reports_unavailable') }}
                </div>
            </section>
        @endif
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
        <section class="surface-panel p-5 lg:p-6" style="order: 2">
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
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.period') }}</label>
                        <select wire:model.live="ledger_period_mode" data-searchable="false" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="quarter">{{ __('finance.reports.period_quarter') }}</option>
                            <option value="custom">{{ __('finance.reports.period_custom') }}</option>
                        </select>
                    </div>
                    @if ($ledger_period_mode === 'quarter')
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.year') }}</label>
                            <select wire:model.live="ledger_year" class="w-full rounded-xl px-4 py-3 text-sm">@for ($reportYear = now()->year + 1; $reportYear >= now()->year - 10; $reportYear--)<option value="{{ $reportYear }}">{{ $reportYear }}</option>@endfor</select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.quarter') }}</label>
                            <select wire:model.live="ledger_quarter" class="w-full rounded-xl px-4 py-3 text-sm">@for ($reportQuarter = 1; $reportQuarter <= 4; $reportQuarter++)<option value="{{ $reportQuarter }}">Q{{ $reportQuarter }}</option>@endfor</select>
                        </div>
                    @else
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.from_date') }}</label>
                            <input wire:model.live="ledger_date_from" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.to_date') }}</label>
                            <input wire:model.live="ledger_date_to" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                        </div>
                    @endif
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
