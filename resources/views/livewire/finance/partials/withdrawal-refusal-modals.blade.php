<x-admin.modal :show="$refusingRequestId !== null" :title="__('finance.refusal.reason')" close-method="closeRefusalModal" max-width="xl">
    @if ($refusingRequestId !== null)
        <form wire:submit="saveRefusal" class="space-y-4" data-withdrawal-refusal-form novalidate>
            <p class="text-sm opacity-75"><bdi dir="ltr">{{ $reviewRequest?->request_no }}</bdi></p>
            <div>
                <label for="withdrawal-refusal-reason" class="mb-2 block text-sm">{{ __('finance.refusal.reason') }}</label>
                <textarea id="withdrawal-refusal-reason" wire:model="refusal_reason" rows="4" maxlength="2000" required aria-required="true" @error('refusal_reason') aria-invalid="true" aria-describedby="withdrawal-refusal-error" @enderror class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('refusal_reason')<p id="withdrawal-refusal-error" class="mt-1 text-sm text-red-400" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="admin-action-cluster admin-action-cluster--end">
                <x-admin.save-button wire:loading.attr="disabled" wire:target="saveRefusal" data-withdrawal-refusal-save />
            </div>
        </form>
    @endif
</x-admin.modal>

<x-admin.modal :show="$refusalReasonRequestId !== null" :title="__('finance.refusal.reason')" close-method="closeRefusalReason" max-width="xl">
    @if ($refusalReasonRequestId !== null)
        @php($refusedRequest = $this->refusalReasonRequest)
        <div class="space-y-4" data-withdrawal-refusal-details>
            <p class="text-sm opacity-75"><bdi dir="ltr">{{ $refusedRequest->request_no }}</bdi></p>
            <p class="soft-callout whitespace-pre-wrap break-words p-4 text-start text-sm">{{ $refusedRequest->review_notes ?: __('finance.refusal.not_recorded') }}</p>
        </div>
    @endif
</x-admin.modal>
