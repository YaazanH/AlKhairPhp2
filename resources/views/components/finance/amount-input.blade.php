@props([
    'amountModel',
    'currencyModel',
    'currencies' => collect(),
    'amountLive' => false,
    'currencyLive' => true,
    'placeholder' => '',
])

<div class="finance-amount-input" dir="ltr" data-finance-amount-input>
    <select
        @if ($currencyLive) wire:model.live="{{ $currencyModel }}" @else wire:model="{{ $currencyModel }}" @endif
        class="finance-amount-input__currency rounded-xl px-3 py-3 text-sm"
        data-clearable="false"
        data-finance-currency-required="true"
        data-search-placeholder=""
        aria-label="{{ __('finance.common.currency') }}"
    >
        @foreach ($currencies as $currency)
            <option value="{{ $currency->id }}">{{ $currency->code }}</option>
        @endforeach
    </select>
    <input
        @if ($amountLive) wire:model.live="{{ $amountModel }}" @else wire:model="{{ $amountModel }}" @endif
        type="text"
        inputmode="decimal"
        data-thousand-separator
        class="finance-amount-input__value w-full rounded-xl px-4 py-3 text-left text-sm"
        placeholder="{{ $placeholder }}"
    >
</div>
