@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-finance-icon="analytics-monitor">
        <rect width="20" height="15.25" x="2" y="2.75" rx="2.25" />
        <path d="M2 15.5h20M10 18l-1.5 3.25h7L14 18M7.5 21.25h9" />
        <circle cx="7.5" cy="9.25" r="3.75" />
        <path d="M7.5 5.5v3.75l3.25 1.9M7.5 9.25l-2.65 2.65M13.5 5.75h5.75M13.5 8.25h5.75" />
        <path d="M12.75 14.25h7.5M13.5 14.25v-2.75h1.75v2.75M16.25 14.25v-4h1.75v4M19 14.25V9h1.25v5.25" />
    </g>
</x-sidebar-outline-icon>
