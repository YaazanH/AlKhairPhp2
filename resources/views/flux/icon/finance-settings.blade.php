@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-finance-settings-icon="currency-gear">
        <path d="M9.4 2.5h5.2l.45 2.05c.6.22 1.15.54 1.65.95l2-.62 2.6 4.5-1.55 1.42a8 8 0 0 1 0 2.4l1.55 1.42-2.6 4.5-2-.62c-.5.41-1.05.73-1.65.95l-.45 2.05H9.4l-.45-2.05c-.6-.22-1.15-.54-1.65-.95l-2 .62-2.6-4.5 1.55-1.42a8 8 0 0 1 0-2.4L2.7 9.38l2.6-4.5 2 .62c.5-.41 1.05-.73 1.65-.95L9.4 2.5Z" />
        <circle cx="12" cy="12" r="5.25" />
        <path d="M13.5 9.3h-1.9a1.25 1.25 0 0 0 0 2.5h.8a1.25 1.25 0 1 1 0 2.5h-1.9M12 8.1v7.8" />
    </g>
</x-sidebar-outline-icon>
