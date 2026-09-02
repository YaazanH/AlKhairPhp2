@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-expense-icon="receipt-outflow" fill="none">
        <path d="M6.75 3.75h10.5a1.5 1.5 0 0 1 1.5 1.5v15l-2.25-1.5-2.25 1.5L12 18.75l-2.25 1.5-2.25-1.5-2.25 1.5v-15a1.5 1.5 0 0 1 1.5-1.5Z" />
        <path d="M8.75 7.25h6.5M8.75 10.25h4.5" />
        <path d="M12 12.75v4M10.25 15l1.75 1.75L13.75 15" />
    </g>
</x-sidebar-outline-icon>
