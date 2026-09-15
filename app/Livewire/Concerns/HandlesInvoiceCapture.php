<?php

namespace App\Livewire\Concerns;

use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\Invoice;
use App\Services\FinanceService;
use App\Services\InvoiceCaptureService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use RuntimeException;

trait HandlesInvoiceCapture
{
    #[Locked]
    public ?array $invoiceCaptureDraft = null;

    #[Locked]
    public ?int $invoiceCaptureRequestId = null;

    #[Locked]
    public int $invoiceCaptureInputVersion = 0;

    public bool $invoiceCaptureApplied = false;

    #[Locked]
    public bool $invoiceCaptureReviewOpen = false;

    #[Locked]
    public bool $invoiceCapturePending = false;

    public array $invoiceCaptureReviewItems = [];

    #[Locked]
    public ?int $invoiceCaptureEditingItemIndex = null;

    public function updatedInvoiceImage(): void
    {
        $request = $this->invoiceCaptureRequest();
        $this->reset(['invoiceCaptureDraft', 'invoiceCaptureRequestId', 'invoiceCaptureApplied', 'invoiceCaptureReviewOpen', 'invoiceCapturePending', 'invoiceCaptureReviewItems', 'invoiceCaptureEditingItemIndex']);
        $this->resetInvoiceCaptureReviewErrors();
        $this->resetErrorBag('invoice_image');
        try {
            $this->validateInvoiceCaptureUpload();
            // Editing an existing invoice only replaces its attachment. Keep
            // saved details and any edits in progress out of the OCR workflow.
            if ($this->editingInvoiceId !== null) {
                return;
            }
            $this->invoiceCaptureRequestId = $request->id;
            $this->invoiceCaptureReviewOpen = true;
            $this->invoiceCapturePending = true;
        } finally {
            $this->dispatch('invoice-capture-finished');
        }
    }

    public function readInvoiceCapture(): void
    {
        $request = $this->invoiceCaptureRequest();
        if ($this->editingInvoiceId !== null || ! $this->invoiceCaptureReviewOpen || ! $this->invoiceCapturePending || $this->invoiceCaptureRequestId !== $request->id) {
            return;
        }
        $this->resetErrorBag('invoice_image');
        try {
            $this->validateInvoiceCaptureUpload();
            $draft = app(InvoiceCaptureService::class)->capture($this->invoice_image);
            $currency = ($request->postedTransaction?->currency ?? $request->acceptedCurrency ?? $request->requestedCurrency)?->code;
            if ($draft['currency'] && $currency && $draft['currency'] !== $currency) {
                $draft['warnings'][] = 'currency_mismatch';
            }
            $draft['currency'] = $currency;
            if ($draft['total'] !== null && $draft['total'] > (float) $request->accepted_amount) {
                $draft['warnings'][] = 'over_approved_amount';
            }
            $originalNumber = Invoice::formatOriginalInvoiceNumber($draft['original_invoice_no']);
            if ($originalNumber && $draft['invoice_issuer']) {
                $cashBoxIds = app(FinanceService::class)->accessibleCashBoxes(auth()->user())->pluck('id');
                $duplicate = Invoice::query()
                    ->when($this->editingInvoiceId, fn ($query) => $query->whereKeyNot($this->editingInvoiceId))
                    ->whereHas('financeRequest', fn ($query) => $query->whereIn('cash_box_id', $cashBoxIds))
                    ->whereRaw('LOWER(TRIM(original_invoice_no)) IN (?, ?, ?)', [
                        Str::lower($originalNumber),
                        Str::lower(Str::after($originalNumber, '№ ')),
                        Str::lower('№'.Str::after($originalNumber, '№ ')),
                    ])
                    ->whereRaw('LOWER(invoicer_name) = ?', [Str::lower($draft['invoice_issuer'])])
                    ->exists();
                if ($duplicate) {
                    $draft['warnings'][] = 'possible_duplicate';
                }
            }

            $draft['warnings'] = array_values(array_unique($draft['warnings']));
            $reviewItems = $draft['review_items'] ?? $draft['invoice_items'];
            $draft['requires_amount_review'] = $reviewItems !== [] && (
                array_intersect(['total_mismatch', 'missing_total', 'unreadable_rows'], $draft['warnings']) !== []
                || array_intersect(['total', 'invoice_deduction'], $draft['uncertain_fields'] ?? []) !== []
            );
            $this->invoiceCaptureReviewItems = array_map(fn ($item) => [
                'item_name' => (string) ($item['item_name'] ?? ''),
                'quantity' => (string) ($item['quantity'] ?? ''),
                'unit_price' => (string) ($item['unit_price'] ?? ''),
            ], $reviewItems);
            $this->invoiceCaptureDraft = $draft;
            $this->invoiceCaptureRequestId = $request->id;
        } catch (RuntimeException $exception) {
            $key = str_starts_with($exception->getMessage(), 'invoice_capture.errors.')
                ? $exception->getMessage()
                : 'invoice_capture.errors.unreadable';
            $this->addError('invoice_image', __($key, ['pages' => config('invoice_capture.max_pages')]));
            $this->invoiceCaptureReviewOpen = false;
        } finally {
            $this->invoiceCapturePending = false;
            $this->invoiceCaptureInputVersion++;
            $this->dispatch('invoice-capture-finished');
        }
    }

