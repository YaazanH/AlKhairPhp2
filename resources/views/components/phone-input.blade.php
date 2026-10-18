@props([
    'id' => null,
    'model',
    'placeholder' => '',
    'required' => false,
    'value' => '',
    'live' => false,
    'duplicate' => null,
])

@php
    $phoneCountries = \App\Support\PhoneCountries::options();
    $inputId = $id ?: 'phone-'.str_replace(['.', '_'], '-', $model);
    $initial = \App\Support\PhoneNumberFormatter::split($value);
    $isRtl = app()->isLocale('ar');
@endphp

{{-- Preserve the editor state while Livewire updates the model and duplicate warning. --}}
<div
    wire:key="phone-input-{{ $inputId }}-{{ $live ? 'live' : md5((string) $value) }}"
    wire:ignore.self
    class="grid grid-cols-[max-content_minmax(0,1fr)] gap-2"
    data-phone-input
    dir="ltr"
    x-data="{
        open: false,
        search: '',
        menuStyle: {},
        optionsStyle: {},
        countries: @js($phoneCountries->values()->all()),
        region: @js($initial['region'] ?? 'SY'),
        nationalNumber: @js($initial['national_number'] ?? ''),
        init() {
            this.nationalNumber = this.formatNational(this.nationalNumber);
            this.syncPhone();
            this.$watch('$wire.' + @js($model), value => {
                if (value !== this.$wire.$get(@js($model))) return;
                const full = String(value || '');
                const national = String(this.nationalNumber || '').replace(/\D/g, '').replace(/^0+/, '');
                if (full === (national ? this.selectedDial + national : '')) return;
                this.syncPhone(full);
            });
        },
        toggleCountryMenu() {
            this.open = ! this.open;

            if (! this.open) return;

            this.$nextTick(() => {
                this.positionCountryMenu();
                this.$refs.countrySearch.focus();
            });
        },
        positionCountryMenu() {
            if (! this.open) return;

            const trigger = this.$refs.countryTrigger;
            const menu = this.$refs.countryMenu;
            const options = this.$refs.countryOptions;
            if (! trigger || ! menu || ! options) return;

            const scrollHost = trigger.closest('.admin-modal__body');
            const viewport = window.visualViewport;
            const viewportTop = viewport?.offsetTop || 0;
            const viewportLeft = viewport?.offsetLeft || 0;
            const viewportBottom = viewportTop + (viewport?.height || window.innerHeight);
            const viewportRight = viewportLeft + (viewport?.width || window.innerWidth);
            const hostRect = scrollHost?.getBoundingClientRect();
            const triggerRect = trigger.getBoundingClientRect();
            const wrapperRect = trigger.parentElement.getBoundingClientRect();
            const scale = wrapperRect.width / trigger.parentElement.offsetWidth || 1;
            const inset = 12;
            const gap = 8;
            const bounds = {
                top: Math.max(viewportTop, hostRect?.top ?? viewportTop) + inset,
                bottom: Math.min(viewportBottom, hostRect?.bottom ?? viewportBottom) - inset,
                left: Math.max(viewportLeft, hostRect?.left ?? viewportLeft) + inset,
                right: Math.min(viewportRight, hostRect?.right ?? viewportRight) - inset,
            };
            const availableBelow = Math.max(0, bounds.bottom - triggerRect.bottom - gap);
            const availableAbove = Math.max(0, triggerRect.top - bounds.top - gap);
            const desiredHeight = Math.min(menu.scrollHeight, 352 * scale);
            const openAbove = availableBelow < desiredHeight && availableAbove > availableBelow;
            const height = Math.min(desiredHeight, openAbove ? availableAbove : availableBelow);
            const desiredWidth = Math.max(triggerRect.width, menu.scrollWidth);
            const width = Math.min(desiredWidth, Math.max(0, bounds.right - bounds.left));
            const left = Math.max(bounds.left, Math.min(triggerRect.left, bounds.right - width));
            const headerHeight = this.$refs.countrySearch.parentElement.offsetHeight * scale;

            this.menuStyle = {
                left: `${(left - wrapperRect.left) / scale}px`,
                right: 'auto',
                width: `${width / scale}px`,
                maxWidth: `${width / scale}px`,
                maxHeight: `${height / scale}px`,
                top: openAbove ? 'auto' : `${(triggerRect.bottom - wrapperRect.top + gap) / scale}px`,
                bottom: openAbove ? `${(wrapperRect.bottom - triggerRect.top + gap) / scale}px` : 'auto',
                marginTop: '0',
            };
            this.optionsStyle = {
                maxHeight: `${Math.max(0, height - headerHeight) / scale}px`,
            };
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
        syncPhone(value = this.nationalNumber) {
            const input = this.$refs.nationalPhone;
            const focused = document.activeElement === input;
            const raw = String(value || '').trim();
            const selectionStart = input.selectionStart ?? raw.length;
            const selectionEnd = input.selectionEnd ?? selectionStart;
            const selectionDirection = input.selectionDirection;
            const atEnd = selectionStart === raw.length && selectionEnd === raw.length;
            let nationalValue = raw;
            let removedDigits = 0;
            if (raw.startsWith('+')) {
                const pastedCountry = [...this.countries]
                    .filter(country => raw.startsWith(country.dial_code))
                    .sort((a, b) => b.dial_code.length - a.dial_code.length)[0];
                if (pastedCountry) {
                    this.region = pastedCountry.region;
                    nationalValue = raw.slice(pastedCountry.dial_code.length);
                    removedDigits = pastedCountry.dial_code.replace(/\D/g, '').length;
                }
            }

            const digits = nationalValue.replace(/\D/g, '');
            const national = digits.replace(/^0+/, '');
            removedDigits += digits.length - national.length;
            this.nationalNumber = this.formatNational(national);
            const full = national ? this.selectedDial + national : '';
            if (this.$refs.fullPhone.value !== full) {
                this.$refs.fullPhone.value = full;
                this.$refs.fullPhone.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (focused) {
                const formatted = this.nationalNumber;
                const position = offset => {
                    const count = Math.max(0, raw.slice(0, offset).replace(/\D/g, '').length - removedDigits);
                    if (count === 0) return 0;
                    let seen = 0;
                    for (let index = 0; index < formatted.length; index++) {
                        if (/\d/.test(formatted[index]) && ++seen === count) return index + 1;
                    }
                    return formatted.length;
                };
                const start = atEnd ? formatted.length : position(selectionStart);
                const end = atEnd ? formatted.length : position(selectionEnd);
                this.$nextTick(() => {
                    if (document.activeElement === input && input.value === formatted) {
                        input.setSelectionRange(start, end, selectionDirection);
                    }
                });
            }
        },
    }"
>
    <div class="relative" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" x-on:resize.window="if (open) $nextTick(() => positionCountryMenu())">
        <button
            x-ref="countryTrigger"
            type="button"
            class="phone-country-trigger inline-flex w-auto items-center justify-between gap-2 rounded-xl px-3 py-3 text-sm"
            dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
            style="unicode-bidi: isolate;"
            x-on:click="toggleCountryMenu()"
            x-bind:aria-expanded="open"
            aria-haspopup="listbox"
            aria-label="{{ __('phone.country_code') }}"
        >
            <bdi dir="ltr" class="whitespace-nowrap" x-text="selectedDial"></bdi>
            <svg class="h-4 w-4 text-neutral-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </button>

        <div
            x-ref="countryMenu"
            x-cloak
            x-show="open"
            x-transition
            x-bind:style="menuStyle"
            data-phone-country-menu
            class="phone-country-menu absolute left-0 z-50 mt-2 w-max max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-neutral-700 bg-neutral-900 shadow-2xl"
        >
            <div class="border-b border-neutral-700 p-2">
                <input x-ref="countrySearch" x-model.debounce.500ms="search" type="search" class="w-full rounded-lg px-3 py-2 text-sm" placeholder="{{ __('phone.search_country') }}">
            </div>
            <div x-ref="countryOptions" x-bind:style="optionsStyle" class="phone-country-options max-h-72 touch-pan-y overflow-x-hidden overflow-y-auto overscroll-contain py-1" role="listbox" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
                <template x-for="country in filteredCountries" :key="country.region">
                    <button
                        type="button"
                        class="phone-country-option w-full px-3 py-2 text-sm hover:bg-white/10"
                        x-on:click="chooseCountry(country)"
                        x-bind:class="country.region === region ? 'bg-white/10 text-emerald-300' : 'text-neutral-200'"
                        role="option"
                    >
                        <span class="phone-country-flag" aria-hidden="true">
                            <img x-bind:src="country.flag" x-bind:alt="country.name">
                        </span>
                        <bdi dir="ltr" class="phone-country-dial whitespace-nowrap text-xs text-neutral-400" x-text="country.dial_code"></bdi>
                        <span class="phone-country-name whitespace-nowrap" x-text="country.name"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <div class="profile-duplicate-field">
        <input
            id="{{ $inputId }}"
            x-ref="nationalPhone"
            x-model="nationalNumber"
            x-on:input="syncPhone($event.target.value)"
            type="tel"
            inputmode="tel"
            autocomplete="tel-national"
            dir="ltr"
            class="w-full rounded-xl px-4 py-3 text-left text-sm"
            style="unicode-bidi: isolate;"
            placeholder="{{ $placeholder ?: __('phone.number_placeholder') }}"
            @required($required)
        >

        <x-profile-duplicate-warning :match="$duplicate" :field="$model" />
    </div>

    <input x-ref="fullPhone" wire:model{{ $live ? '.live.debounce.400ms' : '' }}="{{ $model }}" type="hidden" value="{{ $value }}">
</div>
