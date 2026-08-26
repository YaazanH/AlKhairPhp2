@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-parents-icon="adult-holding-child-hand">
        <circle cx="7.5" cy="5.25" r="2.15" />
        <path d="M5.55 9.1c.35-.65 1-.95 1.95-.95s1.6.3 1.95.95l1.05 5.55H4.5z" />
        <path d="M5.55 9.35 3.1 14M9.45 9.35l2.75 4.45M6.25 14.65l-.8 5.85M8.75 14.65l.8 5.85" />

        <circle cx="16.15" cy="10.8" r="1.45" />
        <path d="M14.85 13.25c.25-.45.7-.65 1.3-.65s1.05.2 1.3.65l.7 3.9h-4z" />
        <path d="m14.85 13.35-2.65.45M17.45 13.35l2.1 2.35M15.45 17.15l-.75 3.35M16.85 17.15l1 3.35" />
    </g>
</x-sidebar-outline-icon>
