@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-points-icon="achievement-coin">
        <circle cx="12" cy="12" r="9.5" />
        <circle cx="12" cy="12" r="7" stroke-width="1.2" />
        <path d="m12 6.5 1.68 3.4 3.75.55-2.72 2.65.64 3.73L12 15.07l-3.35 1.76.64-3.73-2.72-2.65 3.75-.55z" />
    </g>
</x-sidebar-outline-icon>
