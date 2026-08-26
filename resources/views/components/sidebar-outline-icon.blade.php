@props([
    'variant' => 'outline',
])

@php
    if ($variant !== 'outline') {
        throw new \Exception('Sidebar composite icons support the outline variant only.');
    }

    $mergedMaskId = 'sidebar-outline-icon-mask-'.(string) \Illuminate\Support\Str::uuid();
    $classes = Flux::classes('shrink-0')->add('[:where(&)]:size-6');
@endphp

<svg
    {{ $attributes->class($classes) }}
    data-flux-icon
    xmlns="http://www.w3.org/2000/svg"
    fill="none"
    viewBox="0 0 24 24"
    stroke-width="1.5"
    stroke="currentColor"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    data-slot="icon"
>
    {{--
        Paint composite icons through one mask. Applying a translucent sidebar
        colour to every path separately darkens their intersections; painting
        the completed silhouette once keeps all overlaps uniformly translucent.
    --}}
    <defs>
        <mask id="{{ $mergedMaskId }}" maskUnits="userSpaceOnUse" mask-type="luminance">
            <rect width="24" height="24" fill="black" stroke="none" />
            <g
                color="white"
                fill="none"
                stroke="white"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                {{ $slot }}
            </g>
        </mask>
    </defs>

    <rect
        width="24"
        height="24"
        fill="currentColor"
        stroke="none"
        mask="url(#{{ $mergedMaskId }})"
        data-sidebar-icon-merged-paint
    />
</svg>
