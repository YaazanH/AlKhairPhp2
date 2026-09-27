@props(['course'])

<div x-data="{
    open: false, range: false, top: 0, left: 0,
    format(date) { return date ? date.split('-').reverse().join('-') : ''; },
    toggle() {
        this.open = !this.open;
        if (!this.open) return;
        this.range = Boolean(this.$wire.calendarEndDate);
        const rect = this.$refs.trigger.getBoundingClientRect();
        const width = Math.min(380, window.innerWidth - 24);
        this.left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12));
        this.top = rect.bottom + 235 < window.innerHeight ? rect.bottom + 8 : Math.max(12, rect.top - 235);
    },
    mode(range) {
        this.range = range;
        this.$wire.set('calendarEndDate', range ? (this.$wire.calendarEndDate || this.$wire.calendarDate) : '', false);
    }
}" x-on:resize.window="open = false" x-on:keydown.escape.window="if (open) { $event.stopImmediatePropagation(); open = false; $refs.trigger.focus(); }" data-course-calendar-date-picker>
    <button x-ref="trigger" type="button" x-on:click="toggle()" class="calendar-date-trigger" :aria-expanded="open" aria-haspopup="dialog" aria-label="{{ __('course_calendar.manager.actions.choose_dates') }}" data-modal-action-icon-ignore>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18"/></svg>
        <span x-show="!$wire.calendarDate">{{ __('course_calendar.manager.actions.choose_dates') }}</span>
        <span x-show="$wire.calendarDate" class="calendar-date-trigger__value" dir="ltr" x-text="format($wire.calendarDate) + ($wire.calendarEndDate && $wire.calendarEndDate !== $wire.calendarDate ? ' – ' + format($wire.calendarEndDate) : '')"></span>
    </button>
    <template x-teleport="body">
        <div x-show="open" x-cloak x-on:click.outside="if (!$refs.trigger.contains($event.target)) open = false" class="calendar-color-popover calendar-date-popover" :style="{ top: top + 'px', left: left + 'px' }" role="dialog" aria-label="{{ __('course_calendar.manager.actions.choose_dates') }}">
            <div class="calendar-date-modes">
                <button type="button" :aria-pressed="!range" x-on:click="mode(false)" data-modal-action-icon-ignore>{{ __('course_calendar.manager.single_day') }}</button>
                <button type="button" :aria-pressed="range" x-on:click="mode(true)" data-modal-action-icon-ignore>{{ __('course_calendar.manager.date_range') }}</button>
            </div>
            <div class="calendar-date-range" :class="{ 'calendar-date-range--two': range }">
                <label>
                    <span x-text="range ? @js(__('course_calendar.manager.fields.start_date')) : @js(__('course_calendar.manager.fields.date'))"></span>
                    <input wire:model="calendarDate" type="date" :min="$wire.starts_on" :max="$wire.ends_on" aria-label="{{ __('course_calendar.manager.fields.start_date') }}">
                </label>
                <label x-show="range" x-cloak>
                    <span>{{ __('course_calendar.manager.fields.end_date') }}</span>
                    <input wire:model="calendarEndDate" type="date" :min="$wire.calendarDate || $wire.starts_on" :max="$wire.ends_on" aria-label="{{ __('course_calendar.manager.fields.end_date') }}">
                </label>
            </div>
            <button type="button" class="admin-icon-button admin-icon-button--accent calendar-date-done" x-on:click="open = false; $refs.trigger.focus({ preventScroll: true })" title="{{ __('course_calendar.manager.actions.done') }}" aria-label="{{ __('course_calendar.manager.actions.done') }}" data-modal-action-icon-ignore>
                <x-admin-action-icon name="finalise" />
            </button>
        </div>
    </template>
</div>
@error('calendarDate')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror
@error('calendarEndDate')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror
