@props([
    'user' => null,
    'size' => 'md',
])

@php
    $initials = $user?->initials() ?: 'U';
    $sizeClass = match ($size) {
        'sm' => 'student-avatar--sm',
        'lg' => 'student-avatar--lg',
        default => 'student-avatar--md',
    };
    $photoUrl = $user?->profilePhotoUrl();
@endphp

<span {{ $attributes->class(['student-avatar', $sizeClass]) }}>
    @if ($photoUrl)
        <img src="{{ $photoUrl }}" alt="{{ $user?->name ?: __('settings.account.profile.fields.photo') }}" class="student-avatar__image">
    @else
        <span class="student-avatar__fallback">{{ $initials }}</span>
    @endif
</span>
