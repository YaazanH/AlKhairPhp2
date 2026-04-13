<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Services\FinanceService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Invoice $currentInvoice;
    public ?int $editingItemId = null;
    public ?int $item_student_id = null;
    public ?int $item_enrollment_id = null;
    public ?int $item_activity_id = null;
    public string $item_description = '';
    public string $item_quantity = '1';
    public string $item_unit_price = '0';
    public ?int $payment_method_id = null;
    public string $paid_at = '';
    public string $payment_amount = '';
    public string $payment_reference_no = '';
    public string $payment_notes = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorizePermission('payments.view');
        $this->currentInvoice = Invoice::query()->with(['parentProfile'])->findOrFail($invoice->id);
        $this->authorizeScopedInvoiceAccess($this->currentInvoice);
        $this->paid_at = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'invoiceRecord' => $this->currentInvoice->fresh(['parentProfile']),
            'students' => Student::query()->where('parent_id', $this->currentInvoice->parent_id)->orderBy('first_name')->orderBy('last_name')->get(),
            'enrollments' => Enrollment::query()->with(['student', 'group'])
                ->whereHas('student', fn ($query) => $query->where('parent_id', $this->currentInvoice->parent_id))
                ->latest('enrolled_at')->get(),
            'activities' => Activity::query()->orderByDesc('activity_date')->orderBy('title')->get(),
            'items' => InvoiceItem::query()->with(['student', 'enrollment.group', 'activity'])->where('invoice_id', $this->currentInvoice->id)->latest('id')->get(),
            'payments' => Payment::query()->with(['paymentMethod', 'receivedBy', 'voidedBy'])->where('invoice_id', $this->currentInvoice->id)->latest('paid_at')->latest('id')->get(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(),
            'activePaidTotal' => Payment::query()->where('invoice_id', $this->currentInvoice->id)->whereNull('voided_at')->sum('amount'),
        ];
    }

    public function saveItem(): void
    {
        $this->authorizePermission('invoices.update');

        $validated = $this->validate([
            'item_student_id' => ['nullable', 'exists:students,id'],
            'item_enrollment_id' => ['nullable', 'exists:enrollments,id'],
            'item_activity_id' => ['nullable', 'exists:activities,id'],
            'item_description' => ['required', 'string', 'max:255'],
            'item_quantity' => ['required', 'numeric', 'gt:0'],
            'item_unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validated['item_enrollment_id']) {
            $enrollment = Enrollment::query()->findOrFail($validated['item_enrollment_id']);

            if ($validated['item_student_id'] && $enrollment->student_id !== (int) $validated['item_student_id']) {
                $this->addError('item_enrollment_id', __('invoices.detail.item_form.errors.wrong_student'));

                return;
            }

            $validated['item_student_id'] = $enrollment->student_id;
        }

        $amount = (float) $validated['item_quantity'] * (float) $validated['item_unit_price'];

        $item = InvoiceItem::query()->updateOrCreate(
            ['id' => $this->editingItemId],
            [
                'invoice_id' => $this->currentInvoice->id,
                'student_id' => $validated['item_student_id'] ?: null,
                'enrollment_id' => $validated['item_enrollment_id'] ?: null,
                'activity_id' => $validated['item_activity_id'] ?: null,
                'description' => $validated['item_description'],
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
        $this->item_student_id = $item->student_id;
        $this->item_enrollment_id = $item->enrollment_id;
        $this->item_activity_id = $item->activity_id;
        $this->item_description = $item->description;
        $this->item_quantity = number_format((float) $item->quantity, 2, '.', '');
        $this->item_unit_price = number_format((float) $item->unit_price, 2, '.', '');
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
        $validated = $this->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'paid_at' => ['required', 'date'],
            'payment_amount' => ['required', 'numeric', 'gt:0'],
            'payment_reference_no' => ['nullable', 'string', 'max:255'],
            'payment_notes' => ['nullable', 'string'],
        ]);
        Payment::query()->create([
            'invoice_id' => $this->currentInvoice->id,
            'payment_method_id' => $validated['payment_method_id'],
            'paid_at' => $validated['paid_at'],
            'amount' => $validated['payment_amount'],
            'reference_no' => $validated['payment_reference_no'] ?: null,
            'received_by' => auth()->id(),
            'notes' => $validated['payment_notes'] ?: null,
        ]);
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
        $payment->update([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => __('invoices.detail.payment_form.void_reason'),
        ]);
        app(FinanceService::class)->syncInvoiceTotals($this->currentInvoice->fresh());
        session()->flash('status', __('invoices.detail.payment_form.messages.voided'));
    }

    public function cancelItem(): void
    {
        $this->editingItemId = null;
        $this->item_student_id = null;
        $this->item_enrollment_id = null;
        $this->item_activity_id = null;
        $this->item_description = '';
        $this->item_quantity = '1';
        $this->item_unit_price = '0';
        $this->resetValidation();
    }
}; ?>

