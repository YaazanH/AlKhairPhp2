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
        data-edit-action
    >
        <x-admin-action-icon name="edit" />
    </a>
@else
    <button
        type="button"
        {{ $attributes->class('admin-icon-button') }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
        data-edit-action
    >
        <x-admin-action-icon name="edit" />
    </button>
@endif
