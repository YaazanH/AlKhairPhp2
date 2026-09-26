@props(['colors'])

<div x-data="{
    open: false, top: 0, left: 0,
    toggle() {
        this.open = !this.open;
        if (!this.open) return;
        const rect = this.$refs.trigger.getBoundingClientRect();
        const width = Math.min(272, window.innerWidth - 24);
        this.left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12));
        this.top = rect.bottom + 268 < window.innerHeight ? rect.bottom + 8 : Math.max(12, rect.top - 268);
        this.$nextTick(() => (this.$refs.palette.querySelector('input:checked') || this.$refs.palette.querySelector('input:not(:disabled)'))?.focus());
    }
}" x-on:resize.window="open = false" x-on:keydown.escape.window="if (open) { $event.stopImmediatePropagation(); open = false; $refs.trigger.focus(); }" data-course-calendar-color-picker>
    <button x-ref="trigger" type="button" x-on:click="toggle()" class="calendar-color-trigger" :aria-expanded="open" aria-haspopup="dialog" aria-label="{{ __('course_calendar.manager.actions.choose_color') }}" title="{{ __('course_calendar.manager.actions.choose_color') }}" data-modal-action-icon-ignore>
        <span class="calendar-color-trigger__swatch" :style="{ backgroundColor: $wire.calendarColor }" aria-hidden="true"></span>
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 8 4 4 4-4" /></svg>
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak x-ref="palette" x-on:click.outside="if (!$refs.trigger.contains($event.target)) open = false" class="calendar-color-popover" :style="{ top: top + 'px', left: left + 'px' }" role="dialog" aria-label="{{ __('course_calendar.manager.actions.choose_color') }}">
            <div class="calendar-color-popover__title">{{ __('course_calendar.manager.actions.choose_color') }}</div>
            <div class="calendar-color-grid" role="radiogroup" aria-label="{{ __('course_calendar.manager.fields.color') }}" data-course-calendar-color-options>
                @foreach ($colors as $color)
                    <label class="calendar-color-option" title="{{ __('course_calendar.manager.color_option', ['number' => $loop->iteration]) }}">
                        <input :disabled="$wire.calendarRows.some((row, index) => index !== $wire.editingCalendarRow &amp;&amp; row.color.toLowerCase() === '{{ $color }}')" type="radio" name="course-calendar-color" wire:model="calendarColor" value="{{ $color }}" :checked="$wire.calendarColor === '{{ $color }}'" x-on:change="$wire.set('calendarColor', $event.target.value, false)" x-on:click="open = false; $refs.trigger.focus()" x-on:keydown.enter.prevent="open = false; $refs.trigger.focus()" aria-label="{{ __('course_calendar.manager.color_option', ['number' => $loop->iteration]) }}">
                        <span style="background-color: {{ $color }}" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 10 3 3 7-7" /></svg></span>
                    </label>
                @endforeach
            </div>
        </div>
    </template>
</div>
