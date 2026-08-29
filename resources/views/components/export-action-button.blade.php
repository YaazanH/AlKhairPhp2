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
        data-export-action
    >
        <x-admin-action-icon name="export" />
    </a>
@else
    <button
        type="button"
        {{ $attributes->class('admin-icon-button') }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
        data-export-action
    >
        <x-admin-action-icon name="export" />
    </button>
@endif
