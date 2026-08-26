@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-expense-icon="receipt-with-currency">
        <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h10A3.5 3.5 0 0 1 20 6.5v14.75l-2-1.7-2 1.7-2-1.7-2 1.7-2-1.7-2 1.7-2-1.7-2 1.7V5.5Z" />
        <path d="M4 5.75h3.25V5.5A2.5 2.5 0 0 0 4.75 3H4" />

        <path d="M7.25 7.25h.01M10.1 7.25h.01M12.95 7.25h.01M15.8 7.25h.01" />
        <path d="M7.25 10.25h9.5M7.25 13.25h4.25M7.25 16.25h4.25" />
        <path d="M17.25 12h-1.3a1.25 1.25 0 0 0 0 2.5h.55a1.25 1.25 0 0 1 0 2.5h-1.45M16.2 10.75v7.5" />
    </g>
</x-sidebar-outline-icon>
