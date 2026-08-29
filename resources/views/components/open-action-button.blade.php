@props([
    'label',
    'href' => null,
])

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class('admin-icon-button') }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
        data-open-action
    >
        <x-admin-action-icon name="open" />
    </a>
@else
    <button
        type="button"
        {{ $attributes->class('admin-icon-button') }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
        data-open-action
    >
        <x-admin-action-icon name="open" />
    </button>
@endif
