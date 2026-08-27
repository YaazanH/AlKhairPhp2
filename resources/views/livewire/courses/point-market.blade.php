<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Course;
use App\Models\CoursePointMarketDepartment;
use App\Models\CoursePointMarketItem;
use App\Models\Invoice;
use App\Services\CoursePointMarketService;
use App\Services\FinanceService;
use App\Support\NumberFormatter;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public Course $course;

    public bool $showInvoiceModal = false;
    public bool $showDepartmentModal = false;
    public bool $showAssignmentModal = false;
    public bool $showDepartmentSettingsModal = false;

    public array $selectedInvoiceIds = [];
    public array $selectedItemIds = [];
    public array $expandedInvoiceIds = [];
    public array $expandedDepartmentIds = [];

    public string $departmentName = '';
    public ?int $assignmentInvoiceId = null;
    public ?int $assignmentDepartmentId = null;
    public ?int $settingsDepartmentId = null;
    public string $pointPrice = '1';

    public function mount(Course $course): void
    {
        $this->authorizePermission('courses.view');
        $this->authorizePermission('finance.expense-requests.view');
        $this->course = $course;
    }

    public function with(): array
    {
        $service = app(CoursePointMarketService::class);
        $departments = $service->departments($this->course);
        $pointMarketSummary = $service->summary($this->course, $departments);

        return [
            'addedInvoiceLinks' => $service->pointMarketInvoiceLinks($this->course),
            'allocatedInvoiceItemIds' => CoursePointMarketItem::query()
                ->where('course_id', $this->course->id)
                ->whereNotNull('invoice_item_id')
                ->pluck('invoice_item_id'),
            'availableInvoices' => $this->showInvoiceModal ? $service->availableInvoices($this->course) : collect(),
            'departments' => $departments,
            'localCurrency' => $pointMarketSummary['local_currency'],
            'pointMarketSummary' => $pointMarketSummary,
        ];
    }

    public function openInvoiceModal(): void
    {
        $this->authorizePointMarketUpdate();
        $this->resetValidation();
        $this->selectedInvoiceIds = [];
        $this->showInvoiceModal = true;
    }

    public function closeInvoiceModal(): void
    {
        $this->showInvoiceModal = false;
        $this->selectedInvoiceIds = [];
        $this->resetValidation('selectedInvoiceIds');
    }

    public function addInvoices(): void
    {
        $this->authorizePointMarketUpdate();
        app(CoursePointMarketService::class)->addInvoices($this->course, $this->selectedInvoiceIds, auth()->user());
        $this->closeInvoiceModal();
        session()->flash('status', __('course_end.point_market.messages.invoices_added'));
    }

    public function openDepartmentModal(): void
    {
        $this->authorizePointMarketUpdate();
        $this->departmentName = '';
        $this->resetValidation('departmentName');
        $this->showDepartmentModal = true;
    }

    public function closeDepartmentModal(): void
    {
        $this->showDepartmentModal = false;
        $this->departmentName = '';
        $this->resetValidation('departmentName');
    }

    public function createDepartment(): void
    {
        $this->authorizePointMarketUpdate();
        $validated = $this->validate([
            'departmentName' => [
                'required',
                'string',
                'max:255',
                Rule::unique('course_point_market_departments', 'name')
                    ->where(fn ($query) => $query->where('course_id', $this->course->id)),
            ],
        ]);
        $department = app(CoursePointMarketService::class)->createDepartment(
            $this->course,
            $validated['departmentName'],
            auth()->user(),
        );
        $this->expandedDepartmentIds = [$department->id];
        $this->closeDepartmentModal();
        session()->flash('status', __('course_end.point_market.messages.department_created'));
    }

    public function toggleInvoice(int $invoiceId): void
    {
        $this->expandedInvoiceIds = in_array($invoiceId, $this->expandedInvoiceIds, true) ? [] : [$invoiceId];
    }

    public function toggleDepartment(int $departmentId): void
    {
        $this->expandedDepartmentIds = in_array($departmentId, $this->expandedDepartmentIds, true) ? [] : [$departmentId];
    }

    public function toggleAllInvoiceItems(int $invoiceId): void
    {
        $availableIds = $this->availableItemIdsForInvoice($invoiceId);
        $selected = collect($this->selectedItemIds)->map(fn ($id) => (int) $id);
        $allSelected = $availableIds->isNotEmpty() && $availableIds->every(fn ($id) => $selected->contains($id));

        $this->selectedItemIds = $allSelected
            ? $selected->diff($availableIds)->values()->all()
            : $selected->merge($availableIds)->unique()->values()->all();
    }

    public function openAssignmentModal(int $invoiceId): void
    {
        $this->authorizePointMarketUpdate();
        $selectedForInvoice = $this->availableItemIdsForInvoice($invoiceId)
            ->intersect(collect($this->selectedItemIds)->map(fn ($id) => (int) $id));

        if ($selectedForInvoice->isEmpty()) {
            $this->addError('selectedItemIds', __('course_end.point_market.validation.select_item'));

            return;
        }

        $this->assignmentInvoiceId = $invoiceId;
        $this->assignmentDepartmentId = null;
        $this->showAssignmentModal = true;
        $this->resetValidation('assignmentDepartmentId');
    }

    public function closeAssignmentModal(): void
    {
        $this->showAssignmentModal = false;
        $this->assignmentInvoiceId = null;
        $this->assignmentDepartmentId = null;
        $this->resetValidation('assignmentDepartmentId');
    }

    public function addSelectedItems(): void
    {
        $this->authorizePointMarketUpdate();
        $validated = $this->validate([
            'assignmentInvoiceId' => ['required', 'integer'],
            'assignmentDepartmentId' => [
                'required',
                'integer',
                Rule::exists('course_point_market_departments', 'id')
                    ->where(fn ($query) => $query->where('course_id', $this->course->id)),
            ],
        ]);
        $invoice = Invoice::query()->findOrFail($validated['assignmentInvoiceId']);
        $department = CoursePointMarketDepartment::query()->findOrFail($validated['assignmentDepartmentId']);
        $selectedForInvoice = $this->availableItemIdsForInvoice($invoice->id)
            ->intersect(collect($this->selectedItemIds)->map(fn ($id) => (int) $id));

        app(CoursePointMarketService::class)->allocateItems(
            $this->course,
            $invoice,
            $department,
            $selectedForInvoice->all(),
            auth()->user(),
        );

        $this->selectedItemIds = collect($this->selectedItemIds)
            ->map(fn ($id) => (int) $id)
            ->diff($selectedForInvoice)
            ->values()
            ->all();
        $this->expandedDepartmentIds = [$department->id];
        $this->closeAssignmentModal();
        session()->flash('status', __('course_end.point_market.messages.items_added'));
    }

    public function openDepartmentSettings(int $departmentId): void
    {
        $this->authorizePointMarketUpdate();
        $department = CoursePointMarketDepartment::query()
            ->where('course_id', $this->course->id)
            ->findOrFail($departmentId);
        $this->settingsDepartmentId = $department->id;
        $this->pointPrice = NumberFormatter::trimmed($department->point_price, 2);
        $this->showDepartmentSettingsModal = true;
        $this->resetValidation('pointPrice');
    }

    public function closeDepartmentSettingsModal(): void
    {
        $this->showDepartmentSettingsModal = false;
        $this->settingsDepartmentId = null;
        $this->pointPrice = '1';
        $this->resetValidation('pointPrice');
    }

    public function saveDepartmentSettings(): void
    {
        $this->authorizePointMarketUpdate();
        $this->pointPrice = str_replace(',', '', $this->pointPrice);
        $validated = $this->validate([
            'settingsDepartmentId' => ['required', 'integer'],
            'pointPrice' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
        ]);
        $department = CoursePointMarketDepartment::query()
            ->where('course_id', $this->course->id)
            ->findOrFail($validated['settingsDepartmentId']);
        app(CoursePointMarketService::class)->updatePointPrice($department, (float) $validated['pointPrice']);
        $this->closeDepartmentSettingsModal();
        session()->flash('status', __('course_end.point_market.messages.settings_saved'));
    }

    public function removeDepartmentItem(int $itemId): void
    {
        $this->authorizePointMarketUpdate();
        $item = CoursePointMarketItem::query()
            ->where('course_id', $this->course->id)
            ->findOrFail($itemId);

        app(CoursePointMarketService::class)->removeItem($this->course, $item);
        session()->flash('status', __('course_end.point_market.messages.item_removed'));
    }

    protected function availableItemIdsForInvoice(int $invoiceId)
    {
        $allocatedIds = CoursePointMarketItem::query()
            ->where('course_id', $this->course->id)
            ->whereNotNull('invoice_item_id')
            ->pluck('invoice_item_id');

        return Invoice::query()
            ->whereHas('pointMarketCourseLinks', fn ($query) => $query->where('course_id', $this->course->id))
            ->findOrFail($invoiceId)
            ->items()
            ->whereNotIn('id', $allocatedIds)
            ->pluck('id');
    }

    protected function authorizePointMarketUpdate(): void
    {
        $this->authorizePermission('courses.update');
        $this->authorizePermission('finance.expense-requests.view');
    }
}; ?>

