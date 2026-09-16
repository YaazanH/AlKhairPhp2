<?php

namespace App\Livewire\Concerns;

use App\Models\FinanceRequest;
use App\Services\FinanceService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

trait HandlesWithdrawalRefusal
{
    #[Locked]
    public ?int $refusingRequestId = null;

    public string $refusal_reason = '';

    #[Locked]
    public ?int $refusalReasonRequestId = null;

    public function openRefusalModal(): void
    {
        $this->authorizePermission('finance.pull-requests.review');
        $request = FinanceRequest::query()
            ->where('type', FinanceRequest::TYPE_PULL)
            ->where('status', FinanceRequest::STATUS_PENDING)
            ->findOrFail($this->reviewingRequestId);

        $this->refusingRequestId = $request->id;
        $this->refusal_reason = '';
        $this->resetValidation();
    }

    public function closeRefusalModal(): void
    {
        $this->reset(['refusingRequestId', 'refusal_reason']);
        $this->resetValidation();
    }

    public function saveRefusal(): void
    {
        $this->authorizePermission('finance.pull-requests.review');
        $request = FinanceRequest::query()
            ->where('type', FinanceRequest::TYPE_PULL)
            ->findOrFail($this->refusingRequestId);

        $this->refusal_reason = Str::trim($this->refusal_reason);
        app(FinanceService::class)->declineRequest($request, auth()->user(), $this->refusal_reason);

        $this->closeRefusalModal();
        $this->closeReviewModal();
        session()->flash('status', __('finance.messages.pull_declined'));
        $this->redirectRoute('finance.dashboard', navigate: true);
    }

    public function openRefusalReason(int $requestId): void
    {
        $this->visibleRefusedRequest($requestId);
        $this->refusalReasonRequestId = $requestId;
    }

    public function closeRefusalReason(): void
    {
        $this->refusalReasonRequestId = null;
    }

    #[Computed]
    public function refusalReasonRequest(): ?FinanceRequest
    {
        return $this->refusalReasonRequestId ? $this->visibleRefusedRequest($this->refusalReasonRequestId) : null;
    }

    private function visibleRefusedRequest(int $requestId): FinanceRequest
    {
        $user = auth()->user();
        $canViewAll = $user?->can('finance.pull-requests.review') || $user?->can('finance.reports.view');
        abort_unless($canViewAll || $user?->can('finance.pull-requests.view'), 403);

        return FinanceRequest::query()
            ->where('type', FinanceRequest::TYPE_PULL)
            ->where('status', FinanceRequest::STATUS_DECLINED)
            ->when(! $canViewAll, fn ($query) => $query->where(function ($owner) use ($user) {
                $owner->where('requested_by', $user->id)
                    ->when($user->teacherProfile?->id, fn ($teacher) => $teacher->orWhere('teacher_id', $user->teacherProfile->id));
            }))
            ->findOrFail($requestId);
    }
}
