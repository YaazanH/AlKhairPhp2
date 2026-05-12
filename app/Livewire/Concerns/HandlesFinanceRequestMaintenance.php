<?php

namespace App\Livewire\Concerns;

use App\Models\FinanceRequest;
use App\Services\FinanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

trait HandlesFinanceRequestMaintenance
{
    public ?int $editingFinanceRequestId = null;
    public bool $edit_supports_counterparty_name = false;
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
        $this->edit_counterparty_name = $request->counterparty_name ?: '';
        $this->edit_request_date = $transactionDate?->format('Y-m-d') ?: now()->toDateString();
        $this->edit_requested_reason = $request->requested_reason ?: '';
        $this->resetValidation();
    }

    public function closeFinanceRequestEditModal(): void
    {
        $this->editingFinanceRequestId = null;
        $this->edit_supports_counterparty_name = false;
        $this->edit_counterparty_name = '';
        $this->edit_request_date = '';
        $this->edit_requested_reason = '';
        $this->resetValidation();
    }

    public function saveFinanceRequestEdit(): void
    {
        $this->authorizePermission('finance.entries.update');

        $request = $this->financeRequestMaintenanceQuery()->findOrFail((int) $this->editingFinanceRequestId);

        $validated = $this->validate([
            'edit_counterparty_name' => ['nullable', 'string', 'max:255'],
            'edit_request_date' => ['required', 'date'],
            'edit_requested_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        app(FinanceService::class)->updateFinanceRequestEntry($request, [
            'counterparty_name' => in_array($request->type, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true)
                ? ($validated['edit_counterparty_name'] ?: null)
                : $request->counterparty_name,
            'request_date' => $validated['edit_request_date'],
            'requested_reason' => $validated['edit_requested_reason'] ?: null,
        ], auth()->user());

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
