@props([
    'id' => null,
    'model',
    'placeholder' => '',
    'required' => false,
    'value' => '',
])

@php
    $phoneCountries = \App\Support\PhoneCountries::options();
    $inputId = $id ?: 'phone-'.str_replace(['.', '_'], '-', $model);
    $dialOptions = $phoneCountries
        ->mapWithKeys(fn (array $country) => [$country['region'].'|'.$country['dial_code'] => $country['dial_code']])
        ->all();
@endphp

<div
    class="grid grid-cols-[minmax(8.5rem,0.8fr)_minmax(0,1.2fr)] gap-2"
    dir="ltr"
    x-data="{
        nationalNumber: '',
        regionDial: 'SY|+963',
        dialOptions: @js($dialOptions),
        initialPhone: @js((string) $value),
        init() {
            const full = this.initialPhone.trim();

            if (! full.startsWith('+')) {
                this.nationalNumber = full;
                return;
            }

            const matches = Object.entries(this.dialOptions)
                .filter(([, dial]) => full.startsWith(dial))
                .sort((left, right) => right[1].length - left[1].length);

            if (matches.length) {
                this.regionDial = matches[0][0];
                this.nationalNumber = full.slice(matches[0][1].length);
            } else {
                this.nationalNumber = full;
            }
        },
        syncPhone() {
            const dial = this.dialOptions[this.regionDial] || '+963';
            const national = this.nationalNumber.replace(/\D/g, '').replace(/^0+/, '');
            const full = national ? dial + national : '';

            this.$refs.fullPhone.value = full;
            this.$refs.fullPhone.dispatchEvent(new Event('input', { bubbles: true }));
        },
    }"
>
    <select
        x-model="regionDial"
        x-on:change="syncPhone"
        class="w-full rounded-xl px-3 py-3 text-left text-sm"
        style="direction: ltr; unicode-bidi: isolate;"
        aria-label="{{ __('phone.country_code') }}"
    >
        @foreach ($phoneCountries as $country)
            <option value="{{ $country['region'] }}|{{ $country['dial_code'] }}">
                {{ $country['flag'] }} {{ $country['dial_code'] }} · {{ $country['name'] }}
            </option>
        @endforeach
    </select>

    <input
        id="{{ $inputId }}"
        x-model="nationalNumber"
        x-on:input.debounce.150ms="syncPhone"
        type="tel"
        inputmode="tel"
        autocomplete="tel-national"
        dir="ltr"
        class="w-full rounded-xl px-4 py-3 text-left text-sm"
        style="unicode-bidi: isolate;"
        placeholder="{{ $placeholder ?: __('phone.number_placeholder') }}"
        @required($required)
    >

    <input x-ref="fullPhone" wire:model="{{ $model }}" type="hidden" value="{{ $value }}">
</div>
