<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Services\FinanceService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use FormatsFinanceNumbers;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $student_id = null;
    public string $issue_date = '';
    public string $due_date = '';
    public string $description = '';
    public string $quantity = '1';
    public string $unit_price = '0';
    public string $discount = '0';
    public string $notes = '';
    public bool $showForm = false;
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorizePermission('invoices.view');
        $this->issue_date = now()->toDateString();
    }

    public function with(): array
    {
        $query = $this->scopeInvoicesQuery(
            Invoice::query()
                ->where('invoice_type', '!=', 'finance')
                ->with(['student', 'items'])
                ->withSum(['payments as active_paid_total' => fn ($payments) => $payments->whereNull('voided_at')], 'amount')
                ->latest('issue_date')
                ->latest('id')
        );

        return [
            'invoices' => $query->paginate($this->perPage),
            'students' => $this->scopeStudentsQuery(Student::query()->where('status', 'active'))->orderBy('first_name')->orderBy('last_name')->get(),
            'totals' => [
                'count' => (clone $query)->count(),
                'billed' => (float) (clone $query)->sum('total'),
                'outstanding' => (clone $query)->get()->sum(fn (Invoice $invoice) => max((float) $invoice->total - (float) ($invoice->active_paid_total ?? 0), 0)),
            ],
        ];
    }

    public function create(): void
    {
        $this->authorizePermission('invoices.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $invoiceId): void
    {
        $this->authorizePermission('invoices.update');
        $invoice = Invoice::query()->whereNotNull('student_id')->where('invoice_type', '!=', 'finance')->with('items')->findOrFail($invoiceId);
        $this->authorizeScopedInvoiceAccess($invoice);
        $item = $invoice->items->first();
        $this->editingId = $invoice->id;
        $this->student_id = $invoice->student_id;
        $this->issue_date = $invoice->issue_date?->toDateString() ?? '';
        $this->due_date = $invoice->due_date?->toDateString() ?? '';
        $this->description = $item?->description ?? '';
        $this->quantity = $this->formatFinanceNumberForInput($item?->quantity ?? 1);
        $this->unit_price = $this->formatFinanceNumberForInput($item?->unit_price ?? 0);
        $this->discount = $this->formatFinanceNumberForInput($invoice->discount);
        $this->notes = $invoice->notes ?? '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'invoices.update' : 'invoices.create');
        foreach (['quantity', 'unit_price', 'discount'] as $property) {
            $this->normalizeFinanceNumberProperty($property);
        }
        $validated = $this->validate([
            'student_id' => ['required', 'exists:students,id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $subtotal = round((float) $validated['quantity'] * (float) $validated['unit_price'], 2);
        if ((float) $validated['discount'] > $subtotal) {
            $this->addError('discount', __('student_billing.validation.discount'));
            return;
        }

        DB::transaction(function () use ($validated): void {
            $student = Student::query()->findOrFail($validated['student_id']);
            $this->authorizeScopedStudentAccess($student);
            $invoice = $this->editingId
                ? Invoice::query()->whereNotNull('student_id')->where('invoice_type', '!=', 'finance')->findOrFail($this->editingId)
                : new Invoice(['invoice_no' => app(FinanceService::class)->nextInvoiceNumber(), 'invoice_type' => 'student']);
            $invoice->fill([
                'student_id' => $student->id,
                'parent_id' => $student->parent_id,
                'invoicer_name' => $student->full_name,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?: null,
                'status' => $invoice->status ?: 'draft',
                'discount' => $validated['discount'],
                'notes' => $validated['notes'] ?: null,
            ])->save();

            $item = $invoice->items()->orderBy('line_no')->first() ?? new InvoiceItem(['invoice_id' => $invoice->id, 'line_no' => 1]);
            $item->fill([
                'student_id' => $student->id,
                'item_name' => $validated['description'],
                'description' => $validated['description'],
                'quantity' => $validated['quantity'],
                'unit_price' => $validated['unit_price'],
                'amount' => round((float) $validated['quantity'] * (float) $validated['unit_price'], 2),
            ])->save();
            app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());
        });

        session()->flash('status', __('student_billing.messages.saved'));
        $this->cancel();
    }

    public function delete(int $invoiceId): void
    {
        $this->authorizePermission('invoices.delete');
        $invoice = Invoice::query()->whereNotNull('student_id')->where('invoice_type', '!=', 'finance')->withCount('payments')->findOrFail($invoiceId);
        $this->authorizeScopedInvoiceAccess($invoice);
        if ($invoice->payments_count > 0) {
            $this->addError('delete', __('student_billing.validation.paid_delete'));
            return;
        }
        DB::transaction(function () use ($invoice): void {
            $invoice->items()->delete();
            $invoice->delete();
        });
        session()->flash('status', __('student_billing.messages.deleted'));
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->student_id = null;
        $this->issue_date = now()->toDateString();
        $this->due_date = '';
        $this->description = '';
        $this->quantity = '1';
        $this->unit_price = '0';
        $this->discount = '0';
        $this->notes = '';
        $this->resetValidation();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div><div class="eyebrow">{{ __('student_billing.eyebrow') }}</div><h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('student_billing.title') }}</h1><p class="mt-4 max-w-3xl text-neutral-200">{{ __('student_billing.subtitle') }}</p></div>
            @can('invoices.create')<x-add-action-button wire:click="create" :label="__('student_billing.create')" />@endcan
        </div>
    </section>

    @if (session('status'))<div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>@endif
    @error('delete')<div class="rounded-xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>@enderror

    <section class="admin-kpi-grid">
        <article class="stat-card"><div class="kpi-label">{{ __('student_billing.stats.invoices') }}</div><div class="metric-value mt-3">{{ number_format($totals['count']) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('student_billing.stats.billed') }}</div><div class="metric-value mt-3">{{ number_format($totals['billed'], 2) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('student_billing.stats.outstanding') }}</div><div class="metric-value mt-3">{{ number_format($totals['outstanding'], 2) }}</div></article>
    </section>

    @if ($showForm)
    <section class="admin-modal" role="dialog" aria-modal="true">
        <div class="admin-modal__backdrop" wire:click="cancel"></div>
        <div class="admin-modal__panel max-w-4xl"><div class="admin-modal__header"><div><div class="admin-modal__eyebrow">{{ __('student_billing.eyebrow') }}</div><div class="admin-modal__title">{{ $editingId ? __('student_billing.edit') : __('student_billing.create') }}</div></div><button type="button" wire:click="cancel" class="admin-modal__close">×</button></div>
            <form wire:submit="save" class="admin-modal__body grid gap-4 md:grid-cols-2">
                <label class="text-sm md:col-span-2">{{ __('student_billing.fields.student') }}<select wire:model="student_id" class="mt-1 w-full rounded-xl px-4 py-3"><option value="">{{ __('student_billing.fields.choose_student') }}</option>@foreach ($students as $student)<option value="{{ $student->id }}">{{ $student->full_name }}@if($student->student_number) — {{ $student->student_number }}@endif</option>@endforeach</select>@error('student_id')<span class="mt-1 block text-red-400">{{ $message }}</span>@enderror</label>
                <label class="text-sm">{{ __('student_billing.fields.issue_date') }}<input wire:model="issue_date" type="date" class="mt-1 w-full rounded-xl px-4 py-3">@error('issue_date')<span class="mt-1 block text-red-400">{{ $message }}</span>@enderror</label>
                <label class="text-sm">{{ __('student_billing.fields.due_date') }}<input wire:model="due_date" type="date" class="mt-1 w-full rounded-xl px-4 py-3">@error('due_date')<span class="mt-1 block text-red-400">{{ $message }}</span>@enderror</label>
                <label class="text-sm md:col-span-2">{{ __('student_billing.fields.description') }}<input wire:model="description" class="mt-1 w-full rounded-xl px-4 py-3">@error('description')<span class="mt-1 block text-red-400">{{ $message }}</span>@enderror</label>
                <label class="text-sm">{{ __('student_billing.fields.quantity') }}<input wire:model="quantity" type="text" inputmode="decimal" class="mt-1 w-full rounded-xl px-4 py-3">@error('quantity')<span class="mt-1 block text-red-400">{{ $message }}</span>@enderror</label>
                <label class="text-sm">{{ __('student_billing.fields.unit_price') }}<input wire:model="unit_price" type="text" inputmode="decimal" class="mt-1 w-full rounded-xl px-4 py-3">@error('unit_price')<span class="mt-1 block text-red-400">{{ $message }}</span>@enderror</label>
                <label class="text-sm">{{ __('student_billing.fields.discount') }}<input wire:model="discount" type="text" inputmode="decimal" class="mt-1 w-full rounded-xl px-4 py-3">@error('discount')<span class="mt-1 block text-red-400">{{ $message }}</span>@enderror</label>
                <label class="text-sm md:col-span-2">{{ __('student_billing.fields.notes') }}<textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-xl px-4 py-3"></textarea></label>
                <div class="md:col-span-2 flex justify-end gap-3"><button type="button" wire:click="cancel" class="pill-link">{{ __('crud.common.actions.cancel') }}</button><button class="pill-link pill-link--accent">{{ __('crud.common.actions.save') }}</button></div>
            </form>
        </div>
    </section>
    @endif

    <section class="surface-table"><div class="admin-grid-meta"><div class="admin-grid-meta__title">{{ __('student_billing.list') }}</div></div><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-4 text-left">{{ __('student_billing.fields.invoice') }}</th><th class="px-5 py-4 text-left">{{ __('student_billing.fields.student') }}</th><th class="px-5 py-4 text-left">{{ __('student_billing.fields.date') }}</th><th class="px-5 py-4 text-left">{{ __('student_billing.fields.total') }}</th><th class="px-5 py-4 text-left">{{ __('student_billing.fields.balance') }}</th><th></th></tr></thead><tbody class="divide-y divide-white/6">
        @forelse($invoices as $invoice)<tr><td class="px-5 py-4 font-medium text-white">{{ $invoice->invoice_no }}</td><td class="px-5 py-4">{{ $invoice->student?->full_name }}</td><td class="px-5 py-4">{{ $invoice->issue_date?->format('d-m-Y') }}</td><td class="px-5 py-4">{{ number_format((float)$invoice->total, 2) }}</td><td class="px-5 py-4">{{ number_format(max((float)$invoice->total - (float)($invoice->active_paid_total ?? 0), 0), 2) }}</td><td class="px-5 py-4"><div class="admin-action-cluster admin-action-cluster--end"><a href="{{ route('invoices.payments', $invoice) }}" wire:navigate class="pill-link pill-link--compact">{{ __('student_billing.open') }}</a>@can('invoices.update')<button wire:click="edit({{ $invoice->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>@endcan @can('invoices.delete')<button wire:click="delete({{ $invoice->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact">{{ __('crud.common.actions.delete') }}</button>@endcan</div></td></tr>
        @empty<tr><td colspan="6" class="px-5 py-12 text-center text-neutral-500">{{ __('student_billing.empty') }}</td></tr>@endforelse
    </tbody></table></div><div class="p-4">{{ $invoices->links() }}</div></section>
</div>
