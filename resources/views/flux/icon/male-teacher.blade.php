@props(['variant' => 'outline'])

<x-sidebar-outline-icon :$variant {{ $attributes }}>
    <g
        data-teachers-icon="teacher-pointing-at-board"
        data-teachers-style="faithful-landscape-board-trace"
    >
        {{-- Full rounded presentation board behind the teacher. --}}
        <path d="M2.2 15.55V6.72A1.92 1.92 0 0 1 4.12 4.8h15.76a1.92 1.92 0 0 1 1.92 1.92v9.95a1.92 1.92 0 0 1-1.92 1.92h-4.45" />

        {{-- Keep a little breathing room between the pointer and board edge. --}}
        <g transform="translate(-0.4 0)">
            {{-- Large circular head, matching the supplied proportions. --}}
            <circle cx="8.24" cy="10.12" r="2.72" />

            {{-- Shoulders and cropped lower torso from the source artwork. --}}
            <path d="M3.45 21.55v-4.12a3.38 3.38 0 0 1 3.38-3.38h3.35c1.3 0 2.54.5 3.48 1.4l1.15 1.11" />
            <path d="M5.9 21.55v-2.8M11.1 21.55v-2.8" />

            {{-- Bent pointing arm, with the pointer continuing from the hand. --}}
            <path d="m13.62 15.42 1.38 1.7 1.22-1.58a1.28 1.28 0 0 1 1.91-.13 1.28 1.28 0 0 1 .14 1.66l-2.05 2.72a1.76 1.76 0 0 1-2.57.26l-2.55-2.3" />
            <path d="m17.62 14.77 3.04-6.08" />
        </g>
    </g>
</x-sidebar-outline-icon>
