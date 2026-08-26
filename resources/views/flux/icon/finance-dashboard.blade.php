@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-finance-icon="growth-dashboard">
        <path d="M2.75 21v-4.5h3V21M8.1 21v-7h3v7M13.45 21v-9.5h3V21M18.8 21V8.5h3V21M2 21h20" />
        <path d="m3.5 14 5.5-4.5 4 3.25L21 4.75M17 4.75h4v4" />
        <circle cx="7" cy="5.8" r="3.4" />
        <path d="M8.15 4.25h-1.6a.95.95 0 0 0 0 1.9h.9a.95.95 0 1 1 0 1.9h-1.6M7 3.35v4.9" />
    </g>
</x-sidebar-outline-icon>
