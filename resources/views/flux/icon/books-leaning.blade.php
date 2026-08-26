@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-curricula-icon="three-stacked-books">
        <path d="M5 3.25h15l-1.15 1.2v3.1L20 8.75H5a2.75 2.75 0 0 1 0-5.5Z" />
        <path d="M5 4.6h13.85M5 7.4h13.85" />

        <path d="M3.75 8.75h15a2.75 2.75 0 0 1 0 5.5h-15l1.15-1.2v-3.1z" />
        <path d="M4.9 10.1h13.85M4.9 12.9h13.85" />

        <path d="M5 14.25h15l-1.15 1.2v3.1L20 19.75H5a2.75 2.75 0 0 1 0-5.5Z" />
        <path d="M5 15.6h13.85M5 18.4h13.85" />
    </g>
</x-sidebar-outline-icon>
