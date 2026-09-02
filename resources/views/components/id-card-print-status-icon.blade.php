@props(['state'])

@php
    $isPrinted = $state === 'printed';
@endphp

<svg
    {{ $attributes }}
    viewBox="0 0 512 512"
    fill="none"
    aria-hidden="true"
    data-id-card-print-status-icon="{{ $state }}"
    data-supplied-id-card-print-status="{{ $isPrinted ? 'mark-as-printed' : 'mark-as-not-printed' }}"
>
    @if ($isPrinted)
        <g fill="none" stroke="currentColor" stroke-width="18" stroke-linecap="round" stroke-linejoin="round">
            <path d="M158 52h196a14 14 0 0 1 14 14v92H144V66a14 14 0 0 1 14-14Z" />
            <path d="M137 378H93a46 46 0 0 1-46-46V204a46 46 0 0 1 46-46h326a46 46 0 0 1 46 46v128a46 46 0 0 1-46 46h-46" data-printer-outline-open-bottom />
            <circle cx="102" cy="220" r="13" />
            <path d="M137 274h236v135H137Z" fill="currentColor" fill-opacity="0.1" />
            <path d="M178 322h121M178 360h83" />
        </g>

        <circle cx="394" cy="365" r="88" fill="var(--app-panel)" stroke="#22a83a" stroke-width="18" />
        <path d="m350 365 28 29 58-63" fill="none" stroke="#22a83a" stroke-width="20" stroke-linecap="round" stroke-linejoin="round" />
    @else
        <g fill="none" stroke="currentColor" stroke-width="18" stroke-linecap="round" stroke-linejoin="round">
            <path d="M147 48h178l60 60v50H147Z" />
            <path d="M325 48v60h60" />
            <path d="M137 378H93a46 46 0 0 1-46-46V204a46 46 0 0 1 46-46h326a46 46 0 0 1 46 46v128a46 46 0 0 1-46 46h-46" data-printer-outline-open-bottom />
            <circle cx="410" cy="220" r="13" />
            <path d="M137 274h236v135H137Z" fill="currentColor" fill-opacity="0.1" />
            <path d="M178 322h121" />
            <path d="M178 360h83" />
        </g>

        <circle cx="394" cy="365" r="88" fill="var(--app-panel)" stroke="#f05252" stroke-width="18" />
        <path d="M355 326l78 78M433 326l-78 78" fill="none" stroke="#f05252" stroke-width="20" stroke-linecap="round" />
    @endif
</svg>
