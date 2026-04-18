@props([
    'teacher' => null,
    'size' => 'md',
])

@php
    $name = trim(($teacher?->first_name ?? '').' '.($teacher?->last_name ?? ''));
    $segments = array_values(array_filter(preg_split('/\s+/', $name ?: '')));
    $initials = collect($segments)
        ->take(2)
        ->map(fn ($segment) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($segment, 0, 1)))
        ->implode('');
    $sizeClass = match ($size) {
        'sm' => 'student-avatar--sm',
        'lg' => 'student-avatar--lg',
        default => 'student-avatar--md',
    };
    $photoUrl = $teacher?->photo_path ? '/storage/'.ltrim($teacher->photo_path, '/') : null;
@endphp

<span {{ $attributes->class(['student-avatar', $sizeClass]) }}>
    @if ($photoUrl)
        <img src="{{ $photoUrl }}" alt="{{ $name !== '' ? $name : __('crud.teachers.photo.alt') }}" class="student-avatar__image">
    @else
        <span class="student-avatar__fallback">{{ $initials !== '' ? $initials : 'T' }}</span>
    @endif
</span>
