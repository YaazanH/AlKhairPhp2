@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="memorization-book-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <path fill="black" stroke="none" d="M0 0h24v16.2H0z" />
        </mask>
    </defs>
    <g data-memorization-icon="layered-quran-on-rehal">
        <g mask="url(#memorization-book-cutout)" stroke-width="2">
            <path d="m5.1 14.1 13.6 8M18.9 14.1l-13.6 8" />
            <path d="M6.1 19.65c1.05-.85 2-1.3 2.85-1.35M17.9 19.65c-1.05-.85-2-1.3-2.85-1.35" />
        </g>

        <path d="M12 7.2C9.55 4.65 6.65 4.45 3.25 3.15l-1 8.25c3.95 2.05 7.05 4.2 9.75 7zM12 7.2c2.45-2.55 5.35-2.75 8.75-4.05l1 8.25c-3.95 2.05-7.05 4.2-9.75 7zM12 7.2v11.2" />
        <path d="M4.25 5.1c-.45 1.15-.72 2.15-.8 3 3.45 1.65 6.3 3.75 8.55 6.3M19.75 5.1c.45 1.15.72 2.15.8 3-3.45 1.65-6.3 3.75-8.55 6.3" />
        <path d="M2.25 11.4 10.8 17.6M21.75 11.4l-8.55 6.2" />
    </g>
</x-sidebar-outline-icon>
