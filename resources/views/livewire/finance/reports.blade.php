<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Models\AppSetting;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithFileUploads;
    use WithPagination;

    public string $ledger_period_mode = 'quarter';
    public int $ledger_year;
    public string $ledger_quarter = '';
    public string $ledger_cash_box_id = '';
    public array $ledger_cash_box_ids = [];
    public string $ledger_currency_id = '';
    public string $ledger_date_from = '';
    public string $ledger_date_to = '';
    public $report_background_upload = null;
    public $report_logo_upload = null;
    public $report_stamp_upload = null;
    public string $report_notes = '';
    public bool $remove_report_background = false;
    public bool $remove_report_logo = false;
    public bool $remove_report_stamp = false;
    public bool $showReportSettingsModal = false;
    public bool $showCreateReportModal = false;

    public function mount(): void
    {
        $this->authorizePermission('finance.reports.view');

        $this->ledger_year = (int) now()->year;
        $this->ledger_quarter = (string) now()->quarter;
        $latestPeriod = app(FinanceService::class)->availableTransactionPeriods(auth()->user())->first();
        if ($latestPeriod) {
            $this->ledger_year = (int) $latestPeriod['year'];
            $this->ledger_quarter = (string) ($latestPeriod['quarters'][0] ?? 1);
        }
        $this->syncLedgerQuarterDates();
        $this->selectDefaultLedgerCashBox();
    }

    public function updatedLedgerCashBoxId(): void
    {
        $this->selectDefaultLedgerCurrency();
    }

    public function updatedLedgerCashBoxIds(): void
    {
        $this->ledger_cash_box_ids = collect($this->ledger_cash_box_ids)->map(fn ($id) => (string) $id)->filter()->unique()->values()->all();
        $this->ledger_cash_box_id = (string) ($this->ledger_cash_box_ids[0] ?? '');
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
            $period = app(FinanceService::class)->availableTransactionPeriods(auth()->user())->firstWhere('year', $this->ledger_year);
            $this->ledger_quarter = (string) ($period['quarters'][0] ?? '');
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
                    ->paginate(10, pageName: 'generatedReportsPage')
                : collect(),
            'ledgerCashBoxes' => $financeService->accessibleCashBoxes(auth()->user())->get(),
            'ledgerCurrencies' => $this->ledgerCurrencies(),
            'ledgerPeriods' => $financeService->availableTransactionPeriods(auth()->user()),
            'ledgerSettings' => app(FinanceReportService::class)->defaultLedgerTemplate()->fresh(),
            'reportStampPath' => AppSetting::groupValues('finance')->get('report_stamp_path'),
            'canGenerateReport' => auth()->user()?->financeSignaturePdfSource() !== null,
        ];
    }

    public function saveReportSettings(): void
    {
        $this->authorizePermission('finance.report-templates.manage');
        $validated = $this->validate([
            'report_background_upload' => ['nullable', 'image', 'mimes:jpeg,jpg,png', 'max:'.config('uploads.image_max_kb')],
            'report_logo_upload' => ['nullable', 'image', 'mimes:png', 'max:'.config('uploads.image_max_kb')],
            'report_stamp_upload' => ['nullable', 'image', 'mimes:png', 'max:'.config('uploads.image_max_kb')],
            'remove_report_background' => ['boolean'],
            'remove_report_logo' => ['boolean'],
            'remove_report_stamp' => ['boolean'],
        ]);
        $settings = app(FinanceReportService::class)->defaultLedgerTemplate();

        if ($validated['remove_report_background'] && $settings->background_image) {
            Storage::disk('public')->delete($settings->background_image);
            $settings->background_image = null;
        }
        if ($validated['report_background_upload'] ?? null) {
            if ($settings->background_image) {
                Storage::disk('public')->delete($settings->background_image);
            }
            $settings->background_image = $validated['report_background_upload']->store('finance/reports/backgrounds', 'public');
        }
        if ($validated['remove_report_logo'] && $settings->logo_image) {
            Storage::disk('public')->delete($settings->logo_image);
            $settings->logo_image = null;
        }
        if ($validated['report_logo_upload'] ?? null) {
            if ($settings->logo_image) {
                Storage::disk('public')->delete($settings->logo_image);
            }
            $settings->logo_image = $validated['report_logo_upload']->store('finance/reports/logos', 'public');
        }
        $stampPath = AppSetting::groupValues('finance')->get('report_stamp_path');
        if ($validated['remove_report_stamp'] && $stampPath) {
            Storage::disk('public')->delete($stampPath);
            AppSetting::storeValue('finance', 'report_stamp_path', null);
            $stampPath = null;
        }
        if ($validated['report_stamp_upload'] ?? null) {
            if ($stampPath) {
                Storage::disk('public')->delete($stampPath);
            }
            AppSetting::storeValue('finance', 'report_stamp_path', $validated['report_stamp_upload']->store('finance/reports/stamps', 'public'));
        }

        $settings->forceFill([
            'columns' => FinanceReportTemplate::DEFAULT_COLUMNS,
            'custom_text' => null,
            'include_closing_balance' => true,
            'include_exported_at' => true,
            'include_opening_balance' => true,
            'is_default' => true,
            'language' => FinanceReportTemplate::LANGUAGE_AR,
            'name' => 'Financial ledger',
            'show_issuer_name' => true,
            'show_page_numbers' => true,
            'subtitle' => null,
            'title' => 'تقرير مالي',
        ])->save();

        $this->reset('report_background_upload', 'report_logo_upload', 'report_stamp_upload', 'remove_report_background', 'remove_report_logo', 'remove_report_stamp');
        $this->showReportSettingsModal = false;
        session()->flash('status', __('finance.reports.settings_saved'));
    }

    public function openReportSettings(): void
    {
        $this->authorizePermission('finance.report-templates.manage');
        $this->showReportSettingsModal = true;
    }

    public function closeReportSettings(): void
    {
        $this->reset('report_background_upload', 'report_logo_upload', 'report_stamp_upload', 'remove_report_background', 'remove_report_logo', 'remove_report_stamp');
        $this->showReportSettingsModal = false;
        $this->resetValidation();
    }

    public function openCreateReport(): void
    {
        $this->authorizePermission('finance.reports.export');
        if (! auth()->user()?->financeSignaturePdfSource()) {
            $this->addError('createReport', __('finance.reports.signature_required'));

            return;
        }
        $this->showCreateReportModal = true;
    }

    public function closeCreateReport(): void
    {
        $this->showCreateReportModal = false;
        $this->resetValidation('createReport');
    }

    protected function selectDefaultLedgerCashBox(): void
    {
        $cashBox = app(FinanceService::class)->defaultCashBoxForUser(auth()->user());

        $this->ledger_cash_box_id = $cashBox ? (string) $cashBox->id : '';
        $this->ledger_cash_box_ids = $this->ledger_cash_box_id !== '' ? [$this->ledger_cash_box_id] : [];
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
        $cashBoxIds = collect($this->ledger_cash_box_ids)->filter()->map(fn ($id) => (int) $id)->values();
        if ($cashBoxIds->isEmpty()) {
            return collect();
        }

        $service = app(FinanceService::class);
        $currencies = $service->currenciesForCashBox($cashBoxIds->first())->get();

        return $currencies->filter(fn ($currency) => $cashBoxIds->every(
            fn (int $cashBoxId) => $service->currenciesForCashBox($cashBoxId)->whereKey($currency->id)->exists()
        ))->values();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8" style="order: 1">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.reports.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.reports.subtitle') }}</p>
            </div>
            @can('finance.report-templates.manage')
                <button type="button" wire:click="openReportSettings" title="{{ __('finance.reports.report_settings') }}" aria-label="{{ __('finance.reports.report_settings') }}" class="financial-report-symbol-button pill-link pill-link--accent">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.09a2 2 0 0 1-1-1.74v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z"></path>
                    </svg>
                </button>
            @endcan
        </div>
    </section>

    @can('finance.reports.export')
        @if ($generatedReportsEnabled)
            <section class="surface-table" style="order: 4">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('finance.reports.generated_reports') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('finance.reports.saved_reports_count', ['count' => number_format($generatedReports->total())]) }}</div>
                    </div>
                    <div class="admin-toolbar__controls financial-report-symbol-controls">
                        <button type="button" wire:click="openCreateReport" @disabled(! $canGenerateReport) title="{{ $canGenerateReport ? __('finance.reports.generate_report') : __('finance.reports.signature_required') }}" aria-label="{{ __('finance.reports.generate_report') }}" class="financial-report-symbol-button pill-link pill-link--accent disabled:cursor-not-allowed disabled:opacity-50">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"></path></svg>
                        </button>
                    </div>
                </div>
                @error('createReport')<div class="mx-5 mb-4 rounded-xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div>@enderror
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-left">{{ __('finance.fields.report_no') }}</th>
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
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="font-medium text-white">{{ app(FinanceReportService::class)->reportNumber($generatedReport, $generatedReport->report_data ?: []) }}</div>
                                    </td>
                                    <td class="px-5 py-3"><bdi dir="ltr">{{ app(FinanceReportService::class)->savedReportPeriodLabel($generatedReport) }}</bdi></td>
                                    <td class="px-5 py-3">{{ data_get($generatedReport->filters, 'cash_box_name', data_get($generatedReport->report_data, 'cash_box.name', '-')) }}</td>
                                    <td class="px-5 py-3">{{ data_get($generatedReport->filters, 'currency_code', data_get($generatedReport->report_data, 'currency.code', '-')) }}</td>
                                    <td class="px-5 py-3">{{ $generatedReport->generatedBy?->name ?: (data_get($generatedReport->report_data, 'issuer_name') ?: '-') }}</td>
                                    <td class="px-5 py-3">{{ $generatedReport->created_at?->format('d-m-Y') }}</td>
                                    <td class="px-5 py-3">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            <a href="{{ route('finance.reports.generated.show', $generatedReport) }}" target="_blank" rel="noopener" class="pill-link pill-link--compact">{{ __('finance.reports.review_saved_report') }}</a>
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
                @if ($generatedReports->hasPages())
                    <div class="border-t border-white/10 px-5 py-4">
                        {{ $generatedReports->links() }}
                    </div>
                @endif
            </section>
        @else
            <section class="surface-panel p-5 lg:p-6" style="order: 4">
                <div class="rounded-3xl border border-amber-400/25 bg-amber-500/10 px-4 py-4 text-sm text-amber-100">
                    {{ __('finance.reports.generated_reports_unavailable') }}
                </div>
            </section>
        @endif
    @endcan

    @can('finance.reports.export')
        @php
            $ledgerReady = $ledger_cash_box_id !== '' && $ledger_date_from !== '' && $ledger_date_to !== '';
            $ledgerQuery = [
                'cash_box_id' => $ledger_cash_box_id,
                'cash_box_ids' => $ledger_cash_box_ids,
                'date_from' => $ledger_date_from,
                'date_to' => $ledger_date_to,
                'ledger_notes' => $report_notes,
            ];
        @endphp
        <x-admin.modal :show="$showCreateReportModal" :title="__('finance.reports.generate_report')" close-method="closeCreateReport" max-width="4xl">
        <div class="grid gap-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label>
                    <div class="finance-report-funds flex gap-2 overflow-x-auto pb-1">
                        @forelse ($ledgerCashBoxes as $cashBox)
                            <label class="flex shrink-0 items-center gap-3 rounded-xl border border-white/10 px-4 py-3 text-sm"><input type="checkbox" wire:model.live="ledger_cash_box_ids" value="{{ $cashBox->id }}" class="rounded"><span>{{ $cashBox->name }}</span></label>
                        @empty
                            <span>{{ __('finance.empty.no_cash_boxes') }}</span>
                        @endforelse
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.period') }}</label>
                        <select wire:model.live="ledger_period_mode" data-searchable="false" class="h-[3.125rem] min-h-[3.125rem] w-full rounded-xl px-4 py-3 text-sm">
                            <option value="quarter">{{ __('finance.reports.period_quarter') }}</option>
                            <option value="custom">{{ __('finance.reports.period_custom') }}</option>
                        </select>
                    </div>
                    @if ($ledger_period_mode === 'quarter')
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.year') }}</label>
                            <select wire:model.live="ledger_year" class="h-[3.125rem] min-h-[3.125rem] w-full rounded-xl px-4 py-3 text-sm">@forelse($ledgerPeriods as $period)<option value="{{ $period['year'] }}">{{ $period['year'] }}</option>@empty<option value="{{ $ledger_year }}">-</option>@endforelse</select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.quarter') }}</label>
                            <select wire:model.live="ledger_quarter" class="h-[3.125rem] min-h-[3.125rem] w-full rounded-xl px-4 py-3 text-sm">@foreach((collect($ledgerPeriods)->firstWhere('year', $ledger_year)['quarters'] ?? []) as $reportQuarter)<option value="{{ $reportQuarter }}">Q{{ $reportQuarter }}</option>@endforeach</select>
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
            <div class="mt-5">
                <label class="mb-1 block text-sm font-medium">{{ __('finance.reports.report_notes') }}</label>
                <textarea wire:model.live="report_notes" rows="3" maxlength="4000" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($ledgerReady)
                    <a href="{{ route('finance.reports.ledger.export', array_merge($ledgerQuery, ['format' => 'pdf'])) }}" target="_blank" rel="noopener" class="pill-link pill-link--accent">{{ __('finance.reports.generate_report') }}</a>
                @else
                    <span class="pill-link opacity-60">{{ __('finance.reports.choose_box_currency_first') }}</span>
                @endif
            </div>
        </div>
        </x-admin.modal>
    @endcan

    @can('finance.report-templates.manage')
        <x-admin.modal :show="$showReportSettingsModal" :title="__('finance.reports.report_settings')" :description="__('finance.reports.ledger_design_help')" close-method="closeReportSettings" max-width="5xl">
            <form wire:submit="saveReportSettings" class="grid gap-5 lg:grid-cols-2">
                <div>
                    <label class="mb-2 block text-sm font-medium">{{ __('finance.reports.page_background') }}</label>
                    <input wire:model="report_background_upload" type="file" accept="image/png,image/jpeg" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('report_background_upload')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                    @if ($ledgerSettings?->background_image_url)<img src="{{ $ledgerSettings->background_image_url }}" alt="" class="mt-3 h-28 w-full rounded-xl object-cover">@endif
                    @if ($ledgerSettings?->background_image)<label class="mt-3 flex items-center gap-2 text-sm"><input wire:model="remove_report_background" type="checkbox"><span>{{ __('finance.reports.remove_background') }}</span></label>@endif
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium">{{ __('finance.reports.report_logo') }}</label>
                    <input wire:model="report_logo_upload" type="file" accept="image/png" class="w-full rounded-xl px-4 py-3 text-sm">
                    <p class="mt-1 text-xs text-neutral-400">{{ __('finance.reports.transparent_png_help') }}</p>
                    @error('report_logo_upload')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                    @if ($ledgerSettings?->logo_image_url)<img src="{{ $ledgerSettings->logo_image_url }}" alt="" class="mt-3 h-28 max-w-52 rounded-xl object-contain">@endif
                    @if ($ledgerSettings?->logo_image)<label class="mt-3 flex items-center gap-2 text-sm"><input wire:model="remove_report_logo" type="checkbox"><span>{{ __('finance.reports.remove_logo') }}</span></label>@endif
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-2 block text-sm font-medium">{{ __('finance.reports.report_stamp') }}</label>
                    <input wire:model="report_stamp_upload" type="file" accept="image/png" class="w-full rounded-xl px-4 py-3 text-sm">
                    <p class="mt-1 text-xs text-neutral-400">{{ __('finance.reports.report_stamp_help') }}</p>
                    @error('report_stamp_upload')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                    @if ($reportStampPath)<img src="{{ asset('storage/'.ltrim($reportStampPath, '/')) }}" alt="" class="mt-3 h-28 max-w-52 rounded-xl bg-white object-contain p-2"><label class="mt-3 flex items-center gap-2 text-sm"><input wire:model="remove_report_stamp" type="checkbox"><span>{{ __('finance.reports.remove_stamp') }}</span></label>@endif
                </div>
                <div class="lg:col-span-2 flex justify-end gap-3">
                    <button type="button" wire:click="closeReportSettings" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                    <button class="pill-link pill-link--accent">{{ __('finance.actions.save') }}</button>
                </div>
            </form>
        </x-admin.modal>
    @endcan
</div>
