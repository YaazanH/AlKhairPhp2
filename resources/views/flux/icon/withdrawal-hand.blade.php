@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="withdrawal-hand-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <path d="m12.75 13.25 5.5 5.5h3.5v4.25h-10.5v-4.5l-.5-2.5Z" fill="black" stroke="black" stroke-linejoin="round" stroke-width="2.5" />
        </mask>
    </defs>
    <g data-withdrawal-icon="hand-pulling-banknote">
        <g mask="url(#withdrawal-hand-cutout)">
            <rect width="19" height="4.5" x="2.5" y="2.5" rx="1.75" />
            <path d="M5.5 5h13M6 7v8.25a2 2 0 0 0 2 2h7.5a2 2 0 0 0 2-2V7" />
            <circle cx="11.75" cy="11.25" r="1.8" />
            <path d="M7.75 9c1 0 1.5-.5 1.5-1.5M16 9c-1 0-1.5-.5-1.5-1.5M7.75 15.25c1 0 1.5.5 1.5 1.5" />
        </g>
        <path d="M13 20c-1.45-1.55-1.8-3.55-.95-5.3l.45-.95M20 20l1-3.45a4 4 0 0 0-1.05-3.95l-2.8-2.8M18.1 17.15l-3.9-3.9a1.35 1.35 0 0 0-1.9 1.9l2.8 2.8" />
        <rect width="9.25" height="3" x="12" y="19.5" rx="1" />
        <path d="M18.75 21h.75" />
    </g>
</x-sidebar-outline-icon>
