@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="clipboard-student-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <g
                fill="black"
                stroke="black"
                stroke-width="2.75"
                transform="translate(-1.79 7.8) scale(.897)"
            >
                <path d="m2 8.75 10-4 10 4-10 4z" />
                <path d="M6 10.65v4.2c3.55 3.15 8.45 3.15 12 0v-4.2" />
                <path d="M2.5 9v6.25" />
                <circle cx="2.5" cy="16.5" r=".85" />
            </g>
        </mask>
    </defs>
    <g mask="url(#clipboard-student-cutout)" transform="translate(-1.2 -1.2) scale(1.1)">
        <path d="M10 4.25H8.75a2.5 2.5 0 0 0-2.5 2.5v12a2.5 2.5 0 0 0 2.5 2.5H19a2.5 2.5 0 0 0 2.5-2.5v-12a2.5 2.5 0 0 0-2.5-2.5h-2" />
        <path d="M11 2.25h5a1 1 0 0 1 1 1v2h-7v-2a1 1 0 0 1 1-1ZM13.5 8.5H18M13.5 11.5H18M15 14.5h3M15.75 17.5H18" />
    </g>
    <g data-attendance-badge="graduation-cap" transform="translate(-1.79 7.8) scale(.897)">
        <path d="m2 8.75 10-4 10 4-10 4z" />
        <path d="M6 10.65v4.2c3.55 3.15 8.45 3.15 12 0v-4.2" />
        <path d="M2.5 9v6.25" />
        <circle cx="2.5" cy="16.5" r=".85" />
    </g>
</x-sidebar-outline-icon>
