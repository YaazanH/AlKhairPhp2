@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-parents-icon="adult-holding-child-hand">
        <g fill="none" stroke="currentColor" stroke-linecap="round">
            <path d="M5.7 9.3 3.15 14M9.3 9.3l2.9 4.5" stroke-width="2.8" />
            <path d="M6.25 14.2 5.45 20.5M8.75 14.2l.8 6.3" stroke-width="3.1" />
            <path d="m15.2 13.3-3 0.5M17.2 13.35l2.35 2.35" stroke-width="2.15" />
            <path d="m15.45 16.7-.75 3.8M16.85 16.7l1 3.8" stroke-width="2.4" />
        </g>

        <g fill="currentColor" stroke="none">
            <circle cx="7.5" cy="5.25" r="2.15" />
            <path d="M5.45 8.1h4.1l1.05 7.1H4.4z" />
            <circle cx="16.15" cy="10.8" r="1.45" />
            <path d="M14.85 12.65h2.6l.75 4.55h-4.1z" />
        </g>
    </g>
</x-sidebar-outline-icon>
