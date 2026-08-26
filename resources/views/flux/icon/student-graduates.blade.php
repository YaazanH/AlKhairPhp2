@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g data-students-icon="graduation-cap">
        <path d="m2 8.75 10-4 10 4-10 4z" />
        <path d="M6 10.65v4.2c3.55 3.15 8.45 3.15 12 0v-4.2" />
        <path d="M2.5 9v6.25" />
        <circle cx="2.5" cy="16.5" r=".85" />
    </g>
</x-sidebar-outline-icon>
