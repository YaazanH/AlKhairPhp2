<div class="mt-5 space-y-4" data-invoice-capture-item-editor>
    <div class="overflow-hidden rounded-2xl border border-white/10 bg-black/10" data-invoice-capture-items-table>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('invoice_capture.correction_items') }}">
            <table class="w-full min-w-[46rem] text-sm">
                <thead class="border-b border-white/15 bg-white/[0.04]">
                    <tr>
                        <th scope="col" class="w-[5%] px-3 py-3 text-start">#</th>
                        <th scope="col" class="w-[33%] px-3 py-3 text-start">{{ __('finance.fields.item_name') }}</th>
                        <th scope="col" class="w-[15%] px-3 py-3 text-start">{{ __('finance.fields.quantity') }}</th>
                        <th scope="col" class="w-[19%] px-3 py-3 text-start">{{ __('finance.fields.unit_price') }}</th>
                        <th scope="col" class="w-[18%] px-3 py-3 text-start">{{ __('finance.fields.amount') }}</th>
                        <th scope="col" class="admin-actions-column w-[10%] px-3 py-3 text-center">{{ __('finance.actions.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @foreach ($invoiceCaptureReviewItems as $index => $item)
                        @php($editingCaptureItem = $invoiceCaptureEditingItemIndex === $index)
                        <tr wire:key="capture-{{ $editingCaptureItem ? 'edit' : 'view' }}-{{ $invoiceCaptureInputVersion }}-{{ $index }}" class="{{ $editingCaptureItem ? 'bg-emerald-400/[0.035]' : ($loop->even ? 'bg-white/[0.045]' : 'bg-black/[0.09]') }}" data-invoice-capture-item-row @if ($editingCaptureItem) data-invoice-capture-item-edit-row @else data-invoice-capture-item-view-row @endif>
                            <td class="px-3 py-3" data-invoice-capture-number><bdi dir="ltr">{{ $loop->iteration }}</bdi></td>
                            <td class="px-3 py-3 font-medium text-white">
                                @if ($editingCaptureItem)
                                    <label class="sr-only" for="capture-item-{{ $index }}-name">{{ __('finance.fields.item_name') }}</label>
                                    <input id="capture-item-{{ $index }}-name" wire:model="invoiceCaptureReviewItems.{{ $index }}.item_name" x-on:keydown.enter.prevent.stop="void 0" class="w-full min-w-56 rounded-lg px-3 py-2">
                                @else
                                    {{ filled($item['item_name'] ?? null) ? $item['item_name'] : '—' }}
                                @endif
                                @error("invoiceCaptureReviewItems.$index.item_name")<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </td>
                            @foreach (['quantity' => __('finance.fields.quantity'), 'unit_price' => __('finance.fields.unit_price')] as $number => $numberLabel)
                                <td class="px-3 py-3" data-invoice-capture-number>
                                    @if ($editingCaptureItem)
                                        <label class="sr-only" for="capture-item-{{ $index }}-{{ $number }}">{{ $numberLabel }}</label>
                                        <div class="flex items-center gap-1.5" dir="ltr">
                                            <input id="capture-item-{{ $index }}-{{ $number }}" wire:model.live.debounce.150ms="invoiceCaptureReviewItems.{{ $index }}.{{ $number }}" inputmode="decimal" dir="ltr" class="w-full {{ $number === 'quantity' ? 'min-w-24' : 'min-w-32' }} rounded-lg px-3 py-2" data-invoice-capture-number
                                                @if ($number === 'unit_price') wire:keydown.enter.prevent.stop="saveInvoiceCaptureReviewItem" wire:keydown.tab.prevent.stop="saveInvoiceCaptureReviewItem" @else x-on:keydown.enter.prevent.stop="void 0" @endif>
                                            @if ($number === 'unit_price')
                                                <bdi dir="ltr" class="shrink-0 whitespace-nowrap" data-invoice-capture-price-currency>{{ $captureCurrency?->symbol ?: $captureCurrency?->code }}</bdi>
                                            @endif
                                        </div>
                                    @else
                                        <bdi dir="ltr" class="whitespace-nowrap">{{ ! filled($item[$number] ?? null) ? '—' : ($number === 'unit_price' ? app(\App\Services\FinanceService::class)->formatCurrencyAmount($item[$number], $captureCurrency) : $item[$number]) }}</bdi>
                                    @endif
                                    @error("invoiceCaptureReviewItems.$index.$number")<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-white" data-invoice-capture-number>
                                <bdi dir="ltr" data-invoice-capture-line-total>{{ ($this->invoiceCaptureLineTotals[$index] ?? null) !== null ? app(\App\Services\FinanceService::class)->formatCurrencyAmount($this->invoiceCaptureLineTotals[$index], $captureCurrency) : '—' }}</bdi>
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if ($editingCaptureItem)
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" wire:click="saveInvoiceCaptureReviewItem" class="admin-icon-button admin-icon-button--accent" title="{{ __('crud.common.actions.save') }}" aria-label="{{ __('crud.common.actions.save') }}" data-invoice-capture-save-item data-modal-action-icon-ignore><x-admin-action-icon name="save" /></button>
                                        <button type="button" wire:click="removeInvoiceCaptureReviewItem({{ $index }})" class="admin-icon-button admin-icon-button--danger" title="{{ __('invoice_capture.remove_item') }}" aria-label="{{ __('invoice_capture.remove_item') }}" data-invoice-capture-remove-item data-modal-action-icon-ignore><x-admin-action-icon name="delete" /></button>
                                    </div>
                                @else
                                    <button type="button" wire:click="editInvoiceCaptureReviewItem({{ $index }})" class="admin-icon-button" title="{{ __('crud.common.actions.edit') }}" aria-label="{{ __('crud.common.actions.edit') }}" data-invoice-capture-edit-item data-modal-action-icon-ignore><x-admin-action-icon name="edit" /></button>
                                @endif
                                @error("invoiceCaptureReviewItems.$index")<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @error('invoiceCaptureReviewItems')<p class="text-sm text-red-400">{{ $message }}</p>@enderror
</div>
