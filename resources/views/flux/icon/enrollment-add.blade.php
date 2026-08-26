@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="enrollment-add-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="17.25" cy="17" r="5.6" fill="black" stroke="none" />
        </mask>
    </defs>
    <g data-enrollment-icon="profile-card-add">
        <g mask="url(#enrollment-add-cutout)">
            <rect width="17" height="17.5" x="2" y="3" rx="2.25" />
            <circle cx="7" cy="8.25" r="2.15" />
            <path d="M4.25 14c.45-2.15 1.37-3.23 2.75-3.23S9.3 11.85 9.75 14M11.75 6.75h4.5M11.75 10h4.5" />
        </g>
        <circle cx="17.25" cy="17" r="4.85" />
        <path d="M17.25 14.25v5.5M14.5 17h5.5" />
    </g>
</x-sidebar-outline-icon>