    public function applyInvoiceCapture(): void
    {
        $request = $this->invoiceCaptureRequest();
        if ($this->editingInvoiceId !== null) {
            $this->resetInvoiceCapture();

            return;
        }
        if (! $this->invoiceCaptureDraft || $this->invoiceCaptureRequestId !== $request->id) {
            $this->addError('invoice_image', __('invoice_capture.errors.stale'));

            return;
        }
        $this->validateInvoiceCaptureUpload();

        $draft = $this->invoiceCaptureDraft;
        $draft['invoice_items'] = $this->validateInvoiceCaptureAmounts();

        // Accept transfers a draft; missing values are validated when saving.
        // Missing values must not silently retain another receipt's details.
        foreach (['original_invoice_no', 'invoice_issuer', 'invoice_date'] as $field) {
            $this->{$field} = (string) ($draft[$field] ?? '');
        }
        $this->original_invoice_no = Invoice::formatOriginalInvoiceNumber($this->original_invoice_no) ?? '';
        $this->invoice_deduction = (string) ($draft['invoice_deduction'] ?? '0');
        $this->invoice_items = array_values($draft['invoice_items']);
        $this->invoice_notes = '';
        $this->resetInvoiceItemDraft();

        $this->invoiceCaptureDraft = null;
        $this->invoiceCaptureRequestId = null;
        $this->invoiceCaptureReviewOpen = false;
        $this->invoiceCaptureApplied = true;
        $this->reset(['invoiceCaptureReviewItems', 'invoiceCaptureEditingItemIndex']);
        $this->confirm_invoice_overage = false;
        $this->resetValidation();
        $this->dispatch('invoice-capture-finished');
    }

    public function discardInvoiceCapture(): void
    {
        $this->authorizePermission('finance.expense-requests.review');
        $this->resetInvoiceCapture();
    }

    protected function resetInvoiceCapture(): void
    {
        $this->reset(['invoiceCaptureDraft', 'invoiceCaptureRequestId', 'invoiceCaptureApplied', 'invoiceCaptureReviewOpen', 'invoiceCapturePending', 'invoiceCaptureReviewItems', 'invoiceCaptureEditingItemIndex']);
        $this->resetInvoiceCaptureReviewErrors();
        $this->invoiceCaptureInputVersion++;
        $this->resetErrorBag('invoice_image');
        $this->dispatch('invoice-capture-reset');
    }

    public function editInvoiceCaptureReviewItem(int $index): void
    {
        $request = $this->invoiceCaptureRequest();
        if ($this->invoiceCaptureRequestId === $request->id && $this->invoiceCaptureDraft && $this->invoiceCaptureReviewOpen) {
            abort_unless(array_key_exists($index, $this->invoiceCaptureReviewItems), 404);
            if ($this->invoiceCaptureEditingItemIndex !== null) {
                $this->invoiceCaptureReviewItems = $this->validateInvoiceCaptureAmounts();
            }
            $this->invoiceCaptureEditingItemIndex = $index;
        }
    }

    public function saveInvoiceCaptureReviewItem(): void
    {
        $request = $this->invoiceCaptureRequest();
        if ($this->invoiceCaptureRequestId === $request->id && $this->invoiceCaptureDraft && $this->invoiceCaptureReviewOpen && $this->invoiceCaptureEditingItemIndex !== null) {
            $this->invoiceCaptureReviewItems = $this->validateInvoiceCaptureAmounts();
            $this->invoiceCaptureEditingItemIndex = null;
        }
    }

    public function removeInvoiceCaptureReviewItem(int $index): void
    {
        $request = $this->invoiceCaptureRequest();
        if ($this->invoiceCaptureRequestId === $request->id && $this->invoiceCaptureDraft && $this->invoiceCaptureReviewOpen) {
            unset($this->invoiceCaptureReviewItems[$index]);
            $this->invoiceCaptureReviewItems = array_values($this->invoiceCaptureReviewItems);
            if ($this->invoiceCaptureEditingItemIndex === $index) {
                $this->invoiceCaptureEditingItemIndex = null;
            } elseif ($this->invoiceCaptureEditingItemIndex !== null && $this->invoiceCaptureEditingItemIndex > $index) {
                $this->invoiceCaptureEditingItemIndex--;
            }
            $this->resetInvoiceCaptureReviewErrors();
        }
    }

