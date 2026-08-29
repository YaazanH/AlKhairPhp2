@props(['label'])

<button
    type="button"
    {{ $attributes->class([
        'admin-icon-button',
        'clear-filter-button',
    ]) }}
    title="{{ $label }}"
    aria-label="{{ $label }}"
    data-clear-filter-action
>
    <x-admin-action-icon name="clear-filter" />
</button>
