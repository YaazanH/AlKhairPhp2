<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Livewire\Concerns\HandlesFinanceRequestMaintenance;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\FinanceCurrency;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceRequestAttachment;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\FinanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
    public ?int $editingInvoiceId = null;
    public string $final_count = '';
    public string $remaining_amount = '0';
    public string $original_invoice_no = '';
    public string $invoice_issuer = '';
    public string $invoice_date = '';
    public string $invoice_deduction = '0';
    public array $invoice_items = [];
    public string $invoice_item_name = '';
    public string $invoice_item_quantity = '1';
    public string $invoice_item_unit_price = '';
    public ?int $editing_invoice_item_index = null;
    public ?int $paused_invoice_draft_request_id = null;
    public mixed $invoice_image = null;
    public string $invoice_notes = '';
    public bool $confirm_invoice_overage = false;

    public function mount(): void
    {
        $this->authorizePermission('finance.expense-requests.view');
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
    }

    public function with(): array
    {
        $canReview = auth()->user()?->can('finance.expense-requests.review') ?? false;
        $cashBoxes = app(FinanceService::class)->accessibleCashBoxes(auth()->user())->get();

        return [
            'cashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->currency_id)->get(),
            'cashBoxesByCurrency' => FinanceCurrency::query()
                ->where('is_active', true)
                ->where('show_in_dropdowns', true)
                ->pluck('id')
                ->mapWithKeys(fn ($currencyId) => [(int) $currencyId => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), (int) $currencyId)->get()])
                ->all(),
            'currencies' => app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->get(),
            'pullKinds' => FinancePullRequestKind::query()->where('is_active', true)->orderBy('mode')->orderBy('name')->get(),
            'expenses' => FinanceTransaction::query()
                ->with([
                    'cashBox',
                    'category',
                    'currency',
                    'enteredBy',
                    'financeRequest.category',
                    'financeRequest.invoice.items',
                    'financeRequest.pullRequestKind',
                    'financeRequest.requestedBy',
                    'financeRequest.reviewedBy',
                    'financeRequest.teacher',
                ])
                ->where('type', 'expense')
                ->whereIn('cash_box_id', $cashBoxes->pluck('id'))
                ->when(! $canReview, fn ($query) => $query->whereHas('financeRequest', function ($builder): void {
                    $builder->where('requested_by', auth()->id())
                        ->when(auth()->user()?->teacherProfile?->id, fn ($nested) => $nested->orWhere('teacher_id', auth()->user()->teacherProfile->id));
                }))
                ->latest('transaction_date')
                ->latest('id')
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
            'attachments.*' => ['file', 'max:'.config('uploads.image_max_kb'), 'mimes:jpg,jpeg,png,webp,pdf'],
            'cash_box_id' => [$canReview ? 'required' : 'nullable', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['required', 'exists:finance_currencies,id'],
            'finance_pull_request_kind_id' => ['required', Rule::exists('finance_categories', 'id')->where('type', 'expense')->where('is_active', true)],
            'request_date' => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
            'requested_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_EXPENSE),
            'type' => FinanceRequest::TYPE_EXPENSE,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $validated['finance_pull_request_kind_id'],
            'finance_category_id' => $validated['finance_pull_request_kind_id'],
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
        $keepTemporaryUpload = $this->paused_invoice_draft_request_id === $request->id;
        $this->finalisingRequestId = $request->id;
        $this->editingInvoiceId = null;
        $this->final_count = (string) ($request->accepted_count ?: $request->requested_count ?: '');
        $this->remaining_amount = '0';
        $draft = $request->pullRequestKind?->mode === FinancePullRequestKind::MODE_INVOICE
            ? session()->get($this->invoiceDraftSessionKey($request->id))
            : null;

        if (is_array($draft)) {
            $this->original_invoice_no = (string) ($draft['original_invoice_no'] ?? '');
            $this->invoice_issuer = (string) ($draft['invoice_issuer'] ?? '');
            $this->invoice_date = (string) ($draft['invoice_date'] ?? now()->toDateString());
            $this->invoice_deduction = (string) ($draft['invoice_deduction'] ?? '0');
            $this->invoice_items = is_array($draft['invoice_items'] ?? null) ? array_values($draft['invoice_items']) : [];
            $this->invoice_item_name = (string) ($draft['invoice_item_name'] ?? '');
            $this->invoice_item_quantity = (string) ($draft['invoice_item_quantity'] ?? '1');
            $this->invoice_item_unit_price = (string) ($draft['invoice_item_unit_price'] ?? '');
            $this->editing_invoice_item_index = is_numeric($draft['editing_invoice_item_index'] ?? null) ? (int) $draft['editing_invoice_item_index'] : null;
            $this->confirm_invoice_overage = (bool) ($draft['confirm_invoice_overage'] ?? false);
        } else {
            $this->original_invoice_no = '';
            $this->invoice_issuer = '';
            $this->invoice_date = now()->toDateString();
            $this->invoice_deduction = '0';
            $this->invoice_items = [];
            $this->resetInvoiceItemDraft();
            $this->confirm_invoice_overage = false;
        }

        if (! $keepTemporaryUpload) {
            $this->invoice_image = null;
        }
        $this->paused_invoice_draft_request_id = null;
        $this->invoice_notes = '';
        $this->resetValidation();
    }

    public function editInvoice(int $invoiceId): void
    {
        $this->authorizePermission('finance.expense-requests.review');
        $invoice = Invoice::query()->with(['items', 'financeRequest.acceptedCurrency'])->where('invoice_type', 'finance')->findOrFail($invoiceId);
        abort_unless($invoice->financeRequest, 404);

        $this->editingInvoiceId = $invoice->id;
        $this->finalisingRequestId = $invoice->finance_request_id;
        $this->original_invoice_no = (string) $invoice->original_invoice_no;
        $this->invoice_issuer = (string) $invoice->invoicer_name;
        $this->invoice_date = $invoice->issue_date?->toDateString() ?: now()->toDateString();
        $this->invoice_deduction = $this->formatFinanceNumberForInput($invoice->discount);
        $this->invoice_notes = (string) $invoice->notes;
        $this->invoice_image = null;
        $this->invoice_items = $invoice->items->sortBy('line_no')->map(fn (InvoiceItem $item) => [
            'item_name' => $item->item_name,
            'quantity' => $this->formatFinanceNumberForInput($item->quantity),
            'unit_price' => $this->formatFinanceNumberForInput($item->unit_price),
        ])->values()->all();
        $this->resetInvoiceItemDraft();
        $this->viewingInvoiceId = null;
        $this->resetValidation();
    }

    public function closeFinaliseModal(bool $preserveInvoiceDraft = true): void
    {
        $requestId = $this->finalisingRequestId;
        $request = $requestId && ! $this->editingInvoiceId
            ? FinanceRequest::query()->with('pullRequestKind')->find($requestId)
            : null;

        if ($preserveInvoiceDraft && $request?->pullRequestKind?->mode === FinancePullRequestKind::MODE_INVOICE) {
            session()->put($this->invoiceDraftSessionKey($request->id), [
                'original_invoice_no' => $this->original_invoice_no,
                'invoice_issuer' => $this->invoice_issuer,
                'invoice_date' => $this->invoice_date,
                'invoice_deduction' => $this->invoice_deduction,
                'invoice_items' => $this->invoice_items,
                'invoice_item_name' => $this->invoice_item_name,
                'invoice_item_quantity' => $this->invoice_item_quantity,
                'invoice_item_unit_price' => $this->invoice_item_unit_price,
                'editing_invoice_item_index' => $this->editing_invoice_item_index,
                'confirm_invoice_overage' => $this->confirm_invoice_overage,
            ]);
            $this->paused_invoice_draft_request_id = $request->id;
            $this->editingInvoiceId = null;
            $this->finalisingRequestId = null;
            $this->resetValidation();
            session()->flash('status', __('finance.messages.invoice_draft_saved'));

            return;
        }

        if ($requestId) {
            session()->forget($this->invoiceDraftSessionKey($requestId));
        }
        $this->reset(['editingInvoiceId', 'finalisingRequestId', 'final_count', 'remaining_amount', 'original_invoice_no', 'invoice_issuer', 'invoice_date', 'invoice_deduction', 'invoice_items', 'invoice_image', 'invoice_notes', 'confirm_invoice_overage']);
        $this->paused_invoice_draft_request_id = null;
        $this->resetInvoiceItemDraft();
        $this->resetValidation();
    }

    protected function invoiceDraftSessionKey(int $requestId): string
    {
        return 'finance.invoice_expense_drafts.'.auth()->id().'.'.$requestId;
    }

    public function saveInvoiceItem(): void
    {
        $this->invoice_item_name = trim($this->invoice_item_name);
        $this->invoice_item_quantity = str_replace(',', '', $this->invoice_item_quantity);
        $this->invoice_item_unit_price = str_replace(',', '', $this->invoice_item_unit_price);

        $validated = $this->validate([
            'invoice_item_name' => ['required', 'string', 'max:255'],
            'invoice_item_quantity' => ['required', 'numeric', 'gt:0'],
            'invoice_item_unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $item = [
            'item_name' => $validated['invoice_item_name'],
            'quantity' => $validated['invoice_item_quantity'],
            'unit_price' => $validated['invoice_item_unit_price'],
        ];

        if ($this->editing_invoice_item_index !== null) {
            abort_unless(array_key_exists($this->editing_invoice_item_index, $this->invoice_items), 404);
            $this->invoice_items[$this->editing_invoice_item_index] = $item;
        } else {
            $this->invoice_items[] = $item;
        }

        $this->resetInvoiceItemDraft();
        $this->resetValidation(['invoice_items', 'invoice_item_name', 'invoice_item_quantity', 'invoice_item_unit_price']);
    }

    public function editInvoiceItem(int $index): void
    {
        abort_unless(array_key_exists($index, $this->invoice_items), 404);
        $item = $this->invoice_items[$index];
        $this->editing_invoice_item_index = $index;
        $this->invoice_item_name = (string) ($item['item_name'] ?? '');
        $this->invoice_item_quantity = (string) ($item['quantity'] ?? '1');
        $this->invoice_item_unit_price = (string) ($item['unit_price'] ?? '');
        $this->resetValidation(['invoice_item_name', 'invoice_item_quantity', 'invoice_item_unit_price']);
    }

    public function removeInvoiceItem(int $index): void
    {
        abort_unless(array_key_exists($index, $this->invoice_items), 404);
        unset($this->invoice_items[$index]);
        $this->invoice_items = array_values($this->invoice_items);

        if ($this->editing_invoice_item_index === $index) {
            $this->resetInvoiceItemDraft();
        } elseif ($this->editing_invoice_item_index !== null && $this->editing_invoice_item_index > $index) {
            $this->editing_invoice_item_index--;
        }
    }

    protected function resetInvoiceItemDraft(): void
    {
        $this->editing_invoice_item_index = null;
        $this->invoice_item_name = '';
        $this->invoice_item_quantity = '1';
        $this->invoice_item_unit_price = '';
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

        $this->closeFinaliseModal(false);
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
            'invoice_image' => ['nullable', 'file', 'max:'.config('uploads.image_max_kb'), 'mimes:jpg,jpeg,png,webp,pdf'],
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
            'notes' => null,
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

        $this->closeFinaliseModal(false);
        session()->flash('status', __('finance.messages.expense_finalised'));
    }

    public function saveInvoiceExpense(): void
    {
        if (! $this->editingInvoiceId) {
            $this->finaliseInvoiceExpense();

            return;
        }

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
            'invoice_image' => ['nullable', 'file', 'max:'.config('uploads.image_max_kb'), 'mimes:jpg,jpeg,png,webp,pdf'],
            'invoice_notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $subtotal = round(collect($validated['invoice_items'])->sum(fn (array $item) => (float) $item['quantity'] * (float) $item['unit_price']), 2);
        $deduction = round((float) $validated['invoice_deduction'], 2);
        if ($deduction >= $subtotal) {
            $this->addError('invoice_deduction', __('finance.validation.deduction_exceeds_subtotal'));

            return;
        }

        $invoice = Invoice::query()->with(['items', 'financeRequest.postedTransaction'])->where('invoice_type', 'finance')->findOrFail($this->editingInvoiceId);
        $oldImagePath = $invoice->original_image_path;
        $newImagePath = $validated['invoice_image'] ? $validated['invoice_image']->store('finance/invoices', 'public') : null;

        try {
            DB::transaction(function () use ($deduction, $invoice, $newImagePath, $subtotal, $validated): void {
                $invoice->update([
                    'original_invoice_no' => $validated['original_invoice_no'],
                    'invoicer_name' => $validated['invoice_issuer'],
                    'issue_date' => $validated['invoice_date'],
                    'discount' => $deduction,
                    'notes' => $validated['invoice_notes'] ?: null,
                    'original_image_path' => $newImagePath ?: $invoice->original_image_path,
                    'subtotal' => $subtotal,
                    'total' => round($subtotal - $deduction, 2),
                ]);
                $invoice->items()->delete();
                foreach ($validated['invoice_items'] as $index => $item) {
                    $invoice->items()->create([
                        'line_no' => $index + 1,
                        'item_name' => $item['item_name'],
                        'description' => $item['item_name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'amount' => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
                    ]);
                }
                app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());
                $invoice->financeRequest?->postedTransaction?->update(['transaction_date' => $validated['invoice_date']]);
            });
        } catch (\Throwable $exception) {
            if ($newImagePath) {
                Storage::disk('public')->delete($newImagePath);
            }
            throw $exception;
        }

        if ($newImagePath && $oldImagePath && $oldImagePath !== $newImagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        $this->closeFinaliseModal(false);
        session()->flash('status', __('finance.messages.invoice_updated'));
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
            <div class="lg:col-span-3"><label class="mb-1 block text-sm font-medium">{{ __('finance.common.attachments') }}</label><input wire:model="attachments" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="w-full rounded-xl px-4 py-3 text-sm">@error('attachments.*') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div class="lg:col-span-3 flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                <x-admin.create-and-new-button click="saveAndNew('submitRequest', 'openCreateModal')" />
                <button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.save_expense') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.expense_requests.title') }}</div><div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($expenses->total())]) }}</div></div>@can('finance.expense-requests.create')<button wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('finance.expense_requests.new') }}</button>@endcan</div>
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('finance.fields.expense_no') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-4 py-3 text-left">{{ __('finance.common.description') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.amount') }}</th><th class="px-4 py-3 text-left">{{ __('finance.common.status') }}</th><th class="px-4 py-3 text-right">{{ __('finance.actions.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">
            @forelse ($expenses as $transaction)
                @php($request = $transaction->financeRequest)
                @php($category = $transaction->category ?: $request?->category ?: $request?->pullRequestKind)
                @php($rowStatus = $request?->status ?: $transaction->status)
                <tr>
                    <td class="px-4 py-3"><div class="font-semibold text-white">{{ $transaction->special_transaction_no ?: $transaction->transaction_no }}</div><div class="text-xs text-neutral-500">{{ $transaction->transaction_date?->format('d-m-Y') }} · {{ $transaction->enteredBy?->name ?: $request?->reviewedBy?->name ?: '-' }}</div></td>
                    <td class="px-4 py-3"><div>{{ $category?->name ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $category?->mode ? __('finance.pull_modes.'.$category->mode) : '-' }}</div></td>
                    <td class="px-4 py-3"><div class="max-w-xs">{{ $transaction->description ?: '-' }}</div>@if ($request)<div class="text-xs text-neutral-500">{{ $request->request_no }} · {{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : ($request->requestedBy?->name ?: '-') }}</div>@endif</td>
                    <td class="px-4 py-3"><bdi dir="ltr" class="font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($transaction->amount, $transaction->currency) }}</bdi></td>
                    <td class="px-4 py-3"><span class="status-chip {{ in_array($rowStatus, ['active', 'settled'], true) ? 'status-chip--emerald' : 'status-chip--amber' }}">{{ $request ? ($rowStatus === 'accepted' ? __('finance.expense_statuses.accepted') : __('finance.statuses.'.$rowStatus)) : ($rowStatus === 'active' ? __('finance.common.active') : $rowStatus) }}</span></td>
                    <td class="px-4 py-3"><div class="admin-action-cluster admin-action-cluster--end">
                        @if ($request?->status === 'accepted')<button wire:click="openFinaliseModal({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.actions.finalise') }}</button>@endif
                        @if ($request?->invoice)
                            <button wire:click="$set('viewingInvoiceId', {{ $request->invoice->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.view_invoice') }}</button>
                        @endif
                    </div></td>
                </tr>
            @empty<tr><td colspan="6" class="px-5 py-10 text-center text-neutral-500">{{ __('finance.empty.no_expenses') }}</td></tr>@endforelse
        </tbody></table></div>@if ($expenses->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $expenses->links() }}</div>@endif
    </section>

    <x-admin.modal :show="$finalisingRequestId !== null" :title="__('finance.actions.finalise')" close-method="closeFinaliseModal" max-width="5xl">
        @if ($finalisingRequest)
            @if ($finalisingRequest->pullRequestKind?->mode === 'count')
                <div class="mb-5 soft-callout p-4">
                    <div class="font-semibold text-white">{{ $finalisingRequest->expense_no ?: $finalisingRequest->request_no }}</div>
                    <div class="mt-1 text-sm text-neutral-300"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($finalisingRequest->accepted_amount, $finalisingRequest->acceptedCurrency) }}</bdi></div>
                </div>
                <form wire:submit="finaliseCountExpense" class="grid gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block text-sm">{{ __('finance.fields.final_count') }}</label><input wire:model="final_count" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('final_count')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div><label class="mb-1 block text-sm">{{ __('finance.fields.remaining_amount') }}</label><input wire:model="remaining_amount" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('remaining_amount')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div class="flex justify-end md:col-span-2"><button class="pill-link pill-link--accent">{{ __('finance.actions.finalise') }}</button></div>
                </form>
            @else
                @php($invoiceTotals = $this->invoicePreviewTotals())
                <form wire:submit="saveInvoiceExpense" class="space-y-5" data-invoice-finalisation-form>
                    <div class="grid gap-3 sm:grid-cols-3" data-invoice-finalisation-metrics>
                        <div class="rounded-xl border border-white/8 bg-white/4 p-4"><div class="kpi-label">{{ __('finance.fields.subtotal') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($invoiceTotals['subtotal'], $finalisingRequest->acceptedCurrency) }}</bdi></div>
                        <div class="rounded-xl border border-white/8 bg-white/4 p-4"><div class="kpi-label">{{ __('finance.fields.deduction') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount(-$invoiceTotals['deduction'], $finalisingRequest->acceptedCurrency) }}</bdi></div>
                        <div class="rounded-xl border border-emerald-400/20 bg-emerald-500/10 p-4"><div class="kpi-label">{{ __('finance.fields.grand_total') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($invoiceTotals['grand_total'], $finalisingRequest->acceptedCurrency) }}</bdi></div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div><label class="mb-1 block text-sm">{{ __('finance.fields.original_invoice_no') }}</label><input wire:model="original_invoice_no" dir="ltr" class="w-full rounded-xl px-4 py-3"></div>
                        <div><label class="mb-1 block text-sm">{{ __('finance.fields.invoice_issuer') }}</label><input wire:model="invoice_issuer" class="w-full rounded-xl px-4 py-3"></div>
                        <div><label class="mb-1 block text-sm">{{ __('finance.common.date') }}</label><input wire:model="invoice_date" type="date" class="w-full rounded-xl px-4 py-3"></div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_22rem]" data-invoice-scan-fields>
                        <div><label class="mb-1 block text-sm">{{ __('finance.fields.original_invoice_image') }}</label><input wire:model="invoice_image" type="file" accept="image/*,application/pdf" class="w-full rounded-xl px-4 py-3"></div>
                        <div><label class="mb-1 block text-sm">{{ __('finance.fields.deduction') }}</label><input wire:model.live.debounce.300ms="invoice_deduction" data-thousand-separator class="w-full rounded-xl px-4 py-3">@error('invoice_deduction')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-white/10 bg-black/10" data-invoice-items-table>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[46rem] text-sm">
                                <thead class="border-b border-white/15 bg-white/[0.04]" data-invoice-items-header-divider><tr><th class="w-[38%] px-3 py-3 text-start">{{ __('finance.fields.item_name') }}</th><th class="w-[15%] px-3 py-3 text-start">{{ __('finance.fields.quantity') }}</th><th class="w-[19%] px-3 py-3 text-start">{{ __('finance.fields.unit_price') }}</th><th class="w-[18%] px-3 py-3 text-start">{{ __('finance.fields.amount') }}</th><th class="w-[10%] px-3 py-3 text-end">{{ __('finance.actions.actions') }}</th></tr></thead>
                                <tbody class="divide-y divide-white/6">
                                    @foreach ($invoice_items as $index => $item)
                                        @php($lineAmount = round((float) str_replace(',', '', (string) ($item['quantity'] ?? 0)) * (float) str_replace(',', '', (string) ($item['unit_price'] ?? 0)), 2))
                                        @if ($editing_invoice_item_index === $index)
                                            @php($editingLineAmount = round((float) str_replace(',', '', $invoice_item_quantity ?: '0') * (float) str_replace(',', '', $invoice_item_unit_price ?: '0'), 2))
                                            <tr wire:key="invoice-expense-item-edit-{{ $index }}" class="bg-emerald-400/[0.035]" data-invoice-item-edit-row>
                                                <td class="px-3 py-3"><input wire:model="invoice_item_name" x-on:keydown.enter.prevent.stop="void 0" aria-label="{{ __('finance.fields.item_name') }}" class="w-full min-w-56 rounded-lg px-3 py-2">@error('invoice_item_name')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                                <td class="px-3 py-3"><input wire:model="invoice_item_quantity" x-on:keydown.enter.prevent.stop="void 0" data-thousand-separator inputmode="decimal" aria-label="{{ __('finance.fields.quantity') }}" class="w-full min-w-24 rounded-lg px-3 py-2">@error('invoice_item_quantity')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                                <td class="px-3 py-3"><input wire:model="invoice_item_unit_price" wire:keydown.enter.prevent.stop="saveInvoiceItem" data-thousand-separator inputmode="decimal" aria-label="{{ __('finance.fields.unit_price') }}" class="w-full min-w-32 rounded-lg px-3 py-2">@error('invoice_item_unit_price')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                                <td class="whitespace-nowrap px-3 py-3 font-semibold text-white"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($editingLineAmount, $finalisingRequest->acceptedCurrency) }}</bdi></td>
                                                <td class="px-3 py-3"></td>
                                            </tr>
                                        @else
                                            <tr wire:key="invoice-expense-item-view-{{ $index }}" class="{{ $loop->even ? 'bg-white/[0.045]' : 'bg-black/[0.09]' }}" data-invoice-item-saved-row data-invoice-item-row-tone="{{ $loop->even ? 'even' : 'odd' }}">
                                                <td class="px-3 py-3 font-medium text-white">{{ $item['item_name'] }}</td>
                                                <td class="px-3 py-3"><bdi dir="ltr">{{ $item['quantity'] }}</bdi></td>
                                                <td class="px-3 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount((float) str_replace(',', '', (string) $item['unit_price']), $finalisingRequest->acceptedCurrency) }}</bdi></td>
                                                <td class="whitespace-nowrap px-3 py-3 font-semibold text-white"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($lineAmount, $finalisingRequest->acceptedCurrency) }}</bdi></td>
                                                <td class="px-3 py-3"><div class="flex items-center justify-end gap-2">
                                                    <button type="button" wire:click="editInvoiceItem({{ $index }})" class="inline-flex size-9 items-center justify-center rounded-full border border-white/10 text-neutral-300 transition hover:border-emerald-300/30 hover:bg-emerald-400/10 hover:text-white" title="{{ __('crud.common.actions.edit') }}" aria-label="{{ __('crud.common.actions.edit') }}" data-invoice-item-edit>
                                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.651 2.651M18.75 2.999a1.875 1.875 0 0 1 2.652 2.652L8.582 18.47 3 21l2.53-5.582L18.75 2.999Z" /></svg>
                                                    </button>
                                                    <button type="button" wire:click="removeInvoiceItem({{ $index }})" class="inline-flex size-9 items-center justify-center rounded-full border border-red-300/20 text-red-200 transition hover:border-red-300/40 hover:bg-red-400/10 hover:text-red-100" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-invoice-item-delete>
                                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5m-10.5 4.5v6m4.5-6v6m-8.25-10.5.75 13.5h10.5l.75-13.5M9 6.75V4.5h6v2.25" /></svg>
                                                    </button>
                                                </div></td>
                                            </tr>
                                        @endif
                                    @endforeach
                                    @if ($editing_invoice_item_index === null)
                                        @php($draftLineAmount = round((float) str_replace(',', '', $invoice_item_quantity ?: '0') * (float) str_replace(',', '', $invoice_item_unit_price ?: '0'), 2))
                                        <tr wire:key="invoice-expense-item-draft" class="bg-white/[0.025]" data-invoice-item-draft-row>
                                            <td class="px-3 py-3"><input wire:model="invoice_item_name" x-on:keydown.enter.prevent.stop="void 0" aria-label="{{ __('finance.fields.item_name') }}" class="w-full min-w-56 rounded-lg px-3 py-2">@error('invoice_item_name')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                            <td class="px-3 py-3"><input wire:model="invoice_item_quantity" x-on:keydown.enter.prevent.stop="void 0" data-thousand-separator inputmode="decimal" aria-label="{{ __('finance.fields.quantity') }}" class="w-full min-w-24 rounded-lg px-3 py-2">@error('invoice_item_quantity')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                            <td class="px-3 py-3"><input wire:model="invoice_item_unit_price" wire:keydown.enter.prevent.stop="saveInvoiceItem" data-thousand-separator inputmode="decimal" aria-label="{{ __('finance.fields.unit_price') }}" class="w-full min-w-32 rounded-lg px-3 py-2">@error('invoice_item_unit_price')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-white"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($draftLineAmount, $finalisingRequest->acceptedCurrency) }}</bdi></td>
                                            <td class="px-3 py-3"></td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>

                    @error('invoice_items')<div class="text-sm text-red-400">{{ $message }}</div>@enderror
                    @error('confirm_invoice_overage')<div class="rounded-xl border border-amber-400/20 bg-amber-500/10 p-4 text-sm text-amber-100">{{ $message }}<label class="mt-3 flex gap-2"><input wire:model="confirm_invoice_overage" type="checkbox">{{ __('finance.messages.use_invoice_total') }}</label></div>@enderror
                    <div class="flex justify-end"><button class="pill-link pill-link--accent">{{ $editingInvoiceId ? __('crud.common.actions.save') : __('finance.actions.finalise') }}</button></div>
                </form>
            @endif
        @endif
    </x-admin.modal>

    <x-admin.modal :show="$viewingInvoiceId !== null" :title="__('finance.actions.view_invoice')" close-method="$set('viewingInvoiceId', null)" max-width="3xl">
        <x-slot:header-actions>
            @if ($viewingInvoice?->original_image_path)
                <a href="{{ asset('storage/'.$viewingInvoice->original_image_path) }}" target="_blank" rel="noopener" class="admin-modal__close" title="{{ __('finance.actions.view_attachment') }}" aria-label="{{ __('finance.actions.view_attachment') }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" />
                        <circle cx="12" cy="12" r="2.75" />
                    </svg>
                </a>
            @endif
            @if ($viewingInvoice)
                <a href="{{ route('finance.invoices.print', $viewingInvoice) }}" target="_blank" rel="noopener" class="admin-modal__close" title="{{ __('finance.actions.print_a5') }}" aria-label="{{ __('finance.actions.print_a5') }}" data-invoice-print-icon>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6v-7Zm12-2h.01" />
                    </svg>
                </a>
            @endif
        </x-slot:header-actions>
        @if ($viewingInvoice)
            <div class="grid grid-cols-2 gap-x-5 gap-y-2 rounded-xl border border-white/8 bg-white/4 p-3 text-sm">
                <div><span class="kpi-label">{{ __('finance.fields.invoice_no') }}:</span> <span class="text-white">{{ $viewingInvoice->invoice_no }}</span></div>
                <div><span class="kpi-label">{{ __('finance.fields.original_invoice_no') }}:</span> <bdi dir="ltr" class="inline-block text-white">{{ $viewingInvoice->original_invoice_no ?: '—' }}</bdi></div>
                <div><span class="kpi-label">{{ __('finance.fields.invoice_issuer') }}:</span> <span class="text-white">{{ $viewingInvoice->invoicer_name }}</span></div>
                <div><span class="kpi-label">{{ __('finance.common.date') }}:</span> <span class="text-white">{{ $viewingInvoice->issue_date?->format('d-m-Y') ?: '—' }}</span></div>
            </div>
            <div class="mt-4 overflow-hidden rounded-xl border border-white/8 bg-white/4" data-invoice-view-items-box><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th class="px-4 py-3 text-left">#</th><th class="px-4 py-3 text-left">{{ __('finance.fields.item_name') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.quantity') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.unit_price') }}</th><th class="px-4 py-3 text-left">{{ __('finance.fields.amount') }}</th></tr></thead><tbody>@foreach ($viewingInvoice->items as $item)<tr><td class="px-4 py-3">{{ $item->line_no ?: $loop->iteration }}</td><td class="px-4 py-3">{{ $item->item_name }}</td><td class="px-4 py-3">{{ $item->quantity }}</td><td class="px-4 py-3">{{ $item->unit_price }}</td><td class="px-4 py-3">{{ $item->amount }}</td></tr>@endforeach</tbody><tfoot><tr><th colspan="4" class="px-4 py-3 text-right">{{ __('finance.fields.subtotal') }}</th><td class="px-4 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($viewingInvoice->subtotal, $viewingInvoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr><tr><th colspan="4" class="px-4 py-3 text-right">{{ __('finance.fields.deduction') }}</th><td class="px-4 py-3"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount(-(float) $viewingInvoice->discount, $viewingInvoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr><tr><th colspan="4" class="px-4 py-3 text-right text-white">{{ __('finance.fields.grand_total') }}</th><td class="px-4 py-3 font-semibold text-white"><bdi dir="ltr">{{ app(FinanceService::class)->formatCurrencyAmount($viewingInvoice->total, $viewingInvoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr></tfoot></table></div></div>
        @endif
    </x-admin.modal>
</div>
