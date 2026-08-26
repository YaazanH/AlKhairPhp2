@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="clipboard-student-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <path d="m.75 16.25 6.75-3.4 6.75 3.4-6.75 3.4zM2.25 18.5v3c3 1.9 7.5 1.9 10.5 0v-3" fill="black" stroke="black" stroke-width="2.5" />
        </mask>
    </defs>
    <g mask="url(#clipboard-student-cutout)" transform="translate(-1.2 -1.2) scale(1.1)">
        <path d="M10 4.25H8.75a2.5 2.5 0 0 0-2.5 2.5v12a2.5 2.5 0 0 0 2.5 2.5H19a2.5 2.5 0 0 0 2.5-2.5v-12a2.5 2.5 0 0 0-2.5-2.5h-2" />
        <path d="M11 2.25h5a1 1 0 0 1 1 1v2h-7v-2a1 1 0 0 1 1-1ZM13.5 8.5H18M13.5 11.5H18M15 14.5h3M15.75 17.5H18" />
    </g>
    <g data-attendance-badge="graduation-cap" transform="translate(-1.2 -1.2) scale(1.1)">
        <path d="m1.25 16.25 6.25-3.1 6.25 3.1-6.25 3.1zM3 18.5v2.6c2.55 1.65 6.45 1.65 9 0v-2.6M13.75 16.25v4.5" />
    </g>
</x-sidebar-outline-icon>
