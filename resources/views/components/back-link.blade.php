@props([
    'href',
    'navigate' => false,
])

<a
    href="{{ $href }}"
    draggable="false"
    @if ($navigate) wire:navigate @endif
    {{ $attributes->class(['app-back-link inline-flex items-center gap-1 text-sm text-neutral-300 transition-colors hover:text-white']) }}
>
    <span aria-hidden="true">{{ app()->isLocale('ar') ? '→' : '←' }}</span>
    <span>{{ __('crud.common.actions.back') }}</span>
</a>
