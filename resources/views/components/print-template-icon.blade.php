@props(['name'])

<svg
    {{ $attributes->class(['print-template-symbol-icon']) }}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    aria-hidden="true"
>
    @switch($name)
        @case('save')
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3.75h11.25L19 6.5v13.75H5V3.75Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 3.75v5h7.5v-5M8 20.25v-7.5h8v7.5" />
            @break
        @case('settings')
            <path stroke-linecap="round" d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h10M18 18h2" />
            <circle cx="15" cy="6" r="2" />
            <circle cx="9" cy="12" r="2" />
            <circle cx="16" cy="18" r="2" />
            @break
        @case('database')
            <ellipse cx="12" cy="5.5" rx="7.5" ry="3" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 5.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6M4.5 11.5v6c0 1.66 3.36 3 7.5 3s7.5-1.34 7.5-3v-6" />
            @break
        @case('print')
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 9V4h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v6H7v-6Z" />
            <path stroke-linecap="round" d="M17.5 12h.01" />
            @break
        @case('copy')
            <rect x="8" y="8" width="11" height="11" rx="2" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" />
            @break
        @case('trash')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" />
            @break
        @case('text')
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5h14M12 5v14M8.5 19h7" />
            @break
        @case('dynamic-text')
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 4.5H6.5A1.5 1.5 0 0 0 5 6v4.5L3.5 12 5 13.5V18a1.5 1.5 0 0 0 1.5 1.5H8M16 4.5h1.5A1.5 1.5 0 0 1 19 6v4.5l1.5 1.5-1.5 1.5V18a1.5 1.5 0 0 1-1.5 1.5H16M9 8h6M12 8v8M10 16h4" />
            @break
        @case('calendar')
            <rect x="3.5" y="5.5" width="17" height="15" rx="2" />
            <path stroke-linecap="round" d="M8 3.5v4M16 3.5v4M3.5 10h17" />
            @break
        @case('hash')
            <path stroke-linecap="round" d="m9 4-2 16M17 4l-2 16M4 9h16M3 15h16" />
            @break
        @case('image')
            <rect x="3.5" y="4" width="17" height="16" rx="2" />
            <circle cx="8.5" cy="9" r="1.5" />
            <path stroke-linecap="round" stroke-linejoin="round" d="m5.5 18 4.5-4.5 3 3 2.5-2.5 3 4" />
            @break
        @case('image-plus')
            <rect x="3.5" y="4" width="17" height="16" rx="2" />
            <circle cx="8.5" cy="9" r="1.5" />
            <path stroke-linecap="round" stroke-linejoin="round" d="m5.5 18 4.5-4.5 3 3M16.5 8v5M14 10.5h5" />
            @break
        @case('barcode')
            <path stroke-linecap="round" d="M4 5v14M7 5v14M10.5 5v14M13 5v14M17 5v14M20 5v14" />
            @break
        @case('shape')
            <rect x="3.5" y="4" width="9" height="9" rx="1.5" />
            <circle cx="16" cy="16" r="4.5" />
            @break
        @case('chevron-up')
            <path stroke-linecap="round" stroke-linejoin="round" d="m6 15 6-6 6 6" />
            @break
        @case('chevron-down')
            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            @break
    @endswitch
</svg>
