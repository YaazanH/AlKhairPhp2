@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="certificate-seal-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="16.5" cy="15.25" r="4.85" fill="black" stroke="none" />
        </mask>
    </defs>
    <g data-certificate-icon="landscape-award">
        <g mask="url(#certificate-seal-cutout)">
            <rect width="19.5" height="14" x="2.25" y="3.25" rx="2.25" />
            <path d="M6 6.5h11.5M5.25 9.75h7M5.25 12.5h5.5M5.25 15.25H9" />
        </g>
        <circle cx="16.5" cy="15.25" r="4.1" />
        <circle cx="16.5" cy="15.25" r="2.25" />
        <path d="m14.55 18.7-1.8 3.4 2.65-.8 1.1 2 1.1-2 2.65.8-1.8-3.4" />
    </g>
</x-sidebar-outline-icon>
