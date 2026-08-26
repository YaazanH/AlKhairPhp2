@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="quran-stand-book-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <path fill="black" stroke="none" d="M1.5 2h21v14.25h-21z" />
        </mask>
    </defs>
    <g data-memorization-icon="quran-on-stand">
        <g mask="url(#quran-stand-book-cutout)">
            <path d="m5.5 13.5 13 8-1.25-8M18.5 13.5l-13 8 1.25-8" />
        </g>
        <path d="M12 6.25c-2.1-2.4-4.7-2-8-3.5L2.25 12.5c4.1 2.4 7.35 1.7 9.75 4.5zM12 6.25c2.1-2.4 4.7-2 8-3.5l1.75 9.75c-4.1 2.4-7.35 1.7-9.75 4.5zM12 6.25V17" />
        <path d="M5 6c2.3.9 4.1.65 5.6 2M4.6 8.75c2.5 1 4.4.75 6 2.1M4.2 11.5c2.7 1.05 4.75.85 6.4 2.25M19 6c-2.3.9-4.1.65-5.6 2M19.4 8.75c-2.5 1-4.4.75-6 2.1M19.8 11.5c-2.7 1.05-4.75.85-6.4 2.25" />
    </g>
</x-sidebar-outline-icon>
