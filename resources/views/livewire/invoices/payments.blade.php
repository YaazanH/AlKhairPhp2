<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Models\FinanceRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\FinanceService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use FormatsFinanceNumbers;

    public Invoice $currentInvoice;
    public ?int $editingItemId = null;
    public string $item_name = '';
    public string $item_quantity = '1';
    public string $item_unit_price = '0';
    public ?int $payment_method_id = null;
    public string $paid_at = '';
    public string $payment_amount = '';
    public string $payment_reference_no = '';
    public string $payment_notes = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorizePermission('invoices.view');
        $this->currentInvoice = Invoice::query()->with(['parentProfile'])->findOrFail($invoice->id);
        $this->authorizeScopedInvoiceAccess($this->currentInvoice);
        $this->paid_at = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'invoiceRecord' => $this->currentInvoice->fresh(['financeRequest.pullRequestKind', 'invoiceKind', 'parentProfile']),
            'items' => InvoiceItem::query()->where('invoice_id', $this->currentInvoice->id)->orderBy('line_no')->orderBy('id')->get(),
        ];
    }

    public function saveItem(): void
    {
        $this->authorizePermission('invoices.update');
        $this->normalizeFinanceNumberProperty('item_quantity');
        $this->normalizeFinanceNumberProperty('item_unit_price');

        $validated = $this->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'item_quantity' => ['required', 'numeric', 'gt:0'],
            'item_unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $amount = (float) $validated['item_quantity'] * (float) $validated['item_unit_price'];
        $lineNo = $this->editingItemId
            ? InvoiceItem::query()->where('invoice_id', $this->currentInvoice->id)->findOrFail($this->editingItemId)->line_no
            : ((int) InvoiceItem::query()->where('invoice_id', $this->currentInvoice->id)->max('line_no') + 1);

        $item = InvoiceItem::query()->updateOrCreate(
            ['id' => $this->editingItemId],
            [
                'invoice_id' => $this->currentInvoice->id,
                'line_no' => $lineNo,
                'item_name' => $validated['item_name'],
                'description' => $validated['item_name'],
                'quantity' => $validated['item_quantity'],
                'unit_price' => $validated['item_unit_price'],
                'amount' => $amount,
            ],
        );

        app(FinanceService::class)->syncInvoiceTotals($item->invoice->fresh());
        session()->flash('status', $this->editingItemId ? __('invoices.detail.item_form.messages.updated') : __('invoices.detail.item_form.messages.created'));
        $this->cancelItem();
    }

    public function editItem(int $itemId): void
    {
        $this->authorizePermission('invoices.update');
        $item = InvoiceItem::query()->where('invoice_id', $this->currentInvoice->id)->findOrFail($itemId);
        $this->editingItemId = $item->id;
        $this->item_name = $item->item_name ?: $item->description;
        $this->item_quantity = $this->formatFinanceNumberForInput($item->quantity);
        $this->item_unit_price = $this->formatFinanceNumberForInput($item->unit_price);
        $this->resetErrorBag();
    }

    public function deleteItem(int $itemId): void
    {
        $this->authorizePermission('invoices.update');
        $item = InvoiceItem::query()->where('invoice_id', $this->currentInvoice->id)->findOrFail($itemId);
        $item->delete();
        if ($this->editingItemId === $itemId) {
            $this->cancelItem();
        }
        app(FinanceService::class)->syncInvoiceTotals($this->currentInvoice->fresh());
        session()->flash('status', __('invoices.detail.item_form.messages.deleted'));
    }

    public function savePayment(): void
    {
        $this->authorizePermission('payments.create');
        $this->normalizeFinanceNumberProperty('payment_amount');

        $validated = $this->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'paid_at' => ['required', 'date'],
            'payment_amount' => ['required', 'numeric', 'gt:0'],
            'payment_reference_no' => ['nullable', 'string', 'max:255'],
            'payment_notes' => ['nullable', 'string'],
        ]);
        $payment = Payment::query()->create([
            'invoice_id' => $this->currentInvoice->id,
            'payment_method_id' => $validated['payment_method_id'],
            'paid_at' => $validated['paid_at'],
            'amount' => $validated['payment_amount'],
            'reference_no' => $validated['payment_reference_no'] ?: null,
            'received_by' => auth()->id(),
            'notes' => $validated['payment_notes'] ?: null,
        ]);
        app(FinanceService::class)->recordInvoicePayment($payment);
        app(FinanceService::class)->syncInvoiceTotals($this->currentInvoice->fresh());
        $this->payment_method_id = null;
        $this->paid_at = now()->toDateString();
        $this->payment_amount = '';
        $this->payment_reference_no = '';
        $this->payment_notes = '';
        session()->flash('status', __('invoices.detail.payment_form.messages.created'));
    }

    public function voidPayment(int $paymentId): void
    {
        $this->authorizePermission('payments.void');
        $payment = Payment::query()->where('invoice_id', $this->currentInvoice->id)->findOrFail($paymentId);
        if ($payment->voided_at) {
            return;
        }
        DB::transaction(function () use ($payment): void {
            app(FinanceService::class)->reverseSourceTransactions(Payment::class, $payment->id, auth()->user(), __('invoices.detail.payment_form.void_reason'));
            $payment->update([
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => __('invoices.detail.payment_form.void_reason'),
            ]);
            app(FinanceService::class)->syncInvoiceTotals($this->currentInvoice->fresh());
        });

        session()->flash('status', __('invoices.detail.payment_form.messages.voided'));
    }

    public function settleLinkedPullRequest(): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $invoice = $this->currentInvoice->fresh(['financeRequest']);
        $request = $invoice->financeRequest;

        abort_unless($request instanceof FinanceRequest, 404);

        app(FinanceService::class)->settleInvoicePullRequest($request, $invoice, auth()->user());
        session()->flash('status', __('finance.messages.pull_settled'));
    }

    public function cancelItem(): void
    {
        $this->editingItemId = null;
        $this->item_name = '';
        $this->item_quantity = '1';
        $this->item_unit_price = '0';
        $this->resetValidation();
    }
}; ?>

