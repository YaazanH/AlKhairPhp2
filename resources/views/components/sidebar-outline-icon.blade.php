@props([
    'variant' => 'outline',
])

@php
    if ($variant !== 'outline') {
        throw new \Exception('Sidebar composite icons support the outline variant only.');
    }

    $classes = Flux::classes('shrink-0')->add('[:where(&)]:size-6');
@endphp

<svg
    {{ $attributes->class($classes) }}
    data-flux-icon
    xmlns="http://www.w3.org/2000/svg"
    fill="none"
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    data-slot="icon"
>
    {{ $slot }}
</svg>
