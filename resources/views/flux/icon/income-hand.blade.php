@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-income-icon="hand-receiving-coin">
        <circle cx="15.75" cy="7.75" r="5.25" />
        <path d="M17.25 5.25H15.4a1.3 1.3 0 0 0 0 2.6h.7a1.3 1.3 0 0 1 0 2.6h-1.85M15.75 4.25v7" stroke-width="1.2" />
        <rect width="3.5" height="6.5" x="2" y="15.25" rx=".5" />
        <path d="M5.5 16.25h3.25l3.1 1.75h3.35a1.4 1.4 0 0 1 0 2.8h-4.75M5.5 21.75h6.1a5.5 5.5 0 0 0 2.85-.8l6.55-4a1.5 1.5 0 0 0-1.55-2.56l-4.55 2.72" />
    </g>
</x-sidebar-outline-icon>
