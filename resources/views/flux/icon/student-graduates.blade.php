@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="foreground-student-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <path d="m2 9 5.75-2.85L13.5 9l-5.75 2.85z" fill="black" stroke="black" stroke-width="2" />
            <circle cx="7.75" cy="12.25" r="4.25" fill="black" stroke="none" />
            <path d="M0 24c.65-5.65 3.23-8.48 7.75-8.48S14.85 18.35 15.5 24Z" fill="black" stroke="black" stroke-width="1.5" />
        </mask>
    </defs>
    <g data-students-icon="two-graduates">
        <g mask="url(#foreground-student-cutout)">
            <path d="m13.25 6.75 3.6-1.8 3.6 1.8-3.6 1.8zM14.4 7.55v2.3a2.45 2.45 0 0 0 4.9 0v-2.3M20.45 6.75v2.75" />
            <path d="M11.9 18c.45-3.15 2.1-4.72 4.95-4.72S21.35 14.85 21.8 18" />
        </g>
        <path d="m2.5 9 5.25-2.6L13 9l-5.25 2.6zM4.5 10.25v2.35a3.25 3.25 0 0 0 6.5 0v-2.35M13 9v3.25" />
        <path d="M.75 22.5c.65-4.65 3-6.98 7-6.98s6.35 2.33 7 6.98" />
    </g>
</x-sidebar-outline-icon>
