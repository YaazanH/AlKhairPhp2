@props(['match' => null, 'field'])
@if ($match)
    @php($message = __('duplicates.'.$match['reason'].'_warning', ['name' => $match['name']]))
    <span class="profile-duplicate-warning" data-duplicate-warning="{{ $field }}"
        x-data="{ tip: false, left: 0, top: 0, showTip() { const r = this.$refs.trigger.getBoundingClientRect(); this.left = Math.max(12, Math.min(r.left, window.innerWidth - 292)); this.top = r.bottom + 8; this.tip = true; } }"
        x-on:keydown.escape.stop="tip = false" x-on:scroll.window="tip = false" x-on:resize.window="tip = false">
        <button type="button" x-ref="trigger" data-modal-action-icon-ignore class="profile-duplicate-warning__button"
            wire:click="reviewProfileDuplicate('{{ $field }}')"
            x-on:mouseenter="showTip()" x-on:mouseleave="tip = false"
            x-on:focus="showTip()" x-on:blur="tip = false" x-on:click="tip = false"
            aria-label="{{ $message }}" aria-describedby="duplicate-tooltip-{{ $field }}" aria-haspopup="dialog">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m10.3 4-8 14a2 2 0 0 0 1.7 3h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v5m0 3v.5" stroke-linecap="round"/></svg>
        </button>
        <template x-teleport="body">
            <span x-cloak x-show="tip" id="duplicate-tooltip-{{ $field }}" role="tooltip" class="profile-duplicate-tooltip" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"
                x-bind:style="{ left: left + 'px', top: top + 'px' }"
                x-effect="if (tip) $nextTick(() => window.justifyDuplicateWarningTip($el))">
                <span class="block" data-duplicate-tip-message>{{ $message }}</span>
                <span class="mt-1 block"><span class="inline-block" data-duplicate-tip-action>{{ __('duplicates.click_to_review') }}</span></span>
            </span>
        </template>
    </span>
@endif
