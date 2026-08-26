@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g
        data-certificate-icon="awqaf-landscape-certificate"
        data-certificate-style="centered-medal"
    >
        <path d="M9 19.25H3.75A1.75 1.75 0 0 1 2 17.5V6A1.75 1.75 0 0 1 3.75 4.25h16.5A1.75 1.75 0 0 1 22 6v11.5a1.75 1.75 0 0 1-1.75 1.75H15" />
        <path d="M7 7.75h10M5.5 10.75h13" />
        <path d="m10.5 19.2-.8 3.55 2.3-1.45 2.3 1.45-.8-3.55" />
        <circle cx="12" cy="17" r="2.75" />
        <circle cx="12" cy="17" r="1.15" />
    </g>
</x-sidebar-outline-icon>
