@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-curricula-icon="three-standing-books-one-leaning">
        <rect x="2.25" y="6" width="4.75" height="15.5" rx=".65" />
        <path d="M3.65 9h1.95M3.65 18.25h1.95" />

        <rect x="7.75" y="3" width="5.15" height="18.5" rx=".65" />
        <path d="M9.15 6h2.35M9.15 8.3h2.35M9.15 18.25h2.35" />

        <g transform="rotate(-13 17.25 12.6)">
            <rect x="14.7" y="4" width="5.1" height="17.2" rx=".65" />
            <path d="M16.05 7.15h2.4M16.05 9.45h2.4M16.05 17.95h2.4" />
        </g>
    </g>
</x-sidebar-outline-icon>
