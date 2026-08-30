<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Invoice;
use App\Models\FinanceInvoiceKind;
use App\Services\FinanceService;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use FormatsFinanceNumbers;
    use SupportsCreateAndNew;
    use WithFileUploads;
    use WithPagination;

    public ?int $editingId = null;
    public string $invoice_no = '';
    public string $original_invoice_no = '';
    public string $invoicer_name = '';
    public ?int $finance_invoice_kind_id = null;
    public string $invoice_type = 'finance';
    public string $issue_date = '';
    public string $due_date = '';
    public string $status = 'draft';
    public string $discount = '0';
    public string $notes = '';
    public $invoice_scan = null;
    public bool $remove_invoice_scan = false;
    public int $perPage = 15;
    public bool $showForm = false;

    public function mount(): void
    {
        $this->authorizePermission('invoices.view');
        $this->invoice_no = app(FinanceService::class)->nextInvoiceNumber();
        $this->issue_date = now()->toDateString();
        $this->finance_invoice_kind_id = app(FinanceService::class)->defaultInvoiceKindId();
    }

    public function with(): array
    {
        $invoiceQuery = $this->scopeInvoicesQuery(
            Invoice::query()
                ->with(['financeRequest', 'invoiceKind', 'parentProfile'])
                ->withCount(['items'])
                ->withSum(['payments as active_paid_total' => fn ($query) => $query->whereNull('voided_at')], 'amount')
                ->latest('issue_date')
                ->latest('id')
        );

        return [
            'invoices' => $invoiceQuery->paginate($this->perPage),
            'invoiceKinds' => FinanceInvoiceKind::query()->where('is_active', true)->orderBy('name')->get(),
            'totals' => [
                'all' => $this->scopeInvoicesQuery(Invoice::query())->count(),
                'open' => $this->scopeInvoicesQuery(Invoice::query()->whereIn('status', ['issued', 'partial']))->count(),
                'draft' => $this->scopeInvoicesQuery(Invoice::query()->where('status', 'draft'))->count(),
                'outstanding' => $this->scopeInvoicesQuery(
                    Invoice::query()->withSum(['payments as active_paid_total' => fn ($query) => $query->whereNull('voided_at')], 'amount')
                )->get()
                    ->sum(fn (Invoice $invoice) => max((float) $invoice->total - (float) ($invoice->active_paid_total ?? 0), 0)),
            ],
            'filteredCount' => (clone $invoiceQuery)->count(),
        ];
    }

    public function rules(): array
    {
        return [
            'finance_invoice_kind_id' => ['nullable', 'exists:finance_invoice_kinds,id'],
            'original_invoice_no' => ['nullable', 'string', 'max:255'],
            'invoicer_name' => ['required', 'string', 'max:255'],
            'invoice_type' => ['required', 'string', 'max:50'],
            'issue_date' => ['required', 'date'],
            'discount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'invoice_scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.config('uploads.image_max_kb')],
            'remove_invoice_scan' => ['boolean'],
        ];
    }

    public function create(): void
    {
        $this->authorizePermission('invoices.create');

        $this->cancel(closeForm: false);
        $this->invoice_no = app(FinanceService::class)->nextInvoiceNumber();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'invoices.update' : 'invoices.create');
        $this->normalizeFinanceNumberProperty('discount');

        $validated = $this->validate();
        $existingInvoice = $this->editingId ? Invoice::query()->findOrFail($this->editingId) : null;
        $systemInvoiceNumber = $existingInvoice?->invoice_no ?: app(FinanceService::class)->nextInvoiceNumber();
        $canUpdateEntryDate = auth()->user()?->can('finance.entries.update') ?? false;

        $scanPath = $existingInvoice?->original_image_path;
        if ($validated['remove_invoice_scan'] && $scanPath) {
            Storage::disk('public')->delete($scanPath);
            $scanPath = null;
        }
        if ($validated['invoice_scan'] ?? null) {
            if ($scanPath) {
                Storage::disk('public')->delete($scanPath);
            }
            $scanPath = $validated['invoice_scan']->store('finance/invoices/scans', 'public');
        }

        $invoice = Invoice::query()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'invoice_no' => $systemInvoiceNumber,
                'original_invoice_no' => $validated['original_invoice_no'] ?: null,
                'invoicer_name' => $validated['invoicer_name'],
                'invoice_type' => $validated['invoice_type'],
                'finance_invoice_kind_id' => $validated['finance_invoice_kind_id'] ?: app(FinanceService::class)->defaultInvoiceKindId(),
                'issue_date' => $canUpdateEntryDate
                    ? $validated['issue_date']
                    : ($existingInvoice?->issue_date?->toDateString() ?? now()->toDateString()),
                'due_date' => $existingInvoice?->due_date,
                'status' => $existingInvoice?->status ?? 'draft',
                'discount' => $validated['discount'],
                'notes' => $existingInvoice ? ($validated['notes'] ?: null) : null,
                'original_image_path' => $scanPath,
            ],
        );

        app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());

        session()->flash(
            'status',
            $this->editingId ? __('invoices.index.messages.updated') : __('invoices.index.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $invoiceId): void
    {
        $this->authorizePermission('invoices.update');

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->authorizeScopedInvoiceAccess($invoice);

        $this->editingId = $invoice->id;
        $this->invoice_no = $invoice->invoice_no;
        $this->original_invoice_no = $invoice->original_invoice_no ?? '';
        $this->invoicer_name = $invoice->invoicer_name ?? '';
        $this->finance_invoice_kind_id = $invoice->finance_invoice_kind_id;
        $this->invoice_type = $invoice->invoice_type;
        $this->issue_date = $invoice->issue_date?->format('Y-m-d') ?? '';
        $this->due_date = $invoice->due_date?->format('Y-m-d') ?? '';
        $this->status = $invoice->status;
        $this->discount = $this->formatFinanceNumberForInput($invoice->discount);
        $this->notes = $invoice->notes ?? '';
        $this->invoice_scan = null;
        $this->remove_invoice_scan = false;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function cancel(bool $closeForm = true): void
    {
        $this->editingId = null;
        $this->invoice_no = '';
        $this->original_invoice_no = '';
        $this->invoicer_name = '';
        $this->finance_invoice_kind_id = app(FinanceService::class)->defaultInvoiceKindId();
        $this->invoice_type = 'finance';
        $this->issue_date = now()->toDateString();
        $this->due_date = '';
        $this->status = 'draft';
        $this->discount = '0';
        $this->notes = '';
        $this->invoice_scan = null;
        $this->remove_invoice_scan = false;

        if ($closeForm) {
            $this->showForm = false;
        }

        $this->resetValidation();
    }

    public function delete(int $invoiceId): void
    {
        $this->authorizePermission('invoices.delete');

        $invoice = Invoice::query()
            ->withCount(['items', 'payments'])
            ->findOrFail($invoiceId);
        $this->authorizeScopedInvoiceAccess($invoice);

        if ($invoice->items_count > 0 || $invoice->payments_count > 0) {
            $this->addError('delete', __('invoices.index.errors.delete_linked'));

            return;
        }

        if ($invoice->original_image_path) {
            Storage::disk('public')->delete($invoice->original_image_path);
        }
        $invoice->delete();

        if ($this->editingId === $invoiceId) {
            $this->cancel();
        }

        session()->flash('status', __('invoices.index.messages.deleted'));
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_24rem] xl:items-start">
            <div>
                <div class="eyebrow">{{ __('invoices.index.hero.eyebrow') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('invoices.index.hero.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('invoices.index.hero.subtitle') }}</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <span class="badge-soft badge-soft--emerald">{{ __('invoices.index.hero.badges.invoices', ['count' => number_format($filteredCount)]) }}</span>
                </div>
            </div>

            <aside class="surface-panel surface-panel--soft p-5 lg:p-6">
                <div class="eyebrow">{{ __('invoices.index.focus.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ $editingId ? __('invoices.index.focus.edit_title') : __('invoices.index.focus.create_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('invoices.index.focus.subtitle') }}</p>
            </aside>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.index.stats.all.label') }}</div><div class="metric-value mt-6">{{ number_format($totals['all']) }}</div><p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('invoices.index.stats.all.hint') }}</p></article>
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.index.stats.open.label') }}</div><div class="metric-value mt-6">{{ number_format($totals['open']) }}</div><p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('invoices.index.stats.open.hint') }}</p></article>
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.index.stats.draft.label') }}</div><div class="metric-value mt-6">{{ number_format($totals['draft']) }}</div><p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('invoices.index.stats.draft.hint') }}</p></article>
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.index.stats.outstanding.label') }}</div><div class="metric-value mt-6">{{ number_format((float) $totals['outstanding'], 2) }}</div><p class="mt-4 text-sm leading-6 text-neutral-300">{{ __('invoices.index.stats.outstanding.hint') }}</p></article>
    </div>

    <div class="space-y-6">
        @if ($showForm)
        <section class="admin-modal" role="dialog" aria-modal="true">
            <div class="admin-modal__backdrop" wire:click="cancel"></div>
            <div class="admin-modal__viewport">
                <div class="admin-modal__dialog admin-modal__dialog--3xl">
                    <div class="admin-modal__header">
                        <div>
                            <div class="admin-modal__title">{{ $editingId ? __('invoices.index.form.edit_title') : __('invoices.index.form.create_title') }}</div>
                        </div>
                        <button type="button" wire:click="cancel" class="admin-modal__close" aria-label="{{ __('crud.common.actions.cancel') }}">×</button>
                    </div>
                    <div class="admin-modal__body">
            @if (auth()->user()->can('invoices.create') || auth()->user()->can('invoices.update'))
                <form wire:submit="save" class="space-y-4">
                    <input wire:model="invoice_type" type="hidden">

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.invoicer_name') }}</label>
                            <input wire:model="invoicer_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('invoicer_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.original_invoice_no') }}</label>
                            <input wire:model="original_invoice_no" type="text" dir="ltr" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('original_invoice_no') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.invoice_kind') }}</label>
                            <select wire:model="finance_invoice_kind_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@foreach ($invoiceKinds as $kind)<option value="{{ $kind->id }}">{{ $kind->name }}</option>@endforeach</select>
                            @error('finance_invoice_kind_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('finance.common.date') }}</label>
                            <input wire:model="issue_date" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" @disabled(! auth()->user()?->can('finance.entries.update'))>
                            @error('issue_date') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.discount') }}</label>
                            <input wire:model="discount" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('discount') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.invoice_scan') }}</label>
                        <input wire:model="invoice_scan" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('invoice_scan') <div data-pdf-upload-error-for="invoice_scan" class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        @if ($editingId && \App\Models\Invoice::query()->find($editingId)?->original_image_path)
                            <label class="mt-2 flex items-center gap-2 text-sm text-red-300"><input wire:model="remove_invoice_scan" type="checkbox" class="rounded">{{ __('finance.actions.remove_scan') }}</label>
                        @endif
                    </div>

                    @if ($editingId)<div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.notes') }}</label>
                        <textarea wire:model="notes" rows="4" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </div>@endif

                    @error('delete') <div class="rounded-xl border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-300">{{ $message }}</div> @enderror

                    <div class="flex flex-wrap gap-3">
                        @if ($editingId)
                            <button type="submit" class="pill-link pill-link--accent">{{ __('invoices.index.form.update_submit') }}</button>
                        @else
                            <x-admin.create-and-new-button click="saveAndNew('save', 'create')" />
                        @endif
                    </div>
                </form>
            @else
                <div class="text-sm leading-7 text-neutral-300">{{ __('invoices.index.read_only') }}</div>
            @endif
                    </div>
                </div>
            </div>
        </section>
        @endif

        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="eyebrow">{{ __('invoices.index.table.eyebrow') }}</div>
                        <h2 class="font-display mt-3 text-2xl text-white">{{ __('invoices.index.table.title') }}</h2>
                    </div>
                    <div class="admin-action-cluster admin-action-cluster--end">
                        <span class="badge-soft">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</span>
                        @can('invoices.create')
                            <x-add-action-button wire:click="create" :label="__('invoices.index.form.create_title')" />
                        @endcan
                    </div>
                </div>
            </div>

            @if ($invoices->isEmpty())
                <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('invoices.index.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.index.table.headers.invoice') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('finance.fields.invoicer_name') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.index.table.headers.amounts') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.index.table.headers.status') }}</th>
                                <th class="admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('invoices.index.table.headers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($invoices as $invoice)
                                @php
                                    $invoiceStatusClass = match ($invoice->status) {
                                        'paid' => 'status-chip status-chip--emerald',
                                        'partial', 'issued' => 'status-chip status-chip--gold',
                                        'cancelled' => 'status-chip status-chip--rose',
                                        default => 'status-chip status-chip--slate',
                                    };
                                @endphp
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="font-semibold text-white">{{ $invoice->invoice_no }}</div>
                                        <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $invoice->invoiceKind?->name ?: $invoice->invoice_type }} | {{ $invoice->issue_date?->format('d-m-Y') }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6"><div>{{ $invoice->invoicer_name ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $invoice->financeRequest?->request_no ?: '-' }}</div></td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="text-white">{{ __('invoices.index.table.amounts.total', ['amount' => number_format((float) $invoice->total, 2)]) }}</div>
                                        <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('invoices.index.table.amounts.meta', ['paid' => number_format((float) ($invoice->active_paid_total ?? 0), 2), 'items' => number_format($invoice->items_count)]) }}</div>
                                    </td>
                                    <td class="px-5 py-4 lg:px-6"><span class="{{ $invoiceStatusClass }}">{{ trans()->has('print.invoice.statuses.'.$invoice->status) ? __('print.invoice.statuses.'.$invoice->status) : __('print.invoice.statuses.unknown') }}</span></td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @can('invoices.view')
                                                <a href="{{ route('invoices.payments', $invoice) }}" wire:navigate class="admin-icon-button" title="{{ __('invoices.index.table.actions.detail') }}" aria-label="{{ __('invoices.index.table.actions.detail') }}" data-invoice-receipt-action><x-admin-action-icon name="receipt" /></a>
                                            @endcan
                                            @can('invoices.view')
                                                <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="pill-link pill-link--compact">{{ __('invoices.index.table.actions.print') }}</a>
                                            @endcan
                                            @if ($invoice->original_image_path)
                                                <a href="{{ asset('storage/'.$invoice->original_image_path) }}" target="_blank" class="pill-link pill-link--compact">{{ __('finance.actions.view_original') }}</a>
                                            @endif
                                            @can('invoices.delete')
                                                <button type="button" wire:click="delete({{ $invoice->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($invoices->hasPages())
                    <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                        {{ $invoices->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</div>
