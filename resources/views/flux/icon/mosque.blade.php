@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <defs>
        <mask id="course-book-cutout" maskUnits="userSpaceOnUse">
            <path fill="white" stroke="none" d="M0 0h24v24H0z" />
            <path fill="black" stroke="none" d="M4 12h16v11H4z" />
        </mask>
    </defs>
    <g data-courses-icon="mosque-open-book">
        <g mask="url(#course-book-cutout)">
            <path d="M2.75 21.5V11.25A2.25 2.25 0 0 1 5 9h1c-.1-.45-.12-.9-.02-1.35C6.4 5.6 8.53 4.15 12 1.5c3.47 2.65 5.6 4.1 6.02 6.15.1.45.08.9-.02 1.35h1a2.25 2.25 0 0 1 2.25 2.25V21.5z" />
        </g>
        <path d="M12 15.25c-1.8-1.65-4-2.4-6.5-2.2l-.75 6.5c2.7-.1 5.1.65 7.25 2.2zM12 15.25c1.8-1.65 4-2.4 6.5-2.2l.75 6.5c-2.7-.1-5.1.65-7.25 2.2zM12 15.25v6.5" />
        <path d="M7.25 16.25c1.05.05 2 .3 2.85.75M16.75 16.25c-1.05.05-2 .3-2.85.75" />
    </g>
</x-sidebar-outline-icon>
