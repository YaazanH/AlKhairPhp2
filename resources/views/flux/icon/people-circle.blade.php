@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-groups-icon="people-in-circle">
        <g>
            <circle cx="12" cy="3.25" r="1.75" />
            <path d="M8.75 8.25c.3-2 1.38-3 3.25-3s2.95 1 3.25 3c-1.85 1-4.65 1-6.5 0Z" />
        </g>
        <g transform="rotate(90 12 12)">
            <circle cx="12" cy="3.25" r="1.75" />
            <path d="M8.75 8.25c.3-2 1.38-3 3.25-3s2.95 1 3.25 3c-1.85 1-4.65 1-6.5 0Z" />
        </g>
        <g transform="rotate(180 12 12)">
            <circle cx="12" cy="3.25" r="1.75" />
            <path d="M8.75 8.25c.3-2 1.38-3 3.25-3s2.95 1 3.25 3c-1.85 1-4.65 1-6.5 0Z" />
        </g>
        <g transform="rotate(270 12 12)">
            <circle cx="12" cy="3.25" r="1.75" />
            <path d="M8.75 8.25c.3-2 1.38-3 3.25-3s2.95 1 3.25 3c-1.85 1-4.65 1-6.5 0Z" />
        </g>
        <path d="M7.25 4.9A9.25 9.25 0 0 0 4.9 7.25M16.75 4.9a9.25 9.25 0 0 1 2.35 2.35M4.9 16.75a9.25 9.25 0 0 0 2.35 2.35M19.1 16.75a9.25 9.25 0 0 1-2.35 2.35" />
    </g>
</x-sidebar-outline-icon>
