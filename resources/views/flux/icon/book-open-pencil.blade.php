@props([
    'variant' => 'outline',
])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <path d="M12 7v14" />
    <path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v7" />
    <path d="M3 18h6a3 3 0 0 1 3 3" />
    <path d="m15.25 18.75.45-2.15 3.95-3.95a1.4 1.4 0 0 1 2 2L17.7 18.6z" />
    <path d="m18.85 13.45 2 2" />
</x-sidebar-outline-icon>
