@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-assessment-icon="assessment-sheet">
        <rect x="3" y="3" width="18" height="18.5" rx="2" />
        <path d="M8.25 3V1.75h7.5V3" />

        <path d="m6.25 7.35 1.1 1.15 1.8-2.25M11.25 7.5h5.75" />
        <path d="m6.35 11.25 2.4 2.4M8.75 11.25l-2.4 2.4M11.25 12.45h5.75" />
        <circle cx="7.55" cy="17.25" r="1.15" />
        <path d="M11.25 17.25h4.25" />
    </g>
</x-sidebar-outline-icon>
