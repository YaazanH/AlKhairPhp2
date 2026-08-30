@props([
    'label',
    'href' => null,
    'accent' => true,
])

@php
    $classes = [
        'admin-icon-button',
        'admin-icon-button--accent' => $accent,
    ];
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class($classes) }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
        data-add-action
    >
        <x-admin-action-icon name="add" />
    </a>
@else
    <button
        type="button"
        {{ $attributes->class($classes) }}
        title="{{ $label }}"
        aria-label="{{ $label }}"
        data-add-action
    >
        <x-admin-action-icon name="add" />
    </button>
@endif
