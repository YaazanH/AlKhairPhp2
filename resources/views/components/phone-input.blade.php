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
    $initial = \App\Support\PhoneNumberFormatter::split($value);
    $isRtl = app()->isLocale('ar');
@endphp

<div
    wire:key="phone-input-{{ $inputId }}-{{ md5((string) $value) }}"
    class="grid grid-cols-[minmax(5.5rem,0.4fr)_minmax(0,1.6fr)] gap-2"
    dir="ltr"
    x-data="{
        open: false,
        search: '',
        countries: @js($phoneCountries->values()->all()),
        region: @js($initial['region'] ?? 'SY'),
        nationalNumber: @js($initial['national_number'] ?? ''),
        init() {
            this.nationalNumber = this.formatNational(this.nationalNumber);
            this.syncPhone();
        },
        get selectedCountry() {
            return this.countries.find(country => country.region === this.region)
                || this.countries.find(country => country.region === 'SY');
        },
        get selectedDial() {
            return this.selectedCountry?.dial_code || '+963';
        },
        get filteredCountries() {
            const term = this.search.trim().toLocaleLowerCase();
            if (! term) return this.countries;
            return this.countries.filter(country =>
                `${country.name} ${country.region} ${country.dial_code}`.toLocaleLowerCase().includes(term)
            );
        },
        chooseCountry(country) {
            this.region = country.region;
            this.open = false;
            this.search = '';
            this.syncPhone();
            this.$nextTick(() => this.$refs.nationalPhone.focus());
        },
        formatNational(value) {
            const digits = String(value || '').replace(/\D/g, '').replace(/^0+/, '');
            const pattern = this.selectedCountry?.pattern || '##########';
            let formatted = '';
            let digitIndex = 0;

            for (const character of pattern) {
                if (character === '#') {
                    if (digitIndex >= digits.length) break;
                    formatted += digits[digitIndex++];
                } else if (digits.length && (digitIndex < digits.length || digitIndex === 0)) {
                    formatted += character;
                }
            }

            if (digitIndex < digits.length) formatted += digits.slice(digitIndex);
            return formatted;
        },
        syncPhone() {
            const raw = String(this.nationalNumber || '').trim();
            if (raw.startsWith('+')) {
                const pastedCountry = [...this.countries]
                    .filter(country => raw.startsWith(country.dial_code))
                    .sort((a, b) => b.dial_code.length - a.dial_code.length)[0];
                if (pastedCountry) {
                    this.region = pastedCountry.region;
                    this.nationalNumber = raw.slice(pastedCountry.dial_code.length);
                }
            }

            const national = String(this.nationalNumber || '').replace(/\D/g, '').replace(/^0+/, '');
            this.nationalNumber = this.formatNational(national);
            const full = national ? this.selectedDial + national : '';
            this.$refs.fullPhone.value = full;
            this.$refs.fullPhone.dispatchEvent(new Event('input', { bubbles: true }));
        },
    }"
>
    <div class="relative" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
        <button
            type="button"
            class="flex w-full items-center justify-between rounded-xl px-3 py-3 text-sm"
            dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
            style="unicode-bidi: isolate;"
            x-on:click="open = ! open; if (open) $nextTick(() => $refs.countrySearch.focus())"
            x-bind:aria-expanded="open"
            aria-haspopup="listbox"
            aria-label="{{ __('phone.country_code') }}"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span class="truncate" x-text="selectedDial"></span>
            </span>
            <svg class="h-4 w-4 text-neutral-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </button>

        <div
            x-cloak
            x-show="open"
            x-transition
            class="absolute left-0 z-50 mt-2 w-max min-w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-neutral-700 bg-neutral-900 shadow-2xl"
        >
            <div class="border-b border-neutral-700 p-2">
                <input x-ref="countrySearch" x-model="search" type="search" class="w-full rounded-lg px-3 py-2 text-sm" placeholder="{{ __('phone.search_country') }}">
            </div>
            <div class="phone-country-options max-h-72 touch-pan-y overflow-y-auto overscroll-contain py-1" role="listbox" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
                <template x-for="country in filteredCountries" :key="country.region">
                    <button
                        type="button"
                        class="phone-country-option w-full gap-3 px-3 py-2 text-sm hover:bg-white/10"
                        x-on:click="chooseCountry(country)"
                        x-bind:class="country.region === region ? 'bg-white/10 text-emerald-300' : 'text-neutral-200'"
                        role="option"
                    >
                        <img x-bind:src="`https://flagcdn.com/32x24/${country.region.toLowerCase()}.png`" x-bind:alt="country.name" class="h-6 w-8 shrink-0 rounded-md object-cover">
                        <span class="whitespace-nowrap text-xs text-neutral-400" x-text="country.dial_code"></span>
                        <span class="whitespace-nowrap" x-text="country.name"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <input
        id="{{ $inputId }}"
        x-ref="nationalPhone"
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
