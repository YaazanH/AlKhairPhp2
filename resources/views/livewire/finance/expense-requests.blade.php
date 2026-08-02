<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Livewire\Concerns\HandlesFinanceRequestMaintenance;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\FinanceCurrency;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceRequestAttachment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\FinanceService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use FormatsFinanceNumbers;
    use HandlesFinanceRequestMaintenance;
    use SupportsCreateAndNew;
    use WithFileUploads;
    use WithPagination;

    public string $amount = '';
    public string $request_date = '';
    public ?int $currency_id = null;
    public ?int $cash_box_id = null;
    public ?int $finance_pull_request_kind_id = null;
    public string $requested_reason = '';
    public array $attachments = [];
    public array $review_amounts = [];
    public array $review_cash_boxes = [];
    public array $review_dates = [];
    public array $review_notes = [];
    public int $perPage = 15;
    public bool $showCreateModal = false;
    public ?int $finalisingRequestId = null;
    public ?int $viewingInvoiceId = null;
    public string $final_count = '';
    public string $remaining_amount = '0';
    public string $original_invoice_no = '';
    public string $invoice_issuer = '';
    public string $invoice_date = '';
    public string $invoice_deduction = '0';
    public array $invoice_items = [];
    public mixed $invoice_image = null;
    public bool $confirm_invoice_overage = false;

    public function mount(): void
    {
        $this->authorizePermission('finance.expense-requests.view');
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
    }

    public function with(): array
    {
        $canReview = auth()->user()?->can('finance.expense-requests.review') ?? false;

        return [
            'cashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->currency_id)->get(),
            'cashBoxesByCurrency' => FinanceCurrency::query()
                ->where('is_active', true)
                ->pluck('id')
                ->mapWithKeys(fn ($currencyId) => [(int) $currencyId => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), (int) $currencyId)->get()])
                ->all(),
            'currencies' => app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->get(),
            'pullKinds' => FinancePullRequestKind::query()->where('is_active', true)->orderBy('mode')->orderBy('name')->get(),
            'requests' => FinanceRequest::query()
                ->with(['activity', 'cashBox', 'category', 'invoice.items', 'postedTransaction', 'pullRequestKind', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency', 'attachments'])
                ->where(function ($query): void {
                    $query
                        ->where('type', FinanceRequest::TYPE_EXPENSE)
                        ->orWhere(function ($nested): void {
                            $nested
                                ->where('type', FinanceRequest::TYPE_PULL)
                                ->whereIn('status', [FinanceRequest::STATUS_ACCEPTED, FinanceRequest::STATUS_SETTLED]);
                        });
                })
                ->whereIn('status', [FinanceRequest::STATUS_ACCEPTED, FinanceRequest::STATUS_SETTLED])
                ->whereHas('postedTransaction')
                ->when(! $canReview, fn ($query) => $query->where(function ($builder) {
                    $builder
                        ->where('requested_by', auth()->id())
                        ->when(auth()->user()?->teacherProfile?->id, fn ($nested) => $nested->orWhere('teacher_id', auth()->user()->teacherProfile->id));
                }))
                ->latest()
                ->paginate($this->perPage),
            'finalisingRequest' => $this->finalisingRequestId ? FinanceRequest::query()->with(['invoice.items', 'pullRequestKind', 'acceptedCurrency'])->find($this->finalisingRequestId) : null,
            'viewingInvoice' => $this->viewingInvoiceId ? Invoice::query()->with(['items', 'financeRequest.acceptedCurrency'])->find($this->viewingInvoiceId) : null,
        ];
    }

    public function submitRequest(): void
    {
        $this->authorizePermission('finance.expense-requests.create');

        $canReview = auth()->user()?->can('finance.expense-requests.review') ?? false;
        $this->finance_pull_request_kind_id ??= app(FinanceService::class)->defaultPullRequestKindId();
        $this->normalizeFinanceNumberProperty('amount');
        if (auth()->user()?->can('finance.entries.update') && blank($this->request_date)) {
            $this->request_date = now()->toDateString();
        }

        $validated = $this->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'attachments' => ['array'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,webp,pdf'],
            'cash_box_id' => [$canReview ? 'required' : 'nullable', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['required', 'exists:finance_currencies,id'],
            'finance_pull_request_kind_id' => ['required', 'exists:finance_pull_request_kinds,id'],
            'request_date' => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
            'requested_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_EXPENSE),
            'type' => FinanceRequest::TYPE_EXPENSE,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $validated['finance_pull_request_kind_id'],
            'requested_currency_id' => $validated['currency_id'],
            'requested_amount' => $validated['amount'],
            'requested_by' => auth()->id(),
            'requested_reason' => $validated['requested_reason'] ?: null,
        ]);

        $this->storeAttachments($request);

        if ($canReview) {
            try {
                app(FinanceService::class)->acceptRequest(
                    $request,
                    (float) $validated['amount'],
                    app(FinanceService::class)->cashBoxForUser((int) $validated['cash_box_id'], auth()->user()),
                    auth()->user(),
                    'Auto-posted by finance management.',
                    null,
                    auth()->user()?->can('finance.entries.update') ? $validated['request_date'] : null,
                );
            } catch (ValidationException $exception) {
                $request->delete();
                $this->addError('amount', $this->firstValidationMessage($exception));

                return;
            }
        }

        $this->resetCreateForm();
        $this->showCreateModal = false;
        session()->flash('status', $canReview ? __('finance.messages.expense_posted') : __('finance.messages.expense_sent'));
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('finance.expense-requests.create');

        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->resetCreateForm();
        $this->showCreateModal = false;
    }

    public function updatedCashBoxId(): void
    {
        if ($this->cash_box_id && $this->currency_id && ! app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->whereKey($this->currency_id)->exists()) {
            $this->currency_id = app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->value('id');
        }
    }

    public function updatedCurrencyId(): void
    {
        if ($this->cash_box_id && $this->currency_id && ! app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->currency_id)->whereKey($this->cash_box_id)->exists()) {
            $this->cash_box_id = null;
        }
    }

    public function accept(int $requestId): void
    {
        $this->authorizePermission('finance.expense-requests.review');

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_EXPENSE)->findOrFail($requestId);
        $this->normalizeFinanceNumberArrayValue('review_amounts', $requestId);
        if (auth()->user()?->can('finance.entries.update') && blank($this->review_dates[$requestId] ?? null)) {
            $this->review_dates[$requestId] = now()->toDateString();
        }

        $this->validate([
            "review_amounts.{$requestId}" => ['nullable', 'numeric', 'gt:0'],
            "review_cash_boxes.{$requestId}" => ['required', 'exists:finance_cash_boxes,id'],
            "review_dates.{$requestId}" => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
        ]);

        $reviewAmount = $this->review_amounts[$requestId] ?? null;

        try {
            app(FinanceService::class)->acceptRequest(
                $request,
                (float) (($reviewAmount === null || $reviewAmount === '') ? $request->requested_amount : $reviewAmount),
                app(FinanceService::class)->cashBoxForUser((int) $this->review_cash_boxes[$requestId], auth()->user()),
                auth()->user(),
                $this->review_notes[$requestId] ?? null,
                null,
                auth()->user()?->can('finance.entries.update') ? ($this->review_dates[$requestId] ?? null) : null,
            );
        } catch (ValidationException $exception) {
            $this->addError("review_amounts.{$requestId}", $this->firstValidationMessage($exception));

            return;
        }

        session()->flash('status', __('finance.messages.expense_accepted'));
    }

    public function decline(int $requestId): void
    {
        $this->authorizePermission('finance.expense-requests.review');

        app(FinanceService::class)->declineRequest(FinanceRequest::query()->where('type', FinanceRequest::TYPE_EXPENSE)->findOrFail($requestId), auth()->user(), $this->review_notes[$requestId] ?? null);
        session()->flash('status', __('finance.messages.expense_declined'));
    }

    protected function storeAttachments(FinanceRequest $request): void
    {
        foreach ($this->attachments as $upload) {
            $path = $upload->store('finance/requests/'.$request->id, 'public');

            FinanceRequestAttachment::query()->create([
                'finance_request_id' => $request->id,
                'path' => $path,
                'original_name' => $upload->getClientOriginalName(),
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
                'uploaded_by' => auth()->id(),
            ]);
        }
    }

    protected function resetCreateForm(): void
    {
        $this->amount = '';
        $this->request_date = now()->toDateString();
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
        $this->cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $this->currency_id)?->id;
        $this->finance_pull_request_kind_id = app(FinanceService::class)->defaultPullRequestKindId();
        $this->requested_reason = '';
        $this->attachments = [];

        $this->resetValidation();
    }

    protected function firstValidationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (is_array($messages) && isset($messages[0])) {
                return (string) $messages[0];
            }
        }

        return $exception->getMessage();
    }

    public function openFinaliseModal(int $requestId): void
    {
        $this->authorizePermission('finance.expense-requests.review');
        $request = FinanceRequest::query()->with(['invoice.items', 'pullRequestKind'])->where('status', FinanceRequest::STATUS_ACCEPTED)->findOrFail($requestId);
        $this->finalisingRequestId = $request->id;
        $this->final_count = (string) ($request->accepted_count ?: $request->requested_count ?: '');
        $this->remaining_amount = '0';
        $this->invoice_date = now()->toDateString();
        $this->invoice_deduction = '0';
        $this->invoice_items = [['item_name' => '', 'quantity' => '1', 'unit_price' => '']];
        $this->resetValidation();
    }

    public function closeFinaliseModal(): void
    {
        $this->reset(['finalisingRequestId', 'final_count', 'remaining_amount', 'original_invoice_no', 'invoice_issuer', 'invoice_date', 'invoice_deduction', 'invoice_items', 'invoice_image', 'confirm_invoice_overage']);
        $this->resetValidation();
    }

    public function addInvoiceItem(): void
    {
        $this->invoice_items[] = ['item_name' => '', 'quantity' => '1', 'unit_price' => ''];
    }

    public function removeInvoiceItem(int $index): void
    {
        unset($this->invoice_items[$index]);
        $this->invoice_items = array_values($this->invoice_items);
    }

    public function invoicePreviewTotals(): array
    {
        $subtotal = round(collect($this->invoice_items)->sum(function (array $item): float {
            $quantity = (float) str_replace(',', '', (string) ($item['quantity'] ?? 0));
            $unitPrice = (float) str_replace(',', '', (string) ($item['unit_price'] ?? 0));

            return $quantity * $unitPrice;
        }), 2);
        $deduction = max(0, (float) str_replace(',', '', $this->invoice_deduction ?: '0'));

        return [
            'subtotal' => $subtotal,
            'deduction' => $deduction,
            'grand_total' => max(round($subtotal - $deduction, 2), 0),
        ];
    }

    public function finaliseCountExpense(): void
    {
        $this->authorizePermission('finance.expense-requests.review');
        $this->normalizeFinanceNumberProperty('final_count');
        $this->normalizeFinanceNumberProperty('remaining_amount');
        $validated = $this->validate([
            'final_count' => ['required', 'integer', 'min:0'],
            'remaining_amount' => ['required', 'numeric', 'min:0'],
        ]);
        $request = FinanceRequest::query()->where('status', FinanceRequest::STATUS_ACCEPTED)->findOrFail($this->finalisingRequestId);

        try {
            app(FinanceService::class)->finaliseCountExpense($request, (int) $validated['final_count'], (float) $validated['remaining_amount'], auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('remaining_amount', $this->firstValidationMessage($exception));
            return;
        }

        $this->closeFinaliseModal();
        session()->flash('status', __('finance.messages.expense_finalised'));
    }

    public function finaliseInvoiceExpense(): void
    {
        $this->authorizePermission('finance.expense-requests.review');
        $this->normalizeFinanceNumberProperty('invoice_deduction');
        foreach ($this->invoice_items as $index => $item) {
            $this->invoice_items[$index]['quantity'] = str_replace(',', '', (string) ($item['quantity'] ?? ''));
            $this->invoice_items[$index]['unit_price'] = str_replace(',', '', (string) ($item['unit_price'] ?? ''));
        }
        $validated = $this->validate([
            'original_invoice_no' => ['required', 'string', 'max:255'],
            'invoice_issuer' => ['required', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'invoice_deduction' => ['required', 'numeric', 'min:0'],
            'invoice_items' => ['required', 'array', 'min:1'],
            'invoice_items.*.item_name' => ['required', 'string', 'max:255'],
            'invoice_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'invoice_items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'invoice_image' => ['nullable', 'image', 'max:8192'],
            'confirm_invoice_overage' => ['boolean'],
        ]);
        $request = FinanceRequest::query()->with('pullRequestKind')->where('status', FinanceRequest::STATUS_ACCEPTED)->findOrFail($this->finalisingRequestId);
        $subtotal = round(collect($validated['invoice_items'])->sum(fn (array $item) => (float) $item['quantity'] * (float) $item['unit_price']), 2);
        $deduction = round((float) $validated['invoice_deduction'], 2);

        if ($deduction >= $subtotal) {
            $this->addError('invoice_deduction', __('finance.validation.deduction_exceeds_subtotal'));
            return;
        }

        $total = round($subtotal - $deduction, 2);

        if ($total > (float) $request->accepted_amount && ! $validated['confirm_invoice_overage']) {
            $this->addError('confirm_invoice_overage', __('finance.validation.confirm_invoice_overage', ['amount' => app(FinanceService::class)->formatCurrencyAmount($total, $request->acceptedCurrency)]));
            return;
        }

        $imagePath = $validated['invoice_image'] ? $validated['invoice_image']->store('finance/invoices', 'public') : null;
        $invoice = Invoice::query()->create([
            'invoice_no' => app(FinanceService::class)->nextInvoiceNumber(),
            'original_invoice_no' => $validated['original_invoice_no'],
            'invoicer_name' => $validated['invoice_issuer'],
            'invoice_type' => 'finance',
            'finance_invoice_kind_id' => app(FinanceService::class)->defaultInvoiceKindId(),
            'finance_request_id' => $request->id,
            'issue_date' => $validated['invoice_date'],
            'status' => 'draft',
            'subtotal' => $subtotal,
            'discount' => $deduction,
            'total' => $total,
            'original_image_path' => $imagePath,
        ]);
        foreach ($validated['invoice_items'] as $index => $item) {
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'line_no' => $index + 1,
                'item_name' => $item['item_name'],
                'description' => $item['item_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'amount' => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
            ]);
        }

        try {
            app(FinanceService::class)->finaliseInvoiceExpense($request, $invoice, auth()->user());
        } catch (ValidationException $exception) {
            $invoice->items()->delete();
            $invoice->delete();
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            $this->addError('invoice_items', $this->firstValidationMessage($exception));
            return;
        }

        $this->closeFinaliseModal();
        session()->flash('status', __('finance.messages.expense_finalised'));
    }

    protected function financeRequestMaintenanceTypes(): array
    {
        return [FinanceRequest::TYPE_EXPENSE, FinanceRequest::TYPE_PULL];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.expense_requests.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.expense_requests.subtitle') }}</p>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif
    @error('amount') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror
    @error('currency_id') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror

    <x-admin.modal
        :show="$showCreateModal"
        :title="__('finance.expense_requests.new')"
        :description="__('finance.expense_requests.subtitle')"
        close-method="closeCreateModal"
        max-width="5xl"
    >
        <form wire:submit="submitRequest" class="grid gap-4 lg:grid-cols-3">
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.category') }}</label><select wire:model="finance_pull_request_kind_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_expense_kind') }}</option>@foreach ($pullKinds as $kind)<option value="{{ $kind->id }}">{{ $kind->name }} - {{ __('finance.pull_modes.'.$kind->mode) }}</option>@endforeach</select>@error('finance_pull_request_kind_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.amount') }}</label><input wire:model="amount" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">@error('amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.common.currency') }}</label><select wire:model="currency_id" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
            @can('finance.entries.update')<div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.entry_date') }}</label><input wire:model="request_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm">@error('request_date') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endcan
            @can('finance.expense-requests.review')<div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label><select wire:model="cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endcan
            <div class="lg:col-span-3"><label class="mb-1 block text-sm font-medium">{{ __('finance.common.description') }}</label><textarea wire:model="requested_reason" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>@error('requested_reason') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div class="lg:col-span-3"><label class="mb-1 block text-sm font-medium">{{ __('finance.common.attachments') }}</label><input wire:model="attachments" type="file" multiple class="w-full rounded-xl px-4 py-3 text-sm">@error('attachments.*') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div class="lg:col-span-3 flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                <x-admin.create-and-new-button click="saveAndNew('submitRequest', 'openCreateModal')" />
                <button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.save_expense') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.expense_requests.title') }}</div><div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($requests->total())]) }}</div></div>@can('finance.expense-requests.create')<button wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('finance.expense_requests.new') }}</button>@endcan</div>
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('finance.fields.expense_no') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-4 py-3 text-left">{{ __('finance.common.description') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.amount') }}</th><th class="px-4 py-3 text-left">{{ __('finance.common.status') }}</th><th class="px-4 py-3 text-right">{{ __('finance.actions.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">
            @forelse ($requests as $request)<tr><td class="px-4 py-3"><div class="font-semibold text-white">{{ $request->expense_no ?: $request->request_no }}</div><div class="text-xs text-neutral-500">{{ $request->postedTransaction?->transaction_date?->format('d-m-Y') }} · {{ $request->reviewedBy?->name ?: '-' }}</div></td><td class="px-4 py-3"><div>{{ $request->pullRequestKind?->name ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $request->pullRequestKind ? __('finance.pull_modes.'.$request->pullRequestKind->mode) : '-' }}</div></td><td class="px-4 py-3"><div class="max-w-xs">{{ $request->requested_reason ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $request->request_no }} · {{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : ($request->requestedBy?->name ?: '-') }}</div></td><td class="px-4 py-3"><bdi dir="ltr" class="font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($request->accepted_amount, $request->acceptedCurrency) }}</bdi></td><td class="px-4 py-3"><span class="status-chip {{ $request->status === 'settled' ? 'status-chip--emerald' : 'status-chip--amber' }}">{{ $request->status === 'settled' ? __('finance.statuses.settled') : __('finance.statuses.pending') }}</span></td><td class="px-4 py-3"><div class="admin-action-cluster admin-action-cluster--end">@if ($request->status === 'accepted')<button wire:click="openFinaliseModal({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.actions.finalise') }}</button>@endif @if ($request->invoice)<button wire:click="$set('viewingInvoiceId', {{ $request->invoice->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.view_invoice') }}</button>@endif</div></td></tr>
            @empty<tr><td colspan="6" class="px-5 py-10 text-center text-neutral-500">{{ __('finance.empty.no_expenses') }}</td></tr>@endforelse
        </tbody></table></div>@if ($requests->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $requests->links() }}</div>@endif
    </section>

    <x-admin.modal :show="$finalisingRequestId !== null" :title="__('finance.actions.finalise')" close-method="closeFinaliseModal" max-width="5xl">
        @if ($finalisingRequest)
            <div class="mb-5 soft-callout p-4"><div class="font-semibold text-white">{{ $finalisingRequest->expense_no ?: $finalisingRequest->request_no }}</div><div class="mt-1 text-sm text-neutral-300"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($finalisingRequest->accepted_amount, $finalisingRequest->acceptedCurrency) }}</bdi></div></div>
            @if ($finalisingRequest->pullRequestKind?->mode === 'count')
                <form wire:submit="finaliseCountExpense" class="grid gap-4 md:grid-cols-2"><div><label class="mb-1 block text-sm">{{ __('finance.fields.final_count') }}</label><input wire:model="final_count" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('final_count')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div><div><label class="mb-1 block text-sm">{{ __('finance.fields.remaining_amount') }}</label><input wire:model="remaining_amount" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('remaining_amount')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div><div class="md:col-span-2 flex justify-end"><button class="pill-link pill-link--accent">{{ __('finance.actions.finalise') }}</button></div></form>
            @else
                @php($invoiceTotals = $this->invoicePreviewTotals())
                <form wire:submit="finaliseInvoiceExpense" class="space-y-5"><div class="grid gap-4 md:grid-cols-3"><div><label class="mb-1 block text-sm">{{ __('finance.fields.original_invoice_no') }}</label><input wire:model="original_invoice_no" class="w-full rounded-xl px-4 py-3"></div><div><label class="mb-1 block text-sm">{{ __('finance.fields.invoice_issuer') }}</label><input wire:model="invoice_issuer" class="w-full rounded-xl px-4 py-3"></div><div><label class="mb-1 block text-sm">{{ __('finance.common.date') }}</label><input wire:model="invoice_date" type="date" class="w-full rounded-xl px-4 py-3"></div></div><div class="space-y-3">@foreach ($invoice_items as $index => $item)<div class="grid gap-3 md:grid-cols-[1fr_9rem_11rem_auto]"><input wire:model="invoice_items.{{ $index }}.item_name" placeholder="{{ __('finance.fields.item_name') }}" class="rounded-xl px-4 py-3"><input wire:model.live.debounce.300ms="invoice_items.{{ $index }}.quantity" data-thousand-separator placeholder="{{ __('finance.fields.quantity') }}" class="rounded-xl px-4 py-3"><input wire:model.live.debounce.300ms="invoice_items.{{ $index }}.unit_price" data-thousand-separator placeholder="{{ __('finance.fields.unit_price') }}" class="rounded-xl px-4 py-3"><button type="button" wire:click="removeInvoiceItem({{ $index }})" class="pill-link pill-link--danger">×</button></div>@endforeach<button type="button" wire:click="addInvoiceItem" class="pill-link pill-link--compact">{{ __('finance.actions.add') }}</button></div><div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_22rem]"><div><label class="mb-1 block text-sm">{{ __('finance.fields.original_invoice_image') }}</label><input wire:model="invoice_image" type="file" accept="image/*" class="w-full rounded-xl px-4 py-3"></div><div><label class="mb-1 block text-sm">{{ __('finance.fields.deduction') }}</label><input wire:model.live.debounce.300ms="invoice_deduction" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('invoice_deduction')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div></div><div class="grid gap-3 sm:grid-cols-3"><div class="rounded-xl border border-white/8 bg-white/4 p-4"><div class="kpi-label">{{ __('finance.fields.subtotal') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($invoiceTotals['subtotal'], $finalisingRequest->acceptedCurrency) }}</bdi></div><div class="rounded-xl border border-white/8 bg-white/4 p-4"><div class="kpi-label">{{ __('finance.fields.deduction') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount(-$invoiceTotals['deduction'], $finalisingRequest->acceptedCurrency) }}</bdi></div><div class="rounded-xl border border-emerald-400/20 bg-emerald-500/10 p-4"><div class="kpi-label">{{ __('finance.fields.grand_total') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($invoiceTotals['grand_total'], $finalisingRequest->acceptedCurrency) }}</bdi></div></div>@error('invoice_items')<div class="text-sm text-red-400">{{ $message }}</div>@enderror @error('confirm_invoice_overage')<div class="rounded-xl border border-amber-400/20 bg-amber-500/10 p-4 text-sm text-amber-100">{{ $message }}<label class="mt-3 flex gap-2"><input wire:model="confirm_invoice_overage" type="checkbox">{{ __('finance.messages.use_invoice_total') }}</label></div>@enderror<div class="rounded-xl border border-amber-400/20 bg-amber-500/10 p-4 text-sm text-amber-100">{{ __('finance.messages.invoice_immutable_warning') }}</div><div class="flex justify-end"><button wire:confirm="{{ __('finance.messages.invoice_immutable_warning') }}" class="pill-link pill-link--accent">{{ __('finance.actions.finalise') }}</button></div></form>
            @endif
        @endif
    </x-admin.modal>

    <x-admin.modal :show="$viewingInvoiceId !== null" :title="__('finance.actions.view_invoice')" close-method="$set('viewingInvoiceId', null)" max-width="5xl">@if ($viewingInvoice)<div class="grid gap-3 md:grid-cols-3"><div><div class="kpi-label">{{ __('finance.fields.invoice_no') }}</div><div class="mt-1 text-white">{{ $viewingInvoice->invoice_no }}</div></div><div><div class="kpi-label">{{ __('finance.fields.original_invoice_no') }}</div><div class="mt-1 text-white">{{ $viewingInvoice->original_invoice_no }}</div></div><div><div class="kpi-label">{{ __('finance.fields.invoice_issuer') }}</div><div class="mt-1 text-white">{{ $viewingInvoice->invoicer_name }}</div></div></div><div class="mt-5 overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('finance.fields.item_name') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.quantity') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.unit_price') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.amount') }}</th></tr></thead><tbody>@foreach ($viewingInvoice->items as $item)<tr><td class="px-4 py-3">{{ $item->item_name }}</td><td class="px-4 py-3">{{ $item->quantity }}</td><td class="px-4 py-3">{{ $item->unit_price }}</td><td class="px-4 py-3">{{ $item->amount }}</td></tr>@endforeach</tbody><tfoot><tr><th colspan="3" class="px-4 py-3 text-right">{{ __('finance.fields.subtotal') }}</th><td class="px-4 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($viewingInvoice->subtotal, $viewingInvoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr><tr><th colspan="3" class="px-4 py-3 text-right">{{ __('finance.fields.deduction') }}</th><td class="px-4 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount(-(float) $viewingInvoice->discount, $viewingInvoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr><tr><th colspan="3" class="px-4 py-3 text-right text-white">{{ __('finance.fields.grand_total') }}</th><td class="px-4 py-3 font-semibold text-white"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($viewingInvoice->total, $viewingInvoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr></tfoot></table></div><div class="mt-5 flex flex-wrap justify-end gap-3">@if ($viewingInvoice->original_image_path)<a href="{{ asset('storage/'.$viewingInvoice->original_image_path) }}" target="_blank" class="pill-link">{{ __('finance.actions.view_original') }}</a>@endif<a href="{{ route('finance.invoices.print', $viewingInvoice) }}" target="_blank" class="pill-link pill-link--accent">{{ __('finance.actions.print_a5') }}</a></div>@endif</x-admin.modal>
</div>