    #[Computed]
    public function invoiceCaptureLineTotals(): array
    {
        return array_map(function ($item): ?float {
            if (! is_array($item)) {
                return null;
            }
            $quantity = $this->normalizeInvoiceCaptureNumber($item['quantity'] ?? '');
            $price = $this->normalizeInvoiceCaptureNumber($item['unit_price'] ?? '');
            if (! is_string($quantity) || ! is_string($price)
                || ! is_numeric($quantity) || ! is_numeric($price)
                || (float) $quantity < 0 || (float) $quantity > 1000000
                || (float) $price < 0 || (float) $price > 1000000000) {
                return null;
            }

            return round((float) $quantity * (float) $price, 2);
        }, $this->invoiceCaptureReviewItems);
    }

    private function normalizeInvoiceCaptureNumber(mixed $value): mixed
    {
        return is_scalar($value) ? $this->normalizeFinanceNumber(strtr((string) $value, array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹٫', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789.')
        ))) : $value;
    }

    private function validateInvoiceCaptureAmounts(): array
    {
        $this->resetInvoiceCaptureReviewErrors();
        foreach ($this->invoiceCaptureReviewItems as &$item) {
            if (is_array($item)) {
                foreach (['quantity', 'unit_price'] as $field) {
                    $item[$field] = $this->normalizeInvoiceCaptureNumber($item[$field] ?? '');
                }
            }
        }
        unset($item);
        $validated = $this->validate([
            'invoiceCaptureReviewItems' => ['present', 'array', 'max:100'],
            'invoiceCaptureReviewItems.*' => ['array:item_name,quantity,unit_price'],
            'invoiceCaptureReviewItems.*.item_name' => ['nullable', 'string', 'max:255'],
            'invoiceCaptureReviewItems.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'invoiceCaptureReviewItems.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ], [], [
            'invoiceCaptureReviewItems' => __('invoice_capture.correction_items'),
            'invoiceCaptureReviewItems.*.item_name' => __('finance.fields.item_name'),
            'invoiceCaptureReviewItems.*.quantity' => __('finance.fields.quantity'),
            'invoiceCaptureReviewItems.*.unit_price' => __('finance.fields.unit_price'),
        ]);

        $subtotal = 0;
        foreach ($validated['invoiceCaptureReviewItems'] as $index => $item) {
            $amount = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            if ($amount > 1000000000000) {
                throw ValidationException::withMessages([
                    "invoiceCaptureReviewItems.$index.unit_price" => __('validation.max.numeric', ['attribute' => __('invoice_capture.line_amount'), 'max' => number_format(1000000000000)]),
                ]);
            }
            $subtotal += $amount;
        }
        if ($subtotal > 1000000000000) {
            throw ValidationException::withMessages([
                'invoiceCaptureReviewItems' => __('validation.max.numeric', ['attribute' => __('finance.fields.subtotal'), 'max' => number_format(1000000000000)]),
            ]);
        }

        return array_map(fn (array $item) => [
            'item_name' => trim($item['item_name'] ?? ''),
            'quantity' => (string) ($item['quantity'] ?? ''),
            'unit_price' => (string) ($item['unit_price'] ?? ''),
        ], $validated['invoiceCaptureReviewItems']);
    }

    private function resetInvoiceCaptureReviewErrors(): void
    {
        foreach (array_keys($this->getErrorBag()->messages()) as $key) {
            if (str_starts_with($key, 'invoiceCaptureReview')) {
                $this->resetErrorBag($key);
            }
        }
    }

    private function validateInvoiceCaptureUpload(): void
    {
        $this->validate([
            'invoice_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('uploads.image_max_kb')],
        ], [], ['invoice_image' => __('invoice_capture.file')]);
    }

    private function invoiceCaptureRequest(): FinanceRequest
    {
        $this->authorizePermission('finance.expense-requests.review');
        abort_unless($this->finalisingRequestId, 404);
        $request = FinanceRequest::query()->with(['acceptedCurrency', 'requestedCurrency', 'postedTransaction.currency', 'pullRequestKind', 'category'])->findOrFail($this->finalisingRequestId);
        app(FinanceService::class)->cashBoxForUser((int) $request->cash_box_id, auth()->user());
        abort_unless($this->expenseFinalisationMode($request) === FinancePullRequestKind::MODE_INVOICE, 403);
        if ($this->editingInvoiceId) {
            abort_unless(Invoice::query()->whereKey($this->editingInvoiceId)->where('finance_request_id', $request->id)->where('invoice_type', 'finance')->exists(), 404);
        } else {
            abort_unless($request->status === FinanceRequest::STATUS_ACCEPTED, 403);
        }

        return $request;
    }
}
