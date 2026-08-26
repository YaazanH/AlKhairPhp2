@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="expense-coin-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="17.25" cy="17.25" r="5.5" fill="black" stroke="none" />
        </mask>
    </defs>
    <g data-expense-icon="receipt-arrow-coin">
        <g mask="url(#expense-coin-cutout)">
            <path d="M6 2.5h8.5a2 2 0 0 1 2 2V20l-2.1-1.5-2.1 1.5-2.1-1.5L8.1 20 6 18.5 4 20V4.5a2 2 0 0 1 2-2Z" />
            <path d="M6.75 6.25h6.5M6.75 9.25h5M6.75 12.25h6.5M6.75 15.25h3.75M15.75 8.5 19 5.25l3.25 3.25M19 5.25v6" />
        </g>
        <circle cx="17.25" cy="17.25" r="4.75" stroke-width="1.1" />
        <circle cx="17.25" cy="17.25" r="3.45" stroke-width="1.1" />
        <path d="M18.65 14.75H16.9a1.25 1.25 0 0 0 0 2.5h.7a1.25 1.25 0 0 1 0 2.5h-1.75M17.25 13.75v7" stroke-width="1.1" />
    </g>
</x-sidebar-outline-icon>
