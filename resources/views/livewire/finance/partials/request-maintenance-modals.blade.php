<x-admin.modal
    :show="$editingFinanceRequestId !== null"
    :title="__('finance.actions.edit_entry')"
    :description="__('finance.messages.entry_edit_help')"
    close-method="closeFinanceRequestEditModal"
    max-width="3xl"
>
    <form wire:submit="saveFinanceRequestEdit" class="space-y-4">
        @if ($edit_supports_counterparty_name)
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.revenue_name') }}</label>
                <input wire:model="edit_counterparty_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('finance.messages.revenue_name_mask_help') }}</p>
                @error('edit_counterparty_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
        @endif

        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.entry_date') }}</label>
            <input wire:model="edit_request_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
            @error('edit_request_date') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
        </div>

        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('finance.common.description') }}</label>
            <textarea wire:model="edit_requested_reason" rows="3" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
            @error('edit_requested_reason') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
        </div>

        <div class="flex flex-wrap justify-end gap-3">
            <button type="button" wire:click="closeFinanceRequestEditModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
            <button type="submit" class="pill-link pill-link--accent">{{ __('crud.common.actions.save') }}</button>
        </div>
    </form>
</x-admin.modal>

<x-admin.modal
    :show="$deletingFinanceRequestId !== null"
    :title="__('finance.actions.delete_entry')"
    :description="__('finance.messages.entry_delete_help')"
    close-method="closeFinanceRequestDeleteModal"
    max-width="2xl"
>
    <form wire:submit="deleteFinanceRequestEntry" class="space-y-4">
        <div>
            <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.reason') }}</label>
            <textarea wire:model="delete_reason" rows="3" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
            @error('delete_reason') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
        </div>

        <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm leading-6 text-amber-100">
            {{ __('finance.messages.entry_delete_reversal_help') }}
        </div>

        <div class="flex flex-wrap justify-end gap-3">
            <button type="button" wire:click="closeFinanceRequestDeleteModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
            <button type="submit" class="pill-link pill-link--danger">{{ __('finance.actions.delete') }}</button>
        </div>
    </form>
</x-admin.modal>
