@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-finance-icon="rising-bars-dashboard">
        <path d="M3 21h18" />
        <rect x="5" y="14.25" width="3.5" height="5.25" rx=".65" />
        <rect x="10.25" y="11.25" width="3.5" height="8.25" rx=".65" />
        <rect x="15.5" y="8.25" width="3.5" height="11.25" rx=".65" />
        <path d="m3.5 12.25 4.1.05 3.35-4.15h3.25l5.3-5.4" />
        <path d="M16.35 2.75h3.15V5.9" />
    </g>
</x-sidebar-outline-icon>
