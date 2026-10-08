@props(['model', 'id', 'value' => ''])
<div x-data="addressInput({ value: @js($value), places: @js(\App\Support\AddressFormatter::suggestions()), endpoint: @js(route('address-suggestions')) })">
    <div class="address-completion">
        <input id="{{ $id }}" x-ref="input" wire:model="{{ $model }}" type="text" autocomplete="off"
            class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.parents.form.placeholders.address') }}"
            aria-autocomplete="inline" aria-describedby="{{ $id }}-completion-hint"
            x-on:input="input($event)" x-on:focus="query = $el.value; focused = true; caret()" x-on:blur="focused = false"
            x-on:click="caret()" x-on:keyup="caret()" x-on:scroll="caret()"
            x-on:keydown.arrow-down.prevent="cycle(1)" x-on:keydown.arrow-up.prevent="cycle(-1)"
            x-on:keydown.tab="if (!$event.shiftKey) accept($event)" x-on:keydown.enter="accept($event)"
            x-on:keydown.escape.stop="dismissed = true">
        <div class="address-completion__ghost rounded-xl px-4 text-sm" aria-hidden="true" x-cloak x-show="suffix"><span class="address-completion__line"><span class="address-completion__typed" x-text="query"></span><span x-text="suffix"></span></span></div>
    </div>
    <span id="{{ $id }}-completion-hint" class="sr-only">{{ __('crud.parents.form.address_completion_hint') }}</span>
    <span class="sr-only" role="status" x-text="suffix ? selected.value : ''"></span>
    <span class="address-completion__credit" x-cloak x-show="suffix && selected?.source === 'photon'">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a></span>
</div>
