@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="expense-approval-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="17.25" cy="16.75" r="5.15" fill="black" stroke="none" />
        </mask>
    </defs>
    <g data-expense-icon="receipt-approved">
        <g mask="url(#expense-approval-cutout)">
            <path d="M5 2.75h11.5v15.8l-2.15-1.65-2.15 1.65-2.15-1.65-2.15 1.65L5 16.9V2.75Z" />
            <path d="M7.5 6h5.75M7.5 9h5.75M7.5 12h5.75M7.5 15h3.5" />
        </g>
        <circle cx="17.25" cy="16.75" r="4.4" />
        <path d="m15.15 16.7 1.45 1.45 2.8-3" />
    </g>
</x-sidebar-outline-icon>
