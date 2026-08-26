@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-points-icon="star-badge">
        <circle cx="12" cy="12" r="9.5" />
        <path d="m12 4.7 2.08 4.22 4.66.68-3.37 3.28.79 4.64L12 15.33l-4.16 2.19.79-4.64L5.26 9.6l4.66-.68L12 4.7Z" />
    </g>
</x-sidebar-outline-icon>
