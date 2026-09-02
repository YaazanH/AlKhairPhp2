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
        data-download-action
    >
        <x-admin-action-icon name="download" />
    </a>
@else
    <button
        type="button"
        {{ $attributes->class('admin-icon-button') }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
        data-download-action
    >
        <x-admin-action-icon name="download" />
    </button>
@endif
