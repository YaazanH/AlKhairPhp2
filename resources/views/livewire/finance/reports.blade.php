<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Services\FinanceReportService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public int $year;
    public string $quarter = '';

    public function mount(): void
    {
        $this->authorizePermission('finance.reports.view');
        $this->year = (int) now()->year;
    }

    public function with(): array
    {
        return [
            'report' => app(FinanceReportService::class)->report($this->year, $this->quarter !== '' ? (int) $this->quarter : null),
        ];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.reports.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.reports.subtitle') }}</p>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        <div class="grid gap-4 md:grid-cols-[12rem_12rem_auto] md:items-end">
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.year') }}</label><input wire:model.live="year" type="number" min="2000" max="2100" class="w-full rounded-xl px-4 py-3 text-sm"></div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.quarter') }}</label><select wire:model.live="quarter" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.options.all_year') }}</option><option value="1">Q1</option><option value="2">Q2</option><option value="3">Q3</option><option value="4">Q4</option></select></div>
            @can('finance.reports.export')
                <a href="{{ route('finance.reports.export', ['year' => $year, 'quarter' => $quarter ?: null]) }}" class="pill-link pill-link--accent">{{ __('finance.actions.export_xlsx') }}</a>
            @endcan
        </div>
    </section>

    <section class="admin-kpi-grid">
        <article class="stat-card"><div class="kpi-label">{{ __('finance.fields.income') }}</div><div class="metric-value mt-3">{{ number_format($report['summary']['income'], 2) }} {{ $report['summary']['local_currency']?->code }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('finance.fields.expense') }}</div><div class="metric-value mt-3">{{ number_format($report['summary']['expense'], 2) }} {{ $report['summary']['local_currency']?->code }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('finance.fields.net') }}</div><div class="metric-value mt-3">{{ number_format($report['summary']['net'], 2) }} {{ $report['summary']['local_currency']?->code }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('finance.fields.transactions') }}</div><div class="metric-value mt-3">{{ number_format($report['summary']['transactions']) }}</div></article>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.totals_by_currency') }}</div><div class="admin-grid-meta__summary">{{ __('finance.reports.totals_by_currency_subtitle') }}</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.currency') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.income') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.expense') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.net') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($report['summary_by_currency'] as $row)<tr><td class="px-5 py-3">{{ $row['currency']?->code }}</td><td class="px-5 py-3">{{ number_format($row['income'], 2) }} {{ $row['currency']?->code }}</td><td class="px-5 py-3">{{ number_format($row['expense'], 2) }} {{ $row['currency']?->code }}</td><td class="px-5 py-3">{{ number_format($row['net'], 2) }} {{ $row['currency']?->code }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('finance.empty.no_transactions') }}</td></tr>@endforelse</tbody></table></div>
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.cash_balances') }}</div><div class="admin-grid-meta__summary">{{ __('finance.reports.cash_balances_subtitle') }}</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.cash_box') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.currency') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.balance') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.local_equivalent') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($report['balances'] as $boxBalance)@foreach ($boxBalance['currencies'] as $row)<tr><td class="px-5 py-3">{{ $boxBalance['cash_box']->name }}</td><td class="px-5 py-3">{{ $row['currency']->code }}</td><td class="px-5 py-3">{{ number_format($row['balance'], 2) }} {{ $row['currency']->code }}</td><td class="px-5 py-3">{{ number_format($row['local_equivalent'], 2) }} {{ $report['summary']['local_currency']?->code }}</td></tr>@endforeach@endforeach</tbody></table></div>
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.quarter_totals') }}</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.quarter') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.period') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.net_local') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($report['quarter_totals'] as $quarter)<tr><td class="px-5 py-3">Q{{ $quarter['quarter'] }}</td><td class="px-5 py-3">{{ $quarter['start']->format('Y-m-d') }} - {{ $quarter['end']->format('Y-m-d') }}</td><td class="px-5 py-3">{{ number_format($quarter['net'], 2) }} {{ $report['summary']['local_currency']?->code }}</td></tr>@endforeach</tbody></table></div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.category_totals') }}</div><div class="admin-grid-meta__summary">{{ __('finance.reports.category_totals_subtitle') }}</div></div></div>
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.income') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.expense') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.net') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($report['category_totals'] as $row)<tr><td class="px-5 py-3">{{ $row['category'] }}</td><td class="px-5 py-3">{{ number_format($row['income'], 2) }} {{ $report['summary']['local_currency']?->code }}</td><td class="px-5 py-3">{{ number_format($row['expense'], 2) }} {{ $report['summary']['local_currency']?->code }}</td><td class="px-5 py-3">{{ number_format($row['net'], 2) }} {{ $report['summary']['local_currency']?->code }}</td></tr>@endforeach</tbody></table></div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.reports.pending_pull_requests') }}</div></div></div>
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.request') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.requester') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.activity') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.amount') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($report['pending_pull_requests'] as $request)<tr><td class="px-5 py-3">{{ $request->request_no }}</td><td class="px-5 py-3">{{ $request->requestedBy?->name ?: '-' }}</td><td class="px-5 py-3">{{ $request->activity?->title ?: '-' }}</td><td class="px-5 py-3">{{ number_format((float) $request->requested_amount, 2) }} {{ $request->requestedCurrency?->code }}</td></tr>@empty<tr><td colspan="4" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('finance.empty.no_pending_pull_requests') }}</td></tr>@endforelse</tbody></table></div>
    </section>
</div>
