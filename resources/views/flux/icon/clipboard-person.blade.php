@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="clipboard-person-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="7.25" cy="14.5" r="3.5" fill="black" stroke="none" />
            <path d="M.75 24c.8-4.6 3-6.9 6.5-6.9s5.7 2.3 6.5 6.9Z" fill="black" stroke="black" stroke-width="1.5" />
        </mask>
    </defs>
    <g mask="url(#clipboard-person-cutout)" transform="translate(-1.2 -1.2) scale(1.1)">
        <path d="M10 4.25H8.75a2.5 2.5 0 0 0-2.5 2.5v12a2.5 2.5 0 0 0 2.5 2.5H19a2.5 2.5 0 0 0 2.5-2.5v-12a2.5 2.5 0 0 0-2.5-2.5h-2" />
        <path d="M11 2.25h5a1 1 0 0 1 1 1v2h-7v-2a1 1 0 0 1 1-1ZM13.5 8.5H18M13.5 11.5H18M15 14.5h3M15.75 17.5H18" />
    </g>
    <g data-attendance-badge="person" transform="translate(-1.2 -1.2) scale(1.1)">
        <circle cx="7.25" cy="14.5" r="2.65" />
        <path d="M1.5 22.75c.7-3.58 2.62-5.37 5.75-5.37s5.05 1.79 5.75 5.37" />
    </g>
</x-sidebar-outline-icon>