<div class="page-stack" data-course-point-market>
    <style>
        .point-market-table-wrap,
        .point-market-modal-table-wrap { width: 100%; max-width: 100%; overflow-x: auto; }
        .point-market-generic-table { width: 100%; }
        .point-market-generic-table :is(th, td) { min-width: 0; overflow-wrap: anywhere; }
        .point-market-table__select { width: 2.75rem; text-align: center; }
        .point-market-table__number { width: 2.75rem; max-width: 2.75rem; padding-inline: .45rem !important; text-align: center; white-space: nowrap; }
        .point-market-table__action { width: 7rem; text-align: center; }
        .point-market-table__remove { width: 3rem; text-align: center; }
        .point-market-invoice-row { transition: background-color .16s ease; }
        .point-market-invoice-row--expanded > td { background: rgba(255, 255, 255, .025); }
        .point-market-invoice-table { table-layout: fixed; }
        .point-market-generic-table col.point-market-col--number { width: 2.75rem; }
        .point-market-invoice-table col.point-market-col--text { width: 3.5rem; }
        .point-market-invoice-table col.point-market-col--medium { width: 4rem; }
        .point-market-invoice-table col.point-market-col--amount { width: 4.75rem; }
        .point-market-department-table { table-layout: fixed; }
        .point-market-department-table col.point-market-col--utility { width: 5%; }
        .point-market-department-table col.point-market-col--equal { width: 18%; }
        .point-market-department-table :is(.point-market-table__remove, .point-market-table__number) { width: auto; max-width: none; }
        html[dir='rtl'] [data-course-point-market] .point-market-generic-table :is(th, td) { text-align: right; }
        html[dir='rtl'] [data-course-point-market] .point-market-generic-table :is(.point-market-table__select, .point-market-table__number, .point-market-table__action, .point-market-table__remove) { text-align: center; }
        html[dir='rtl'] [data-course-point-market] .point-market-generic-table .point-market-numeric { text-align: right !important; }
        .point-market-numeric > bdi { display: inline-block; }
        .point-market-detail-header > th {
            border-top: 1px solid rgba(255, 255, 255, .1) !important;
            background: rgba(35, 28, 22, .98);
            padding: .7rem .85rem;
            color: #fff4db;
        }
        .point-market-detail-row > td {
            background: rgba(19, 16, 13, .98);
            padding: .72rem .85rem;
        }
        .point-market-detail-row + .point-market-detail-row > td {
            border-top: 1px solid rgba(255, 255, 255, .06) !important;
        }
        .point-market-detail-row:hover > td { background: rgba(35, 28, 22, .98) !important; }
        html:not(.dark) .point-market-invoice-row--expanded > td { background: rgba(32, 91, 55, .025); }
        html:not(.dark) .point-market-detail-header > th { background: rgba(224, 216, 202, .92); color: #40372d; }
        html:not(.dark) .point-market-detail-row > td { background: rgba(250, 247, 240, .97); }
        html:not(.dark) .point-market-detail-row:hover > td { background: rgba(239, 233, 222, .98) !important; }
        .point-market-row-actions { display: flex; align-items: center; justify-content: flex-end; gap: .45rem; }
        .point-market-department-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .5rem; }
        .point-market-department-toggle { display: inline-flex; min-width: 0; flex: 1; align-items: center; justify-content: space-between; gap: .75rem; text-align: start; }
        .point-market-department-toggle__copy { min-width: 0; }
        .point-market-chevron { width: 1rem; height: 1rem; flex: 0 0 auto; transition: transform .18s ease; }
        .point-market-chevron--open { transform: rotate(90deg); }
        html[dir='rtl'] .point-market-chevron { transform: scaleX(-1); }
        html[dir='rtl'] .point-market-chevron--open { transform: rotate(90deg); }
        .point-market-collapse-button {
            display: inline-flex;
            width: 2.5rem;
            height: 2.5rem;
            flex: 0 0 2.5rem;
            align-items: center;
            justify-content: center;
            border: 0;
            padding: 0;
            background: transparent;
            color: var(--app-muted);
        }
        .point-market-collapse-button:hover,
        .point-market-collapse-button:focus-visible { color: var(--app-text); outline: none; }
        .point-market-remove-item { color: #fca5a5; font-size: 1.35rem; font-weight: 500; line-height: 1; }
        .point-market-modal-actions { display: flex; justify-content: flex-end; gap: .75rem; }
        html[dir='rtl'] .point-market-modal-actions { justify-content: flex-start; }
        .point-market-hero__layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(30rem, 34rem); align-items: center; gap: 2rem; }
        .point-market-hero__copy { min-width: 0; }
        .point-market-hero__summary { display: grid; width: 100%; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .65rem; }
        .point-market-hero__metric { min-width: 0; }
        .point-market-hero__metric-value { max-width: 100%; overflow-wrap: anywhere; }
        .point-market-prefixed-input {
            display: flex;
            width: 100%;
            align-items: stretch;
            overflow: hidden;
            border: 1px solid rgba(17, 81, 43, .16);
            border-radius: .75rem;
            background: rgba(255, 255, 255, .86);
            box-shadow: 0 10px 26px rgba(43, 62, 47, .06);
        }
        .point-market-prefixed-input__prefix { display: flex; align-items: center; border-inline-end: 1px solid var(--app-border); padding-inline: 1rem; color: var(--app-muted); font-weight: 600; white-space: nowrap; }
        .point-market-prefixed-input > input { min-width: 0; flex: 1; border: 0 !important; border-radius: 0 !important; background: transparent !important; box-shadow: none !important; }
        .dark .point-market-prefixed-input { border-color: rgba(216, 204, 185, .16); background: rgba(31, 24, 19, .88); }
        @media (max-width: 767px) {
            .point-market-hero__layout { grid-template-columns: minmax(0, 1fr); gap: 1.25rem; }
            .point-market-hero__summary { gap: .4rem; }
            .point-market-modal-table { font-size: .68rem; }
            .point-market-modal-table :is(th, td) { padding: .55rem .2rem !important; line-height: 1.25; white-space: normal; }
            .point-market-table-wrap > .point-market-invoice-table { min-width: 55rem; }
            .point-market-table-wrap > .point-market-department-table { min-width: 48rem; }
            .point-market-modal-table-wrap > .point-market-modal-table { min-width: 42rem; }
        }
    </style>

    <section class="page-hero p-6 lg:p-8">
        <div class="point-market-hero__layout">
            <div class="point-market-hero__copy">
            <x-back-link :href="route('courses.end', $course)" />
            <div class="eyebrow mt-4">{{ __('course_end.point_market.eyebrow') }}</div>
            <h1 class="font-display mt-4 text-4xl text-white">{{ __('course_end.point_market.title') }}</h1>
            <p class="mt-3 text-neutral-200">{{ __('course_end.point_market.subtitle', ['course' => $course->name]) }}</p>
            </div>
            <dl class="point-market-hero__summary" data-point-market-summary>
                <div class="point-market-hero__metric shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner">
                    <dt class="text-xs text-neutral-300">{{ __('course_end.point_market.summary.exchange_rate') }}</dt>
                    <dd><bdi dir="ltr" class="point-market-hero__metric-value mt-1 block text-lg font-semibold text-emerald-100">{{ $pointMarketSummary['exchange_rate'] }}</bdi></dd>
                </div>
                <div class="point-market-hero__metric shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner">
                    <dt class="text-xs text-neutral-300">{{ __('course_end.point_market.summary.total_points_after_rules') }}</dt>
                    <dd><bdi dir="ltr" class="point-market-hero__metric-value mt-1 block text-lg font-semibold text-emerald-100">{{ number_format($pointMarketSummary['total_points_after_rules']) }}</bdi></dd>
                </div>
                <div class="point-market-hero__metric shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner">
                    <dt class="text-xs text-neutral-300">{{ __('course_end.point_market.summary.departments_total') }}</dt>
                    <dd><bdi dir="ltr" class="point-market-hero__metric-value mt-1 block text-lg font-semibold text-emerald-100">{{ NumberFormatter::withSuffix($pointMarketSummary['departments_base_total'], $pointMarketSummary['base_currency']->symbol ?: $pointMarketSummary['base_currency']->code, (int) $pointMarketSummary['base_currency']->decimal_places) }}</bdi></dd>
                </div>
            </dl>
        </div>
    </section>

    @if (session('status'))
        <div class="soft-callout p-4 text-sm text-emerald-100">{{ session('status') }}</div>
    @endif

    <section class="surface-table" data-point-market-generic-table>
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('course_end.point_market.added_invoices') }}</div>
                <div class="mt-1 text-xs text-neutral-400">{{ __('course_end.point_market.added_invoices_count') }}: <bdi dir="ltr">{{ number_format($addedInvoiceLinks->count()) }}</bdi></div>
            </div>
            @can('courses.update')
                <button type="button" wire:click="openInvoiceModal" class="admin-icon-button admin-icon-button--accent" title="{{ __('course_end.point_market.add_invoice') }}" aria-label="{{ __('course_end.point_market.add_invoice') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg></button>
            @endcan
        </div>
        <div class="point-market-table-wrap">
            <table class="point-market-generic-table point-market-invoice-table text-sm">
                <colgroup><col class="point-market-col--number"><col class="point-market-col--number">@for($column = 0; $column < 4; $column++)<col class="point-market-col--text">@endfor<col class="point-market-col--medium"><col class="point-market-col--medium">@for($column = 0; $column < 4; $column++)<col class="point-market-col--amount">@endfor<col><col></colgroup>
                <thead><tr><th class="point-market-table__number">#</th><th colspan="2" class="px-4 py-3 text-start">{{ __('course_end.point_market.invoice_table.category') }}</th><th colspan="3" class="px-4 py-3 text-start">{{ __('course_end.point_market.invoice_table.description') }}</th><th colspan="2" class="px-4 py-3 text-start">{{ __('course_end.point_market.invoice_table.issuer') }}</th><th colspan="2" class="px-4 py-3 text-start">{{ __('course_end.point_market.invoice_table.original_invoice_no') }}</th><th colspan="2" class="px-4 py-3 text-start">{{ __('course_end.point_market.invoice_table.total') }}</th><th colspan="2" class="point-market-table__action px-3 py-3"></th></tr></thead>
                <tbody class="divide-y divide-white/6">
                    @forelse($addedInvoiceLinks as $link)
                        @php($invoice = $link->invoice)
                        @php($availableItems = $invoice ? $invoice->items->whereNotIn('id', $allocatedInvoiceItemIds)->values() : collect())
                        @php($isExpanded = in_array($invoice?->id, $expandedInvoiceIds, true))
                        @php($selectedForInvoice = $availableItems->pluck('id')->intersect(collect($selectedItemIds)->map(fn($id) => (int) $id)))
                        @php($invoiceCurrency = $invoice?->financeRequest?->acceptedCurrency ?: $invoice?->financeRequest?->requestedCurrency)
                        <tr class="point-market-invoice-row {{ $availableItems->isNotEmpty() ? 'cursor-pointer' : '' }} {{ $isExpanded ? 'point-market-invoice-row--expanded' : '' }}" @if($invoice && $availableItems->isNotEmpty()) wire:click="toggleInvoice({{ $invoice->id }})" @endif wire:key="point-market-invoice-{{ $link->id }}">
                            <td class="point-market-table__number">{{ $loop->iteration }}</td>
                            <td colspan="2" class="px-4 py-3">{{ $invoice?->financeRequest?->category?->name ?: '—' }}</td>
                            <td colspan="3" class="px-4 py-3">{{ $invoice?->financeRequest?->requested_reason ?: $invoice?->notes ?: '—' }}</td>
                            <td colspan="2" class="px-4 py-3">{{ $invoice?->invoicer_name ?: '—' }}</td>
                            <td colspan="2" class="px-4 py-3"><bdi dir="ltr">{{ $invoice?->original_invoice_no ?: '—' }}</bdi></td>
                            <td colspan="2" class="point-market-numeric px-4 py-3"><bdi dir="ltr">{{ \App\Support\NumberFormatter::withSuffix($invoice?->total, $invoiceCurrency?->symbol ?: $invoiceCurrency?->code, (int) ($invoiceCurrency?->decimal_places ?? 2)) }}</bdi></td>
                            <td colspan="2" class="point-market-table__action px-3 py-3">
                                <div class="point-market-row-actions">
                                    @if($availableItems->isEmpty())
                                        <span class="text-xs font-medium text-neutral-400">{{ __('course_end.point_market.all_added') }}</span>
                                    @else
                                        @if($selectedForInvoice->isNotEmpty())
                                            <button type="button" wire:click.stop="openAssignmentModal({{ $invoice->id }})" class="admin-icon-button admin-icon-button--accent" title="{{ __('course_end.point_market.add_selected_items') }}" aria-label="{{ __('course_end.point_market.add_selected_items') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg></button>
                                        @endif
                                        <button type="button" wire:click.stop="toggleInvoice({{ $invoice->id }})" class="point-market-collapse-button" aria-expanded="{{ $isExpanded ? 'true' : 'false' }}" aria-label="{{ __('course_end.point_market.added_invoices') }}"><svg class="point-market-chevron {{ $isExpanded ? 'point-market-chevron--open' : '' }}" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m7 4 6 6-6 6"/></svg></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @if($invoice && $availableItems->isNotEmpty() && $isExpanded)
                            <tr class="point-market-detail-header" wire:key="point-market-invoice-items-header-{{ $link->id }}">
                                <th class="point-market-table__select"><input type="checkbox" wire:click="toggleAllInvoiceItems({{ $invoice->id }})" @checked($availableItems->pluck('id')->every(fn($id) => collect($selectedItemIds)->map(fn($selected) => (int) $selected)->contains($id))) aria-label="{{ __('course_end.point_market.item_table.select_all') }}"></th>
                                <th class="point-market-table__number">#</th>
                                <th colspan="4" class="text-start">{{ __('course_end.point_market.item_table.item') }}</th>
                                <th colspan="2">{{ __('course_end.point_market.item_table.quantity') }}</th>
                                <th colspan="2">{{ __('course_end.point_market.item_table.unit_price') }}</th>
                                <th colspan="2">{{ __('course_end.point_market.item_table.total') }}</th>
                                <th colspan="2" aria-hidden="true"></th>
                            </tr>
                            @foreach($availableItems as $item)
                                <tr class="point-market-detail-row" wire:key="point-market-available-item-{{ $item->id }}">
                                    <td class="point-market-table__select"><input type="checkbox" wire:model.live="selectedItemIds" value="{{ $item->id }}"></td>
                                    <td class="point-market-table__number">{{ $item->line_no ?: $loop->iteration }}</td>
                                    <td colspan="4">{{ $item->item_name ?: $item->description }}</td>
                                    <td colspan="2" class="point-market-numeric"><bdi dir="ltr">{{ \App\Support\NumberFormatter::trimmed($item->quantity, 2) }}</bdi></td>
                                    <td colspan="2" class="point-market-numeric"><bdi dir="ltr">{{ \App\Support\NumberFormatter::withSuffix($item->unit_price, $invoiceCurrency?->symbol ?: $invoiceCurrency?->code, (int) ($invoiceCurrency?->decimal_places ?? 2)) }}</bdi></td>
                                    <td colspan="2" class="point-market-numeric"><bdi dir="ltr">{{ \App\Support\NumberFormatter::withSuffix($item->amount, $invoiceCurrency?->symbol ?: $invoiceCurrency?->code, (int) ($invoiceCurrency?->decimal_places ?? 2)) }}</bdi></td>
                                    <td colspan="2" aria-hidden="true"></td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr><td colspan="14" class="admin-empty-state">{{ __('course_end.point_market.no_added_invoices') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @error('selectedItemIds')<div class="px-5 py-3 text-sm text-red-400">{{ $message }}</div>@enderror
    </section>

    <section class="space-y-4">
        <div class="admin-toolbar px-1">
            <div class="admin-toolbar__title">{{ __('course_end.point_market.departments') }}</div>
            @can('courses.update')
                <button type="button" wire:click="openDepartmentModal" class="admin-icon-button admin-icon-button--accent" title="{{ __('course_end.point_market.add_department') }}" aria-label="{{ __('course_end.point_market.add_department') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg></button>
            @endcan
        </div>

        @forelse($departments as $department)
            @php($departmentExpanded = in_array($department->id, $expandedDepartmentIds, true))
            @php($invoiceCurrencyLabel = $department->items->pluck('currency_code')->filter()->unique()->values()->implode(' / ') ?: '—')
            @php($localCurrencyLabel = $department->items->pluck('local_currency_code')->filter()->unique()->values()->implode(' / ') ?: $localCurrency->code)
            <article class="surface-table" data-point-market-generic-table wire:key="point-market-department-{{ $department->id }}">
                <div class="admin-grid-meta gap-4 cursor-pointer" wire:click="toggleDepartment({{ $department->id }})">
                    <div class="point-market-department-toggle">
                        <span class="point-market-department-toggle__copy"><span class="block font-semibold text-white">{{ __('course_end.point_market.department.title', ['name' => $department->name]) }}</span><span class="mt-1 block text-xs text-neutral-400">{{ __('course_end.point_market.department.point_price') }}: <bdi dir="ltr">{{ \App\Support\NumberFormatter::withSuffix($department->point_price, $localCurrency->symbol ?: $localCurrency->code, 2) }}</bdi></span></span>
                    </div>
                    <div class="point-market-department-actions">
                        @can('courses.update')
                            <button type="button" wire:click.stop="openDepartmentSettings({{ $department->id }})" class="admin-icon-button" title="{{ __('course_end.point_market.department_settings') }}" aria-label="{{ __('course_end.point_market.department_settings') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.86 2.86-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.55v-.1A1.7 1.7 0 0 0 8.45 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.86-2.86.06-.06A1.7 1.7 0 0 0 4.05 15a1.7 1.7 0 0 0-1.5-1H2.5V10h.05a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06L6.5 4.2l.06.06A1.7 1.7 0 0 0 8.45 4a1.7 1.7 0 0 0 1-1.5V2.4h4.05v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.86 2.86-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.5 1h.1v4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg></button>
                        @endcan
                        <a href="{{ route('courses.end.point-market.departments.pdf', [$course, $department]) }}" target="_blank" rel="noopener" x-on:click.stop class="admin-icon-button" title="{{ __('course_end.point_market.export_pdf') }}" aria-label="{{ __('course_end.point_market.export_pdf') }}"><x-pdf-export-icon /></a>
                        <button type="button" wire:click.stop="toggleDepartment({{ $department->id }})" class="point-market-collapse-button" aria-expanded="{{ $departmentExpanded ? 'true' : 'false' }}" aria-label="{{ __('course_end.point_market.departments') }}"><svg class="point-market-chevron {{ $departmentExpanded ? 'point-market-chevron--open' : '' }}" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m7 4 6 6-6 6"/></svg></button>
                    </div>
                </div>
                @if($departmentExpanded)
                    <div class="point-market-table-wrap">
                        <table class="point-market-generic-table point-market-department-table text-sm">
                            <colgroup><col class="point-market-col--utility"><col class="point-market-col--utility">@for($column = 0; $column < 5; $column++)<col class="point-market-col--equal">@endfor</colgroup>
                            <thead><tr><th class="point-market-table__remove"></th><th class="point-market-table__number">#</th><th class="px-4 py-3 text-start">{{ __('course_end.point_market.department.item') }}</th><th class="px-4 py-3">{{ __('course_end.point_market.department.quantity') }}</th><th class="px-4 py-3">{{ __('course_end.point_market.department.invoice_unit_price') }} (<bdi dir="ltr">{{ $invoiceCurrencyLabel }}</bdi>)</th><th class="px-4 py-3">{{ __('course_end.point_market.department.invoice_unit_price') }} (<bdi dir="ltr">{{ $localCurrencyLabel }}</bdi>)</th><th class="px-4 py-3">{{ __('course_end.point_market.department.points') }}</th></tr></thead>
                            <tbody class="divide-y divide-white/6">
                                @forelse($department->items as $item)
                                    <tr wire:key="point-market-department-item-{{ $item->id }}"><td class="point-market-table__remove">@can('courses.update')<button type="button" wire:click="removeDepartmentItem({{ $item->id }})" class="admin-icon-button point-market-remove-item !h-8 !w-8 !basis-8" title="{{ __('course_end.point_market.remove_item') }}" aria-label="{{ __('course_end.point_market.remove_item') }}"><span aria-hidden="true">−</span></button>@endcan</td><td class="point-market-table__number">{{ $loop->iteration }}</td><td class="px-4 py-3 font-medium text-white">{{ $item->item_name }}</td><td class="point-market-numeric px-4 py-3"><bdi dir="ltr">{{ \App\Support\NumberFormatter::trimmed($item->quantity, 2) }}</bdi></td><td class="point-market-numeric px-4 py-3"><bdi dir="ltr">{{ $item->formattedAmount('unit_price') }}</bdi></td><td class="point-market-numeric px-4 py-3"><bdi dir="ltr">{{ $item->formattedAmount('local_unit_price', true) }}</bdi></td><td class="point-market-numeric px-4 py-3 font-semibold text-emerald-100">{{ number_format($item->points($department->point_price)) }}</td></tr>
                                @empty
                                    <tr><td colspan="7" class="admin-empty-state">{{ __('course_end.point_market.department.empty') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </article>
        @empty
            <div class="surface-panel p-8 text-center text-neutral-400">{{ __('course_end.point_market.no_departments') }}</div>
        @endforelse
    </section>

    <x-admin.modal :show="$showInvoiceModal" :title="__('course_end.point_market.add_invoice')" close-method="closeInvoiceModal" max-width="6xl" compact>
        <div class="surface-table settings-record-table point-market-modal-table-wrap" data-point-market-generic-table>
            <table class="point-market-generic-table point-market-modal-table text-sm">
                <thead><tr><th class="point-market-table__select"></th><th class="point-market-table__number">#</th><th class="px-3 py-3">{{ __('course_end.point_market.invoice_table.category') }}</th><th class="px-3 py-3">{{ __('course_end.point_market.invoice_table.issuer') }}</th><th class="px-3 py-3">{{ __('course_end.point_market.invoice_table.items_count') }}</th><th class="px-3 py-3">{{ __('course_end.point_market.invoice_table.total') }}</th></tr></thead>
                <tbody class="divide-y divide-white/6">
                    @forelse($availableInvoices as $invoice)
                        @php($availableInvoiceCurrency = $invoice->financeRequest?->acceptedCurrency ?: $invoice->financeRequest?->requestedCurrency)
                        <tr wire:key="available-point-market-invoice-{{ $invoice->id }}"><td class="point-market-table__select"><input type="checkbox" wire:model.live="selectedInvoiceIds" value="{{ $invoice->id }}"></td><td class="point-market-table__number">{{ $loop->iteration }}</td><td class="px-3 py-3">{{ $invoice->financeRequest?->category?->name ?: '—' }}</td><td class="px-3 py-3">{{ $invoice->invoicer_name ?: '—' }}</td><td class="point-market-numeric px-3 py-3">{{ number_format($invoice->items_count) }}</td><td class="point-market-numeric px-3 py-3"><bdi dir="ltr">{{ \App\Support\NumberFormatter::withSuffix($invoice->total, $availableInvoiceCurrency?->symbol ?: $availableInvoiceCurrency?->code, (int) ($availableInvoiceCurrency?->decimal_places ?? 2)) }}</bdi></td></tr>
                    @empty
                        <tr><td colspan="6" class="admin-empty-state">{{ __('course_end.point_market.no_available_invoices') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @error('selectedInvoiceIds')<div class="mt-3 text-sm text-red-400">{{ $message }}</div>@enderror
        <div class="point-market-modal-actions mt-5"><button type="button" wire:click="addInvoices" wire:loading.attr="disabled" @disabled(empty($selectedInvoiceIds)) class="pill-link pill-link--accent disabled:cursor-not-allowed disabled:opacity-40">{{ __('course_end.point_market.add') }}</button></div>
    </x-admin.modal>

    <x-admin.modal :show="$showDepartmentModal" :title="__('course_end.point_market.add_department')" close-method="closeDepartmentModal" max-width="xl" compact>
        <div><label class="mb-1 block text-sm font-medium">{{ __('course_end.point_market.department_name') }}</label><div class="point-market-prefixed-input"><span class="point-market-prefixed-input__prefix">{{ __('course_end.point_market.department_prefix') }}</span><input type="text" wire:model="departmentName" wire:keydown.enter="createDepartment" class="w-full px-4 py-3" placeholder="{{ __('course_end.point_market.department_name_placeholder') }}"></div>@error('departmentName')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
        <div class="point-market-modal-actions mt-5"><button type="button" wire:click="createDepartment" class="pill-link pill-link--accent">{{ __('course_end.point_market.add') }}</button></div>
    </x-admin.modal>

    <x-admin.modal :show="$showAssignmentModal" :title="__('course_end.point_market.select_department')" close-method="closeAssignmentModal" max-width="xl" compact>
        <div><label class="mb-1 block text-sm font-medium">{{ __('course_end.point_market.departments') }}</label><select wire:model="assignmentDepartmentId" class="w-full rounded-xl px-4 py-3"><option value="">{{ __('crud.common.select') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}">{{ __('course_end.point_market.department.title', ['name' => $department->name]) }}</option>@endforeach</select>@error('assignmentDepartmentId')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
        <div class="point-market-modal-actions mt-5"><button type="button" wire:click="addSelectedItems" @disabled($departments->isEmpty()) class="pill-link pill-link--accent disabled:cursor-not-allowed disabled:opacity-40">{{ __('course_end.point_market.add') }}</button></div>
    </x-admin.modal>

    <x-admin.modal :show="$showDepartmentSettingsModal" :title="__('course_end.point_market.department_settings')" close-method="closeDepartmentSettingsModal" max-width="xl" compact>
        <div><label class="mb-1 block text-sm font-medium">{{ __('course_end.point_market.department.point_price') }} ({{ $localCurrency->symbol ?: $localCurrency->code }})</label><input type="text" inputmode="decimal" data-thousand-separator wire:model="pointPrice" class="w-full rounded-xl px-4 py-3">@error('pointPrice')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
        <div class="point-market-modal-actions mt-5"><button type="button" wire:click="saveDepartmentSettings" class="pill-link pill-link--accent">{{ __('course_end.point_market.save_settings') }}</button></div>
    </x-admin.modal>
</div>
