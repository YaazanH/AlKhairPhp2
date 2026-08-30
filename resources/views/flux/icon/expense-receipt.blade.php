@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-expense-icon="traced-rolled-receipt">
        <path d="M5.5 2.75h11A4.5 4.5 0 0 1 21 7.25V21l-2-1.7-2 1.7-2-1.7-2 1.7-2-1.7L9 21l-1.75-1.5V5.75a3 3 0 0 0-3-3Z" />
        <path d="M7.25 7.25H2.5v-1.5a3 3 0 0 1 3-3" />
        <path d="M10 6.5h4M10 8.4h4" />
        <path d="M16.25 6.5h2M16.25 8.4h2M16.25 10.3h2" />
        <path d="M10 13.25h8.25M10 15.5h8.25" />
    </g>
</x-sidebar-outline-icon>
