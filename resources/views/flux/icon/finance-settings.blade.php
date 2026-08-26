@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="finance-settings-coin-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <circle cx="16.75" cy="7.25" r="5.25" fill="black" stroke="none" />
        </mask>
    </defs>
    <g data-finance-settings-icon="gear-currency-coin">
        <g mask="url(#finance-settings-coin-cutout)">
            <path d="M8 7.5h2.5l.4 1.8c.6.2 1.15.52 1.65.95l1.75-.55 1.25 2.15-1.35 1.25c.07.38.1.77.1 1.15s-.03.77-.1 1.15l1.35 1.25-1.25 2.15-1.75-.55c-.5.43-1.05.75-1.65.95l-.4 1.8H8l-.4-1.8c-.6-.2-1.15-.52-1.65-.95l-1.75.55-1.25-2.15L4.3 15.4a6.6 6.6 0 0 1 0-2.3l-1.35-1.25L4.2 9.7l1.75.55c.5-.43 1.05-.75 1.65-.95z" />
            <circle cx="9.25" cy="14.25" r="2.65" />
        </g>
        <circle cx="16.75" cy="7.25" r="4.75" />
        <path d="M18.15 4.75H16.4a1.25 1.25 0 0 0 0 2.5h.7a1.25 1.25 0 0 1 0 2.5h-1.75M16.75 3.75v7" />
    </g>
</x-sidebar-outline-icon>
