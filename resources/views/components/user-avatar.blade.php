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
    $avatarType = $user?->studentProfile ? 'student' : ($user?->teacherProfile ? 'teacher' : ($user?->parentProfile ? 'parent' : 'user'));
    $photoUrl = $user?->profilePhotoUrl();
@endphp

<span {{ $attributes->class(['student-avatar', $sizeClass]) }}>
    @if ($photoUrl)
        <x-avatar-image :type="$avatarType" :src="$photoUrl" alt="{{ $user?->name ?: __('settings.account.profile.fields.photo') }}" class="student-avatar__image" />
    @else
        <span class="student-avatar__fallback">{{ $initials }}</span>
    @endif
</span>
