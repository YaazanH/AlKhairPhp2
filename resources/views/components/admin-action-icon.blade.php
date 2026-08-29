@props(['name'])

@php
    $isSuppliedFilledIcon = in_array($name, ['save', 'save-new', 'unlink'], true);
    $viewBox = match ($name) {
        'save' => '300 280 720 720',
        'save-new' => '250 230 760 760',
        'unlink' => '0 0 256 256',
        default => '0 0 24 24',
    };
@endphp

<svg
    {{ $attributes }}
    viewBox="{{ $viewBox }}"
    fill="{{ $isSuppliedFilledIcon ? 'currentColor' : 'none' }}"
    stroke="{{ $isSuppliedFilledIcon ? 'none' : 'currentColor' }}"
    stroke-width="1.8"
    aria-hidden="true"
    data-icon-name="{{ $name }}"
>
    @switch($name)
        @case('account')
            <circle cx="12" cy="8" r="3.75" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20c.65-4 3.15-6.25 7.5-6.25S19.85 16 20.5 20" />
            @break
        @case('children')
            <circle cx="8.5" cy="8" r="3" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.25 20c.45-3.65 2.2-5.6 5.25-5.6s4.8 1.95 5.25 5.6M15 5.75a2.75 2.75 0 0 1 0 5.5m.25 3.15c3.05 0 4.8 1.95 5.25 5.6" />
            @break
        @case('camera')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.25 7.75h3l1.5-2.25h6.5l1.5 2.25h3A1.75 1.75 0 0 1 21 9.5v8.25a1.75 1.75 0 0 1-1.75 1.75H4.75A1.75 1.75 0 0 1 3 17.75V9.5a1.75 1.75 0 0 1 1.25-1.75Z" />
            <circle cx="12" cy="13.25" r="3.25" />
            @break
        @case('edit')
            <path stroke-linecap="round" stroke-linejoin="round" d="m4 20 4.2-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z" />
            @break
        @case('copy')
            <rect x="8" y="8" width="12" height="12" rx="2.25" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 8V6.25A2.25 2.25 0 0 0 13.75 4h-7.5A2.25 2.25 0 0 0 4 6.25v7.5A2.25 2.25 0 0 0 6.25 16H8" />
            @break
        @case('schedule')
            <rect x="3.5" y="5.5" width="17" height="15" rx="2.5" />
            <path stroke-linecap="round" d="M7.5 3.5v4M16.5 3.5v4M3.5 10h17" />
            @break
        @case('open')
            <rect x="4" y="4" width="16" height="16" rx="3" />
            <path stroke-linecap="round" stroke-linejoin="round" d="m9 15 6-6m-5 0h5v5" />
            @break
        @case('view-file')
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 3.5h7.25L18 8.25v4.25M6 3.5v17h5M13.25 3.5v4.75H18" />
            <circle cx="15.25" cy="16.25" r="3.75" />
            <path stroke-linecap="round" d="m18 19 2.75 2.75" />
            @break
        @case('scanner')
            <g data-scanner-artwork="open-flatbed-scanner" stroke-linecap="round" stroke-linejoin="round">
                <path d="m7.15 4.15 11.7 8.1H5.4" />
                <path d="M4.25 12.25h15.5c.7 0 1.25.55 1.25 1.25v3.4c0 .7-.55 1.25-1.25 1.25H4.25A1.25 1.25 0 0 1 3 16.9v-3.4c0-.7.55-1.25 1.25-1.25Z" fill="currentColor" fill-opacity="0.16" />
                <path d="M5.25 18.15v1.2c0 .7.55 1.25 1.25 1.25h11c.7 0 1.25-.55 1.25-1.25v-1.2" />
                <circle cx="5.45" cy="14.6" r=".8" fill="currentColor" stroke="none" />
            </g>
            @break
        @case('chart')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 3.5v15.25A1.75 1.75 0 0 0 5.75 20.5H21M7 14l5-5 3.5 3.5L20 8" />
            @break
        @case('history')
            <g transform="translate(0.5 0.75)">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.25 6.25A8.25 8.25 0 0 0 5.15 5L3.5 7.5M3.5 4v3.5H7M12 7v5l3.5 2.25" />
                <path stroke-linecap="round" d="M20.1 10.25v1.5m-1.35 4.2-1.05 1.1m-3.7 2.6h-1.5m-4.35-1-1.25-.75M4.45 15l-.55-1.3M4.8 9.2l-.7 1.2" />
            </g>
            @break
        @case('lock')
            <rect x="5" y="10" width="14" height="10.5" rx="2.25" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 10V7.25a3.75 3.75 0 0 1 7.5 0V10M12 14.25v2.25" />
            @break
        @case('unlock')
            <rect x="5" y="10" width="14" height="10.5" rx="2.25" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 10V7.5a3.75 3.75 0 0 1 7.3-1.2M12 14.25v2.25" />
            @break
        @case('disable-group')
            <path
                data-group-disable-sign
                d="M7.25 3.5h9.5l3.75 3.75v9.5l-3.75 3.75h-9.5L3.5 16.75v-9.5L7.25 3.5Z"
                fill="currentColor"
                fill-opacity="0.16"
                stroke-linejoin="round"
            />
            <path
                data-group-disable-mark
                d="m8.15 8.15 7.7 7.7m0-7.7-7.7 7.7"
                stroke-width="2.8"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            @break
        @case('archive')
            <rect x="3.75" y="5.5" width="16.5" height="15" rx="2" />
            <path d="M6.25 5.5v-1.5a2 2 0 0 1 2-2h7.5a2 2 0 0 1 2 2V5.5" />
            <path d="M8.5 12h7m-3.5 0v-4m-1.75 0L12 10.5m1.75 3v3" />
            @break
        @case('reactivate')
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.25 8.25V4.5h-3.75" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 4.75a8 8 0 1 0 1 9.1" />
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.75 12 2.1 2.1 4.4-4.4" />
            @break
        @case('add')
            <path stroke-linecap="round" d="M12 5v14M5 12h14" />
            @break
        @case('book')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.1C9.6 5.35 7.05 4.8 3.5 5.55v12.1c3.55-.75 6.1-.2 8.5 1.55m0-12.1c2.4-1.75 4.95-2.3 8.5-1.55v12.1c-3.55-.75-6.1-.2-8.5 1.55m0-12.1v12.1" />
            @break
        @case('expand')
            <path stroke-linecap="round" stroke-linejoin="round" d="m10 10-5.5-5.5m0 4.5V4.5H9m5 5.5 5.5-5.5M15 4.5h4.5V9M10 14l-5.5 5.5m0-4.5v4.5H9m5-5.5 5.5 5.5M15 19.5h4.5V15" />
            @break
        @case('export')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.5V4m0 0L7.75 8.25M12 4l4.25 4.25" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 11.75v5.75A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-5.75" />
            @break
        @case('transfer')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h14m0 0-3.5-3.5M18 8l-3.5 3.5M20 16H6m0 0 3.5-3.5M6 16l3.5 3.5" />
            @break
        @case('clear-filter')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4.5h16v3.6l-6.25 6.1v3.25L10.5 20v-5.5L4 8.1V4.5" />
            <circle class="clear-filter-icon__badge" cx="8.65" cy="14.2" r="4" />
            <path class="clear-filter-icon__mark" stroke-linecap="round" d="m6.95 12.5 3.4 3.4m0-3.4-3.4 3.4" />
            @break
        @case('select-visible')
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 4h9.5A2.5 2.5 0 0 1 20 6.5V16" />
            <rect x="4" y="7" width="13" height="13" rx="2.25" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="m7.25 13.2 2.65 2.65 4.85-5.35" />
            @break
        @case('clear-selection')
            <rect x="3.5" y="3.5" width="17" height="17" rx="2.6" stroke-width="1.9" stroke-linecap="round" stroke-dasharray="2.8 2.8" data-clear-selection-frame />
            <path stroke-linecap="square" stroke-width="2.8" d="m8 8 8 8M16 8l-8 8" data-clear-selection-mark />
            @break
        @case('finalise')
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 10.65V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.35" />
            <path stroke-linecap="round" stroke-linejoin="round" d="m9 11 3 3L22 4" />
            @break
        @case('finish-line')
            <circle cx="4" cy="3.5" r="1.1" fill="currentColor" stroke="none" />
            <path stroke-linecap="round" d="M4 4.75v16.5M2 21.25h4" />
            <path stroke-linejoin="round" d="M5.25 5h15.5v12H5.25z" />
            <path fill="currentColor" stroke="none" d="M5.25 5h3.875v4H5.25zm7.75 0h3.875v4H13zm-3.875 4H13v4H9.125zm7.75 0h3.875v4h-3.875zM5.25 13h3.875v4H5.25zM13 13h3.875v4H13z" />
            <path stroke-width="1.15" d="M9.125 5v12M13 5v12m3.875-12v12M5.25 9h15.5M5.25 13h15.5" />
            @break
        @case('receipt')
            <g data-receipt-icon="supplied-invoice-sheet" transform="scale(0.2666667)" fill="currentColor" stroke="none">
                <path d="M74.635 55.709c-1.002 0-1.993-.251-2.896-.746-1.688-.924-2.845-2.616-3.094-4.527-.143-1.096.63-2.099 1.726-2.241 1.097-.149 2.099.63 2.241 1.726.085.657.468 1.217 1.048 1.534.482.264 1.032.324 1.56.17.524-.153.958-.503 1.221-.982.543-.991.178-2.238-.813-2.78l-3.89-2.233c-2.89-1.581-3.968-5.264-2.367-8.189 1.603-2.926 5.283-4.005 8.21-2.403 1.689.924 2.847 2.617 3.096 4.527.143 1.096-.63 2.099-1.726 2.242-1.087.137-2.099-.63-2.241-1.725-.085-.658-.468-1.217-1.049-1.535-.993-.542-2.238-.177-2.781.814-.541.99-.176 2.237.814 2.78l3.89 2.233c2.889 1.581 3.967 5.264 2.366 8.189-.776 1.417-2.057 2.447-3.607 2.901-.524.154-1.098.235-1.668.235Z" />
                <path d="M74.66 38.293a2 2 0 0 1-2-2v-2.71a2 2 0 1 1 4 0v2.71a2 2 0 0 1-2 2ZM74.66 58.417a2 2 0 0 1-2-2v-2.71a2 2 0 1 1 4 0v2.71a2 2 0 0 1-2 2Z" />
                <path d="M65.928 90H20.04c-5.918 0-10.732-4.814-10.732-10.732V10.732C9.308 4.814 14.122 0 20.04 0h45.888C71.846 0 76.66 4.814 76.66 10.732v14.709a2 2 0 1 1-4 0V10.732C72.66 7.02 69.64 4 65.928 4H20.04c-3.712 0-6.732 3.02-6.732 6.732v68.535c0 3.712 3.02 6.732 6.732 6.732h45.888c3.712 0 6.732-3.021 6.732-6.732V64.559a2 2 0 1 1 4 0v14.709C76.66 85.186 71.846 90 65.928 90Z" />
                <path d="M60.489 66.087h-27.42a2 2 0 1 1 0-4h27.42a2 2 0 1 1 0 4ZM60.489 54.159h-27.42a2 2 0 1 1 0-4h27.42a2 2 0 1 1 0 4ZM60.489 42.231h-27.42a2 2 0 1 1 0-4h27.42a2 2 0 1 1 0 4ZM60.489 30.303h-27.42a2 2 0 1 1 0-4h27.42a2 2 0 1 1 0 4Z" />
                <path d="M26.875 66.087h-1.396a2 2 0 1 1 0-4h1.396a2 2 0 1 1 0 4ZM26.875 54.159h-1.396a2 2 0 1 1 0-4h1.396a2 2 0 1 1 0 4ZM26.875 42.231h-1.396a2 2 0 1 1 0-4h1.396a2 2 0 1 1 0 4ZM26.875 30.303h-1.396a2 2 0 1 1 0-4h1.396a2 2 0 1 1 0 4Z" />
            </g>
            @break
        @case('print')
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 8V4h10v4M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7z" />
            <circle cx="18" cy="12" r="0.55" fill="currentColor" stroke="none" />
            @break
        @case('save')
            <g data-supplied-save-disk stroke="currentColor" stroke-width="16" stroke-linecap="round" stroke-linejoin="round">
                <path d="M385.8,337.3l453.7-.2c12.7,1.9,18.5,10.5,26.6,18.4,15.3,15.1,30.1,30.7,45,46,13.3,13.7,44.7,39.6,49,57,2.5,150.4.3,301.4,1.1,452-5.8,38-41.8,29.7-69.5,29.7-158.4.2-317.4.6-476,.8-7.7,0-16.3,0-23.8-.2-16.9-.4-29.4-16.2-29.6-32.4V371.5c1-17,5.8-29.8,23.8-34.2h-.3ZM484,354h-94.5c-2.4,0-8.7,5-9,8v552.1c.7,3.5,6.7,9,10,9h37.5v-237.5c0-8,15.7-21.3,24.5-19.5h401.1c7.9-.2,21.5,12.2,21.5,19.5v237.5h56.5c5.9,0,13-10.6,12.5-16.5l-.2-438.9c-.9-5.3-3.2-10.3-6.4-14.6l-90-92c-2.6-2.3-9.8-7-13-7h-18.5v182.5c0,.6-3.2,8-3.8,9.2-4.4,8.1-11.6,12.9-20.7,14.3h-280c-12.4.7-27.5-13.3-27.5-25.5v-180.5h0ZM799,354h-298c.7,1.5,1,2.7,1.1,4.4,1.3,52.8-.4,106.3-1,159,2.7,13.5-4.1,24.1,14.4,25.6,91.4-.5,183,.9,274.4-.7,3.8-.3,9.2-6.5,9.2-9.8v-178.5h-.1ZM858,682.9h-413v240h413v-240Z" />
                <path d="M506.7,752.2l287.8-.2c11.2,1.3,11.9,16.5-1,18h-285c-11.4-1.4-12.7-14.6-1.8-17.8h0Z" />
                <path d="M800.7,829.3c5.3,4.9,1.7,15.5-6.1,14.7h-288.1c-8.9-2.3-9.9-12.6-1.5-16.6l288.6-.5c2.2,0,5.5.9,7.1,2.4h0Z" />
                <path d="M705.7,385.2c16.3,1,36-2.1,51.8-.2,5.2.6,6.1,2.6,6.5,7.5,3.3,35.9-2.6,76.7,0,113.1,0,6.5-3.8,8-9.5,8.5-10.4,1-33.8,1.1-44,0-4.2-.5-7.3-1.9-9-6l-.5-113.6c0-3.6,1.2-7.6,4.8-9.2h0ZM719,402v94h28v-94h-28Z" />
            </g>
            @break
        @case('save-new')
            @php
                $saveNewClearanceMaskId = 'save-new-clearance-'.\Illuminate\Support\Str::random(10);
            @endphp
            <defs>
                <mask id="{{ $saveNewClearanceMaskId }}" maskUnits="userSpaceOnUse" x="250" y="230" width="760" height="760">
                    <rect x="250" y="230" width="760" height="760" fill="white" stroke="none" />
                    <ellipse data-save-new-asterisk-clearance cx="370" cy="369" rx="104" ry="112" fill="black" stroke="none" />
                </mask>
            </defs>
            <g data-save-new-artwork transform="translate(12 5)" stroke="currentColor" stroke-width="16" stroke-linecap="round" stroke-linejoin="round">
                <g data-save-new-disk mask="url(#{{ $saveNewClearanceMaskId }})">
                    <g transform="translate(-23 -51)">
                        <path d="M437.4,932.2v-225.5c0-10.2,15.6-21.2,25.5-20.5h379.1c9.1.2,19,4.3,23.7,12.3.8,1.4,3.8,9.1,3.8,10.2v223.5h51.5c4.6,0,12.1-8.5,12.8-13.2,1.5-140.3.3-280.8.6-421.2-1.3-6.9-4.5-11.9-8.9-17.1-25.1-29.8-60-57.3-86-87-2.1-1.6-9.2-5.5-11.5-5.5h-15.5v172.5c0,10.7-14.3,24.8-25.5,24.5h-272c-8.6.5-24.5-12.2-24.5-20.5v-176.5h-39.5c-5.5,0-9.9-15.6,2-17h377.9c8.2,1.4,15.2,5.5,21.6,10.5,26.9,31.4,62.4,58.9,88.5,90.5,15.5,18.8,10.2,47.2,10.4,70.6,1,125.3-.4,251.7,0,377.8-3.7,18.2-17.1,28.3-35.3,29.7H389.9c-19-1.4-32.6-12.3-35.5-31.5l.6-442.5c4.6-6.5,12.5-6.5,16,.9l.4,438.6c.7,5.1,3,9.8,7.1,12.9.7.5,6.1,3.6,6.4,3.6h52.5ZM795.4,388.2h-288v172.5c0,2.8,8,8.7,11.5,7.5l268.6-.5c3.6-1,8-6.4,8-10v-169.5h0ZM454.4,703.1v229.1h398.1v-229.1h-398.1,0Z" />
                        <path d="M706.1,417.4l49.8-.3c2.9,0,4.8,2.5,6.2,4.8l.2,111.8c0,2.7-.7,5.5-3.3,6.7-4.4,2-36.7.7-44.3.7s-9.6,2.5-13-4.9l-.5-111.6c0-3.1,2-5.8,4.7-7.3h.2ZM745.4,434.2h-27v90h27v-90Z" />
                        <path d="M797.1,783.9c-1.6,1.5-4.9,2.3-7.1,2.4l-277.6-.5c-10.1-5.1-6.7-17.6,4.5-17.6h270.1c9-1.6,17.5,9,10.2,15.7h0Z" />
                        <path d="M512.1,841.4l279.8-.2c11.4,1.3,9.7,18-2,17.1h-275.1c-9.5-1.1-12.1-13.5-2.8-16.8h0Z" />
                    </g>
                </g>
                <path data-save-new-asterisk fill="var(--save-new-asterisk)" stroke="var(--save-new-asterisk)" transform="translate(4 -10) translate(366 379) scale(1.3) translate(-366 -379)" d="M370.7,362.6l43.8-25.9c9.4-2.9,14.6,5.3,8.8,12.7-13.1,9.2-29.5,16.1-42.3,25.3-.6.4-2.5,1.8-2.1,2.2,15.2,8.2,29.8,17.7,44.5,26.7,6.4,5.8,0,15.5-8,12.7l-43.8-25.9c-.6,11.3-.8,22.5-.8,33.8s.9,4.3.9,6.4c0,3.7-1.5,8.4-1,12.6-1.7,7.6-14.2,4-14.2-.6v-52.1l-44.3,26.1c-9.1,2-13.6-7.8-6.5-13.7,9.4-7.7,29.8-18.2,41.1-25,.8-.5,1.7-.9,2.6-1l-2.1-2.2-43.3-26c-5.3-5.8,1.5-16.1,9-12.2l43.6,26v-53.7c0-2,4.5-5.2,6.8-5.3s7.6,2.1,7.6,4.5v54.5h0v-.2Z" />
            </g>
            @break
        @case('search')
            <circle cx="10.75" cy="10.75" r="6.25" />
            <path stroke-linecap="round" d="m15.25 15.25 4.5 4.5" />
            @break
        @case('review')
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 5H6.25A1.25 1.25 0 0 0 5 6.25v13.5h14V6.25A1.25 1.25 0 0 0 17.75 5H15.5M9 3h6v4H9zM9 14l2 2 4-4" />
            @break
        @case('permissions')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.25 19 6v5.15c0 4.45-2.8 7.75-7 9.6-4.2-1.85-7-5.15-7-9.6V6l7-2.75Z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m8.75 12 2.1 2.1 4.4-4.4" />
            @break
        @case('eligible-students')
            <g data-eligible-awqaf-icon="students-check" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="4.65" r="2.15" />
                <circle cx="5.4" cy="7" r="1.6" />
                <circle cx="18.6" cy="7" r="1.6" />
                <path d="M7.6 12.25c.55-2.65 1.95-3.85 4.4-3.85s3.85 1.2 4.4 3.85" />
                <path d="M1.8 13.4c.35-2.6 1.55-3.9 3.6-3.9 1.15 0 2.05.4 2.7 1.25M22.2 13.4c-.35-2.6-1.55-3.9-3.6-3.9-1.15 0-2.05.4-2.7 1.25" />
                <circle cx="12" cy="17.4" r="4.4" />
                <path stroke-width="2.1" d="m9.75 17.3 1.55 1.6 3.15-3.45" />
            </g>
            @break
        @case('delete')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5" />
            @break
        @case('unlink')
            <g data-unlink-icon="supplied-artboard-1" transform="translate(35.84 35.84) scale(.72)">
                <path d="M191.37,174.75c-2.97-1.88-5.48-2.81-9.53-1.98-3.56.72-6.49,3-8.03,6.25-1.68,3.54-1.56,7.82.34,11.44,1.27,2.43,18.4,19.48,20.5,20.8,2.38,1.5,4.94,2.25,7.39,2.25,2.86,0,5.59-1.01,7.77-3.02,4.03-3.71,4.84-9.57,2.05-14.94-1.41-2.72-18.89-19.78-20.5-20.8Z" />
                <path d="M65.53,81.85c1.99,1.05,4.1,1.56,6.16,1.56,3.26,0,6.38-1.29,8.65-3.75,3.76-4.08,4.11-9.98.9-15.02-1.56-2.45-9.53-10.1-11.11-11.6-10.27-9.79-11.76-10.06-13.07-10.3-1.45-.26-2.68-.26-4.13,0-3.99.72-7.37,3.43-9.04,7.26-1.63,3.74-1.31,8,.87,11.4,1.22,1.91,18.06,19.05,20.77,20.47Z" />
                <path d="M160.09,199.11c-2.64-3.29-6.71-4.94-10.89-4.39-5.62.73-9.89,4.85-10.62,10.27-.44,3.25,2,26.96,6.49,30.88,2.12,1.85,4.92,2.82,7.87,2.82,1.5,0,3.03-.25,4.53-.76,4.2-1.44,7.16-4.64,7.93-8.58.66-3.39-.67-12.48-1.1-15.18-1.91-12.19-3.58-14.27-4.21-15.05Z" />
                <path d="M94.77,54.8c1.98,3.82,6.08,6.11,10.62,6.11.77,0,1.56-.07,2.35-.2,5.19-.89,8.99-4.69,9.68-9.68.51-3.67-2.17-26.58-6.12-30.7-2.32-2.42-6.52-3.93-9.99-3.6h0c-3.44.33-11.37,2.44-10.81,14.94.15,3.29,2.28,19.27,4.28,23.13Z" />
                <path d="M24.12,113.5c3.3,1.58,21.83,4.1,25.36,4.1.07,0,.14,0,.2,0,5.94-.22,10.67-4.24,11.77-9.99,1.06-5.58-1.68-11-6.67-13.2-2.9-1.28-24.68-4.34-28-3.63-5.07,1.09-8.74,5.16-9.36,10.38-.62,5.26,2.01,10.1,6.69,12.34Z" />
                <path d="M238.64,151.03c-.71-3.68-2.84-6.69-5.83-8.25-4.43-2.32-24.99-5.05-30.09-3.51-4.05,1.22-6.87,4.95-7.36,9.73-.52,5.13,1.79,10.03,5.75,12.18,3.38,1.84,19,4.14,26.25,4.14,1.23,0,2.22-.07,2.87-.21,1.54-.35,4.73-2.13,5.88-3.49h0c2.33-2.75,3.27-6.71,2.52-10.6Z" />
                <path d="M151.37,111.57c-3.66-4.2-10.11-8.51-16.29-6.85-2.35.63-6.48,2.73-7.8,9.63-1.15,5.97,2.38,10.13,5.21,13.46,1.35,1.59,2.63,3.1,3.27,4.58,3.59,8.31,2.49,17.59-2.94,24.81-6.33,8.43-41.38,43.8-50.08,49.94-10.08,7.11-20.69,4.79-27.25.25-6.77-4.69-12.87-14.07-9.68-26.32,2.07-7.96,12.87-17.65,22.4-26.2,7.19-6.46,13.99-12.56,17.96-18.42,4.33-6.38,4.23-12.87-.26-17.36-2.23-2.23-5.02-3.36-8.1-3.3-7.65.18-14.95,7.51-22.38,15.66-1.04,1.15-1.94,2.13-2.66,2.86-.83.84-1.74,1.75-2.71,2.73-9.04,9.08-22.71,22.79-26.45,32.67-7.96,21-1.44,43.16,16.61,56.45,8.95,6.59,19.1,9.88,29.21,9.88,10.45,0,20.85-3.51,29.82-10.53,12.03-9.4,43.89-41.26,53.27-53.28,13.7-17.54,13.2-44.19-1.15-60.68Z" />
                <path d="M186.1,137.67c6.93-4.9,39.07-37.9,42.98-44.78,9.31-16.39,8.73-35.41-1.55-50.89-10.14-15.26-27.86-23.38-46.28-21.19-8.29.99-17.52,4.89-24.08,10.17-13.48,10.86-40.97,38.74-51.65,51.62-13.49,16.27-15.28,38.4-4.57,56.39,1.24,2.09,7.88,12.53,16.38,12.89.15,0,.3,0,.46,0,2.64,0,6.25-.98,9.35-5.3,5.26-7.33.06-13.74-3.04-17.57-1.13-1.39-2.2-2.71-2.78-3.86-4.24-8.35-3.68-17.22,1.58-24.98,5.86-8.64,39.44-41.37,48.44-48.73,13.17-10.77,25.97-6.7,32.94.06,7.21,6.99,10.96,19.75,2.14,31.22-3.97,5.17-10.5,10.95-16.81,16.55-7.81,6.93-15.89,14.09-20.24,20.98-4.22,6.7-2.37,13.25,1.35,16.77,4.16,3.94,10.34,4.2,15.39.63Z" />
            </g>
            @break
    @endswitch
</svg>
