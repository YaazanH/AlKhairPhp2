<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Services\FinanceService;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $parent_id = null;
    public string $invoice_type = 'tuition';
    public string $issue_date = '';
    public string $due_date = '';
    public string $status = 'draft';
    public string $discount = '0';
    public string $notes = '';
    public int $perPage = 15;
    public bool $showForm = false;

    public function mount(): void
    {
        $this->authorizePermission('invoices.view');
        $this->issue_date = now()->toDateString();
    }

    public function with(): array
    {
        $invoiceQuery = $this->scopeInvoicesQuery(
            Invoice::query()
                ->with(['parentProfile'])
                ->withCount(['items'])
                ->withSum(['payments as active_paid_total' => fn ($query) => $query->whereNull('voided_at')], 'amount')
                ->latest('issue_date')
                ->latest('id')
        );

        return [
            'invoices' => $invoiceQuery->paginate($this->perPage),
            'parents' => $this->scopeParentsQuery(
                ParentProfile::query()->withCount('students')->orderBy('father_name')
            )->get(),
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
            'parent_id' => ['required', 'exists:parents,id'],
            'invoice_type' => ['required', 'in:tuition,activity,other'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'status' => ['required', 'in:draft,issued,partial,paid,cancelled'],
            'discount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function create(): void
    {
        $this->authorizePermission('invoices.create');

        $this->cancel(closeForm: false);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'invoices.update' : 'invoices.create');

        $validated = $this->validate();
        $this->authorizeScopedParentAccess(ParentProfile::query()->findOrFail((int) $validated['parent_id']));

        $invoice = Invoice::query()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'parent_id' => $validated['parent_id'],
                'invoice_no' => $this->editingId
                    ? Invoice::query()->findOrFail($this->editingId)->invoice_no
                    : app(FinanceService::class)->nextInvoiceNumber(),
                'invoice_type' => $validated['invoice_type'],
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?: null,
                'status' => $validated['status'],
                'discount' => $validated['discount'],
                'notes' => $validated['notes'] ?: null,
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
        $this->parent_id = $invoice->parent_id;
        $this->invoice_type = $invoice->invoice_type;
        $this->issue_date = $invoice->issue_date?->format('Y-m-d') ?? '';
        $this->due_date = $invoice->due_date?->format('Y-m-d') ?? '';
        $this->status = $invoice->status;
        $this->discount = number_format((float) $invoice->discount, 2, '.', '');
        $this->notes = $invoice->notes ?? '';
        $this->showForm = true;

        $this->resetValidation();
    }

    public function cancel(bool $closeForm = true): void
    {
        $this->editingId = null;
        $this->parent_id = null;
        $this->invoice_type = 'tuition';
        $this->issue_date = now()->toDateString();
        $this->due_date = '';
        $this->status = 'draft';
        $this->discount = '0';
        $this->notes = '';

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
                    <span class="badge-soft">{{ __('invoices.index.hero.badges.parents', ['count' => number_format($parents->count())]) }}</span>
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
                            <p class="admin-modal__description">{{ __('invoices.index.form.subtitle') }}</p>
                        </div>
                        <button type="button" wire:click="cancel" class="admin-modal__close" aria-label="{{ __('crud.common.actions.cancel') }}">×</button>
                    </div>
                    <div class="admin-modal__body">
            @if (auth()->user()->can('invoices.create') || auth()->user()->can('invoices.update'))
                <div class="mb-5 md:hidden">
                    <div class="eyebrow">{{ __('invoices.index.form.eyebrow') }}</div>
                    <h2 class="font-display mt-3 text-2xl text-white">{{ $editingId ? __('invoices.index.form.edit_title') : __('invoices.index.form.create_title') }}</h2>
                    <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('invoices.index.form.subtitle') }}</p>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.parent') }}</label>
                        <select wire:model="parent_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('invoices.index.form.placeholders.parent') }}</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->father_name }} ({{ __('invoices.index.form.parent_option', ['count' => $parent->students_count]) }})</option>
                            @endforeach
                        </select>
                        @error('parent_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.invoice_type') }}</label>
                            <select wire:model="invoice_type" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="tuition">{{ __('print.invoice.types.tuition') }}</option>
                                <option value="activity">{{ __('print.invoice.types.activity') }}</option>
                                <option value="other">{{ __('print.invoice.types.other') }}</option>
                            </select>
                            @error('invoice_type') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.status') }}</label>
                            <select wire:model="status" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="draft">{{ __('print.invoice.statuses.draft') }}</option>
                                <option value="issued">{{ __('print.invoice.statuses.issued') }}</option>
                                <option value="partial">{{ __('print.invoice.statuses.partial') }}</option>
                                <option value="paid">{{ __('print.invoice.statuses.paid') }}</option>
                                <option value="cancelled">{{ __('print.invoice.statuses.cancelled') }}</option>
                            </select>
                            @error('status') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.issue_date') }}</label>
                            <input wire:model="issue_date" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('issue_date') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.due_date') }}</label>
                            <input wire:model="due_date" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('due_date') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.discount') }}</label>
                        <input wire:model="discount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('discount') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('invoices.index.form.fields.notes') }}</label>
                        <textarea wire:model="notes" rows="4" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </div>

                    @error('delete') <div class="rounded-xl border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-300">{{ $message }}</div> @enderror

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="pill-link pill-link--accent">{{ $editingId ? __('invoices.index.form.update_submit') : __('invoices.index.form.create_submit') }}</button>
                        <x-admin.create-and-new-button :show="! $editingId" click="saveAndNew('save', 'create')" />
                        @if ($editingId)
                            <button type="button" wire:click="cancel" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
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
                            <button type="button" wire:click="create" class="pill-link pill-link--accent">
                                {{ __('invoices.index.form.create_title') }}
                            </button>
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
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.index.table.headers.parent') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.index.table.headers.amounts') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('invoices.index.table.headers.status') }}</th>
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('invoices.index.table.headers.actions') }}</th>
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
                                        <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ trans()->has('print.invoice.types.'.$invoice->invoice_type) ? __('print.invoice.types.'.$invoice->invoice_type) : __('print.invoice.types.other') }} | {{ $invoice->issue_date?->format('Y-m-d') }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $invoice->parentProfile?->father_name ?: '-' }}</td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="text-white">{{ __('invoices.index.table.amounts.total', ['amount' => number_format((float) $invoice->total, 2)]) }}</div>
                                        <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('invoices.index.table.amounts.meta', ['paid' => number_format((float) ($invoice->active_paid_total ?? 0), 2), 'items' => number_format($invoice->items_count)]) }}</div>
                                    </td>
                                    <td class="px-5 py-4 lg:px-6"><span class="{{ $invoiceStatusClass }}">{{ trans()->has('print.invoice.statuses.'.$invoice->status) ? __('print.invoice.statuses.'.$invoice->status) : __('print.invoice.statuses.unknown') }}</span></td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @can('payments.view')
                                                <a href="{{ route('invoices.payments', $invoice) }}" wire:navigate class="pill-link pill-link--compact">{{ __('invoices.index.table.actions.detail') }}</a>
                                            @endcan
                                            @can('invoices.view')
                                                <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="pill-link pill-link--compact">{{ __('invoices.index.table.actions.print') }}</a>
                                            @endcan
                                            @can('invoices.update')
                                                <button type="button" wire:click="edit({{ $invoice->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                            @endcan
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
