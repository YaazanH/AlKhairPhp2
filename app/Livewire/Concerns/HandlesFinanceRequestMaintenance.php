<?php

namespace App\Livewire\Concerns;

use App\Models\FinanceRequest;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

trait HandlesFinanceRequestMaintenance
{
    public ?int $editingFinanceRequestId = null;
    public string $edit_amount = '';
    public ?int $edit_cash_box_id = null;
    public ?int $edit_currency_id = null;
    public ?int $edit_finance_pull_request_kind_id = null;
    public bool $edit_supports_counterparty_name = false;
    public bool $edit_supports_expense_details = false;
    public string $edit_counterparty_name = '';
    public string $edit_request_date = '';
    public string $edit_requested_reason = '';
    public ?int $deletingFinanceRequestId = null;
    public string $delete_reason = '';

    public function openFinanceRequestEditModal(int $requestId): void
    {
        $this->authorizePermission('finance.entries.update');

        $request = $this->financeRequestMaintenanceQuery()->findOrFail($requestId);
        $transactionDate = $request->postedTransaction?->transaction_date
            ?: $request->returnTransaction?->transaction_date
            ?: $request->closingTransaction?->transaction_date
            ?: $request->created_at;

        $this->editingFinanceRequestId = $request->id;
        $this->edit_supports_counterparty_name = in_array($request->type, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true);
        $this->edit_supports_expense_details = $request->type === FinanceRequest::TYPE_EXPENSE;
        $this->edit_amount = (string) ($request->accepted_amount ?? $request->requested_amount ?? '');
        $this->edit_cash_box_id = $request->cash_box_id;
        $this->edit_currency_id = $request->accepted_currency_id ?: $request->requested_currency_id;
        $this->edit_finance_pull_request_kind_id = $request->finance_pull_request_kind_id;
        $this->edit_counterparty_name = $request->counterparty_name ?: '';
        $this->edit_request_date = $transactionDate?->format('Y-m-d') ?: now()->toDateString();
        $this->edit_requested_reason = $request->requested_reason ?: '';
        $this->resetValidation();
    }

    public function closeFinanceRequestEditModal(): void
    {
        $this->editingFinanceRequestId = null;
        $this->edit_amount = '';
        $this->edit_cash_box_id = null;
        $this->edit_currency_id = null;
        $this->edit_finance_pull_request_kind_id = null;
        $this->edit_supports_counterparty_name = false;
        $this->edit_supports_expense_details = false;
        $this->edit_counterparty_name = '';
        $this->edit_request_date = '';
        $this->edit_requested_reason = '';
        $this->resetValidation();
    }

    public function updatedEditCashBoxId(): void
    {
        if ($this->edit_cash_box_id && $this->edit_currency_id && ! app(FinanceService::class)->currenciesForCashBox($this->edit_cash_box_id)->whereKey($this->edit_currency_id)->exists()) {
            $this->edit_currency_id = app(FinanceService::class)->currenciesForCashBox($this->edit_cash_box_id)->value('id');
        }
    }

    public function updatedEditCurrencyId(): void
    {
        if ($this->edit_cash_box_id && $this->edit_currency_id && ! app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->edit_currency_id)->whereKey($this->edit_cash_box_id)->exists()) {
            $this->edit_cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $this->edit_currency_id)?->id;
        }
    }

    public function saveFinanceRequestEdit(): void
    {
        $this->authorizePermission('finance.entries.update');

        $request = $this->financeRequestMaintenanceQuery()->findOrFail((int) $this->editingFinanceRequestId);
        if ($request->type === FinanceRequest::TYPE_EXPENSE && method_exists($this, 'normalizeFinanceNumberProperty')) {
            $this->normalizeFinanceNumberProperty('edit_amount');
        }

        $expenseRules = $request->type === FinanceRequest::TYPE_EXPENSE;

        $validated = $this->validate([
            'edit_amount' => $expenseRules ? ['required', 'numeric', 'gt:0'] : ['nullable'],
            'edit_cash_box_id' => $expenseRules ? ['required', 'exists:finance_cash_boxes,id'] : ['nullable'],
            'edit_counterparty_name' => ['nullable', 'string', 'max:255'],
            'edit_currency_id' => $expenseRules ? ['required', 'exists:finance_currencies,id'] : ['nullable'],
            'edit_finance_pull_request_kind_id' => $expenseRules ? ['required', 'exists:finance_pull_request_kinds,id'] : ['nullable'],
            'edit_request_date' => ['required', 'date'],
            'edit_requested_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(FinanceService::class)->updateFinanceRequestEntry($request, [
                'amount' => $request->type === FinanceRequest::TYPE_EXPENSE ? $validated['edit_amount'] : null,
                'cash_box_id' => $request->type === FinanceRequest::TYPE_EXPENSE ? $validated['edit_cash_box_id'] : null,
                'counterparty_name' => in_array($request->type, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true)
                    ? ($validated['edit_counterparty_name'] ?: null)
                    : $request->counterparty_name,
                'currency_id' => $request->type === FinanceRequest::TYPE_EXPENSE ? $validated['edit_currency_id'] : null,
                'finance_pull_request_kind_id' => $request->type === FinanceRequest::TYPE_EXPENSE ? $validated['edit_finance_pull_request_kind_id'] : null,
                'request_date' => $validated['edit_request_date'],
                'requested_reason' => $validated['edit_requested_reason'] ?: null,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('edit_amount', $this->financeMaintenanceValidationMessage($exception));

            return;
        }

        $this->closeFinanceRequestEditModal();
        session()->flash('status', __('finance.messages.entry_updated'));
    }

    public function openFinanceRequestDeleteModal(int $requestId): void
    {
        $this->authorizePermission('finance.entries.delete');

        $request = $this->financeRequestMaintenanceQuery()->findOrFail($requestId);
        $this->deletingFinanceRequestId = $request->id;
        $this->delete_reason = '';
        $this->resetValidation();
    }

    public function closeFinanceRequestDeleteModal(): void
    {
        $this->deletingFinanceRequestId = null;
        $this->delete_reason = '';
        $this->resetValidation();
    }

    public function deleteFinanceRequestEntry(): void
    {
        $this->authorizePermission('finance.entries.delete');

        $this->validate([
            'delete_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $request = $this->financeRequestMaintenanceQuery()->findOrFail((int) $this->deletingFinanceRequestId);

        try {
            app(FinanceService::class)->deleteFinanceRequestEntry($request, auth()->user(), $this->delete_reason ?: null);
        } catch (ValidationException $exception) {
            $this->addError('delete_reason', $this->financeMaintenanceValidationMessage($exception));

            return;
        }

        $this->closeFinanceRequestDeleteModal();
        session()->flash('status', __('finance.messages.entry_deleted'));
    }

    protected function financeRequestMaintenanceTypes(): array
    {
        return [
            FinanceRequest::TYPE_EXPENSE,
            FinanceRequest::TYPE_PULL,
            FinanceRequest::TYPE_RETURN,
            FinanceRequest::TYPE_REVENUE,
        ];
    }

    protected function financeRequestMaintenanceQuery(): Builder
    {
        return FinanceRequest::query()
            ->with(['closingTransaction', 'postedTransaction', 'returnTransaction'])
            ->whereIn('type', $this->financeRequestMaintenanceTypes());
    }

    protected function financeMaintenanceValidationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (is_array($messages) && isset($messages[0])) {
                return (string) $messages[0];
            }
        }

        return $exception->getMessage();
    }
}
