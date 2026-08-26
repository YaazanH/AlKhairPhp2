@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="father-foreground-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="7.25" cy="8.5" r="3.5" fill="black" stroke="none" />
            <path d="M.25 24c.65-6 3-9 7-9s6.35 3 7 9Z" fill="black" stroke="black" stroke-width="1.5" />
        </mask>
    </defs>
    <g data-parents-icon="father-mother">
        <g mask="url(#father-foreground-cutout)">
            <path d="M13.75 13V8.5a4.25 4.25 0 0 1 8.5 0V13M15.25 8.75a2.75 3.1 0 0 0 5.5 0 2.75 3.1 0 0 0-5.5 0Z" />
            <path d="M12.75 22v-3.25c0-2.75 1.9-4.4 5.25-4.4s5.25 1.65 5.25 4.4V22M13.75 12.5c.85 1.85 2.25 2.8 4.25 2.8s3.4-.95 4.25-2.8" />
        </g>
        <circle cx="7.25" cy="8.5" r="2.85" />
        <path d="M4.75 7.75c.45-1.2 1.3-1.8 2.55-1.8 1.2 0 2.03.55 2.47 1.65M1.25 22c.65-4.8 2.65-7.2 6-7.2s5.35 2.4 6 7.2M4.75 14.9c.35 2.05 1.18 3.08 2.5 3.08s2.15-1.03 2.5-3.08M5.65 16.75l1.6-1.5 1.6 1.5" />
    </g>
</x-sidebar-outline-icon>