@php
    $invoiceStatusLabel = trans()->has('print.invoice.statuses.'.$invoiceRecord->status)
        ? __('print.invoice.statuses.'.$invoiceRecord->status)
        : __('print.invoice.statuses.unknown');
    $invoiceTypeLabel = trans()->has('print.invoice.types.'.$invoiceRecord->invoice_type)
        ? __('print.invoice.types.'.$invoiceRecord->invoice_type)
        : \Illuminate\Support\Str::headline((string) $invoiceRecord->invoice_type);
    $balanceDue = max((float) $invoiceRecord->total - (float) $activePaidTotal, 0);
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
                <div class="mt-1 text-sm text-neutral-400">{{ $invoiceRecord->parentProfile?->father_name ?: '-' }} | {{ $invoiceTypeLabel }}</div>
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
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.detail.summary.paid') }}</div><div class="metric-value mt-3">{{ number_format((float) $activePaidTotal, 2) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('invoices.detail.summary.balance') }}</div><div class="metric-value mt-3">{{ number_format($balanceDue, 2) }}</div></article>
    </section>

    <div class="grid gap-6 xl:grid-cols-[23rem_23rem_minmax(0,1fr)]">
        <section class="space-y-6">
            <div class="surface-panel p-5 lg:p-6">
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ $editingItemId ? __('invoices.detail.item_form.edit_title') : __('invoices.detail.item_form.create_title') }}</div>
                    <p class="admin-section-card__copy">{{ __('invoices.detail.tables.items.title') }}</p>
                </div>
                <form wire:submit="saveItem" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.description') }}</label>
                        <input wire:model="item_description" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('item_description') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.student') }}</label>
                        <select wire:model="item_student_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('invoices.detail.item_form.placeholders.student') }}</option>
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}">{{ $student->first_name }} {{ $student->last_name }}</option>
                            @endforeach
                        </select>
                        @error('item_student_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.enrollment') }}</label>
                        <select wire:model="item_enrollment_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('invoices.detail.item_form.placeholders.enrollment') }}</option>
                            @foreach ($enrollments as $enrollment)
                                <option value="{{ $enrollment->id }}">{{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }} | {{ $enrollment->group?->name }}</option>
                            @endforeach
                        </select>
                        @error('item_enrollment_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.activity') }}</label>
                        <select wire:model="item_activity_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('invoices.detail.item_form.placeholders.activity') }}</option>
                            @foreach ($activities as $activity)
                                <option value="{{ $activity->id }}">{{ $activity->title }} | {{ $activity->activity_date?->format('Y-m-d') }}</option>
                            @endforeach
                        </select>
                        @error('item_activity_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.quantity') }}</label>
                            <input wire:model="item_quantity" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('item_quantity') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.item_form.fields.unit_price') }}</label>
                            <input wire:model="item_unit_price" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
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

            <div class="surface-panel p-5 lg:p-6">
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ __('invoices.detail.payment_form.title') }}</div>
                    <p class="admin-section-card__copy">{{ __('invoices.detail.tables.payments.title') }}</p>
                </div>
                <form wire:submit="savePayment" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.payment_form.fields.method') }}</label>
                        <select wire:model="payment_method_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('invoices.detail.payment_form.placeholders.method') }}</option>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                        @error('payment_method_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.payment_form.fields.paid_at') }}</label>
                            <input wire:model="paid_at" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('paid_at') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.payment_form.fields.amount') }}</label>
                            <input wire:model="payment_amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('payment_amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.payment_form.fields.reference') }}</label>
                        <input wire:model="payment_reference_no" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('payment_reference_no') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.detail.payment_form.fields.notes') }}</label>
                        <textarea wire:model="payment_notes" rows="3" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                    </div>
                    <button type="submit" class="pill-link pill-link--accent">{{ __('invoices.detail.payment_form.save') }}</button>
                </form>
            </div>
        </section>

        <section class="space-y-6 xl:col-span-2">
            <div class="surface-table">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('invoices.detail.tables.items.title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($items->count())]) }}</div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.items.headers.description') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.items.headers.links') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.items.headers.qty') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.items.headers.amount') }}</th><th class="px-5 py-4 text-right lg:px-6">{{ __('invoices.detail.tables.items.headers.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse ($items as $item)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="font-medium text-white">{{ $item->description }}</div>
                                        <div class="text-xs text-neutral-500">{{ __('invoices.detail.item_form.unit_price', ['amount' => number_format((float) $item->unit_price, 2)]) }}</div>
                                    </td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="text-neutral-200">{{ $item->student ? $item->student->first_name.' '.$item->student->last_name : '-' }}</div>
                                        <div class="text-xs text-neutral-500">{{ $item->activity?->title ?: ($item->enrollment?->group?->name ?: '-') }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((float) $item->quantity, 2) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((float) $item->amount, 2) }}</td>
                                    <td class="px-5 py-4 lg:px-6"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editItem({{ $item->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deleteItem({{ $item->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('invoices.detail.item_form.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="surface-table">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('invoices.detail.tables.payments.title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($payments->count())]) }}</div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.payments.headers.date') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.payments.headers.method') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.payments.headers.amount') }}</th><th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.detail.tables.payments.headers.state') }}</th><th class="px-5 py-4 text-right lg:px-6">{{ __('invoices.detail.tables.payments.headers.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse ($payments as $payment)
                                <tr class="{{ $payment->voided_at ? 'opacity-60' : '' }}">
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $payment->paid_at?->format('Y-m-d') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $payment->paymentMethod?->name ?: '-' }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((float) $payment->amount, 2) }}</td>
                                    <td class="px-5 py-4 lg:px-6"><span class="status-chip {{ $payment->voided_at ? 'status-chip--rose' : 'status-chip--emerald' }}">{{ __('print.states.'.($payment->voided_at ? 'voided' : 'active')) }}</span></td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            <a href="{{ route('payments.receipt', $payment) }}" target="_blank" class="pill-link pill-link--compact">{{ __('invoices.detail.tables.payments.receipt') }}</a>
                                            @if (! $payment->voided_at)
                                                <button type="button" wire:click="voidPayment({{ $payment->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('invoices.detail.tables.payments.void') }}</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('invoices.detail.tables.payments.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
