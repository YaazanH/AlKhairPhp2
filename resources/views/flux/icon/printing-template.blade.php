@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="printing-template-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <rect width="13" height="10.75" x="5.5" y="11.25" fill="black" stroke="none" rx="2.25" />
        </mask>
    </defs>
    <g data-printing-template-icon="printer-layout-page">
        <g mask="url(#printing-template-cutout)">
            <path d="M7 7V2.75h10V7M5 7h14a3 3 0 0 1 3 3v4.25A2.75 2.75 0 0 1 19.25 17H4.75A2.75 2.75 0 0 1 2 14.25V10a3 3 0 0 1 3-3Z" />
            <path d="M18.75 9.5h.01" />
        </g>
        <rect width="12" height="9.75" x="6" y="11.75" rx="1.75" />
        <path d="M8.75 14.5h6.5M8.75 17.25h6.5M8.75 20h6.5" />
    </g>
</x-sidebar-outline-icon>
