@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="assessment-review-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="16.25" cy="16.5" r="5" fill="black" stroke="none" />
            <path d="m19.75 20 3 3" fill="none" stroke="black" stroke-linecap="round" stroke-width="3.5" />
        </mask>
    </defs>
    <g data-assessment-icon="checklist-review">
        <g mask="url(#assessment-review-cutout)">
            <rect width="16.5" height="18.5" x="2" y="2.25" rx="2.25" />
            <path d="M18.5 5h1.25A2.25 2.25 0 0 1 22 7.25v7M5.25 7.25l1.15 1.2L8.5 5.75M5.5 11.25l2.5 2.5M8 11.25l-2.5 2.5M6.75 17h.01M10.25 7.25h5M10.25 12.5h5M10.25 17h3.25" />
        </g>
        <circle cx="16.25" cy="16.5" r="4.25" />
        <path d="m14.35 16.5 1.25 1.3 2.6-3M19.25 19.5 22.5 22.75" />
    </g>
</x-sidebar-outline-icon>
