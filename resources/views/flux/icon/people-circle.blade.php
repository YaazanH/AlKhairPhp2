@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g
        data-groups-icon="five-people-circular-community"
        fill="currentColor"
        stroke="none"
    >
        @foreach ([0, 72, 144, 216, 288] as $rotation)
            <g transform="rotate({{ $rotation }} 12 12)">
                <circle cx="12" cy="2.85" r="1.45" />
                <path d="M7.25 7.35c.62-1.18 1.6-1.98 2.83-2.35.48.76 1.12 1.14 1.92 1.14s1.44-.38 1.92-1.14c1.23.37 2.21 1.17 2.83 2.35l-.82 3.05-2.1-.58.25-1.32A4.2 4.2 0 0 0 12 7.95a4.2 4.2 0 0 0-2.08.55l.25 1.32-2.1.58Z" />
            </g>
        @endforeach
    </g>
</x-sidebar-outline-icon>
