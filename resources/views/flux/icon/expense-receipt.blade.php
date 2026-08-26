@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-expense-icon="receipt-with-dollar">
        <path d="M5.25 3h11.5A4.25 4.25 0 0 1 21 7.25V21l-1.75-1.55L17.5 21l-1.75-1.55L14 21l-1.75-1.55L10.5 21l-1.75-1.55L7 21l-1.75-1.55L3.5 21V6.25A3.25 3.25 0 0 1 6.75 3" />
        <path fill="currentColor" stroke="none" d="M3.5 3h2.25A2.25 2.25 0 0 1 8 5.25v2H1.25v-1A3.25 3.25 0 0 1 3.5 3Z" />

        <circle cx="7" cy="8.25" r=".72" fill="currentColor" stroke="none" />
        <circle cx="9.5" cy="8.25" r=".72" fill="currentColor" stroke="none" />
        <circle cx="12" cy="8.25" r=".72" fill="currentColor" stroke="none" />
        <circle cx="14.5" cy="8.25" r=".72" fill="currentColor" stroke="none" />
        <circle cx="17" cy="8.25" r=".72" fill="currentColor" stroke="none" />

        <path d="M7 11.25h10M7 14.25h4.25M7 17.25h4.25" stroke-width="1.8" />
        <path d="M17.45 13.55c-.45-.35-.98-.55-1.55-.55-.95 0-1.72.55-1.72 1.25 0 .72.62 1.08 1.72 1.35 1.1.28 1.72.65 1.72 1.38 0 .7-.77 1.27-1.72 1.27-.7 0-1.35-.28-1.82-.73M15.9 12.05v7.15" />
    </g>
</x-sidebar-outline-icon>