@php
    $invoiceStatusLabel = trans()->has('print.invoice.statuses.'.$invoiceRecord->status)
        ? __('print.invoice.statuses.'.$invoiceRecord->status)
        : __('print.invoice.statuses.unknown');
    $invoiceTypeLabel = $invoiceRecord->invoiceKind?->name
        ?: (trans()->has('print.invoice.types.'.$invoiceRecord->invoice_type)
            ? __('print.invoice.types.'.$invoiceRecord->invoice_type)
            : \Illuminate\Support\Str::headline((string) $invoiceRecord->invoice_type));
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <a href="{{ route('invoices.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('invoices.detail.back') }}</a>
            <div class="eyebrow mt-4">{{ __('ui.nav.finance') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('invoices.detail.heading') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('invoices.detail.subheading') }}</p>
        </div>
        <div class="flex flex-col gap-3 lg:items-end">
            <a href="{{ route('invoices.print', $invoiceRecord) }}" target="_blank" class="pill-link">
                {{ __('invoices.detail.print') }}
            </a>
            <div class="surface-panel px-5 py-4">
                <div class="text-sm font-semibold text-white">{{ $invoiceRecord->invoice_no }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ $invoiceRecord->invoicer_name ?: ($invoiceRecord->parentProfile?->father_name ?: '-') }} | {{ $invoiceTypeLabel }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ __('invoices.detail.summary.status', ['status' => $invoiceStatusLabel]) }}</div>
            </div>
        </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.detail.summary.subtotal') }}</div><div class="metric-value mt-3">{{ number_format((float) $invoiceRecord->subtotal, 2) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.detail.summary.discount') }}</div><div class="metric-value mt-3">{{ number_format((float) $invoiceRecord->discount, 2) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.detail.summary.total') }}</div><div class="metric-value mt-3">{{ number_format((float) $invoiceRecord->total, 2) }}</div></article>
    </section>

    <div class="grid gap-6 xl:grid-cols-[23rem_minmax(0,1fr)]">
        <section class="space-y-6">
            <div class="surface-panel p-5 lg:p-6">
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ $editingItemId ? __('invoices.detail.item_form.edit_title') : __('invoices.detail.item_form.create_title') }}</div>
                    <p class="admin-section-card__copy">{{ __('invoices.detail.tables.items.title') }}</p>
                </div>
                <form wire:submit="saveItem" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.item_name') }}</label>
                        <input wire:model="item_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('item_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.quantity') }}</label>
                            <input wire:model="item_quantity" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('item_quantity') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.unit_price') }}</label>
                            <input wire:model="item_unit_price" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('item_unit_price') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="pill-link pill-link--accent">{{ $editingItemId ? __('invoices.detail.item_form.update') : __('invoices.detail.item_form.save') }}</button>
                        @if ($editingItemId)
                            <button type="button" wire:click="cancelItem" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                        @endif
                    </div>
                </form>
            </div>

            @if ($invoiceRecord->financeRequest && $invoiceRecord->financeRequest->status === \App\Models\FinanceRequest::STATUS_ACCEPTED)
                <div class="surface-panel p-5 lg:p-6">
                    <div class="admin-section-card__title">{{ __('finance.pull_requests.close_invoice_cycle') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-300">{{ __('finance.pull_requests.close_invoice_cycle_hint') }}</p>
                    <button type="button" wire:click="settleLinkedPullRequest" class="mt-4 pill-link pill-link--accent">{{ __('finance.actions.finish_cycle') }}</button>
                </div>
            @endif
        </section>

        <section class="space-y-6">
            <div class="surface-table">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('invoices.detail.tables.items.title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($items->count())]) }}</div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr><th class="px-5 py-4 text-left lg:px-6">#</th><th class="px-5 py-4 text-left lg:px-6">{{ __('finance.fields.item_name') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.items.headers.qty') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('finance.fields.unit_price') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.items.headers.amount') }}</th><th class="px-5 py-4 text-right lg:px-6">{{ __('invoices.detail.tables.items.headers.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse ($items as $item)
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $item->line_no ?: $loop->iteration }}</td>
                                    <td class="px-5 py-4 lg:px-6"><div class="font-medium text-white">{{ $item->item_name ?: $item->description }}</div></td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((float) $item->quantity, 2) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((float) $item->amount, 2) }}</td>
                                    <td class="px-5 py-4 lg:px-6"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editItem({{ $item->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deleteItem({{ $item->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('invoices.detail.item_form.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
