@props(['src', 'alt' => '', 'type' => 'user'])

<img
    src="{{ $src }}"
    alt="{{ $alt }}"
    data-avatar-default="{{ \App\Support\AvatarDefaults::url($type) ?: asset('images/default-avatar.svg') }}"
    data-avatar-placeholder="{{ asset('images/default-avatar.svg') }}"
    onerror="if (this.getAttribute('src') !== this.dataset.avatarDefault && !this.dataset.avatarFallback) { this.dataset.avatarFallback = 'true'; this.src = this.dataset.avatarDefault; } else { this.onerror = null; this.src = this.dataset.avatarPlaceholder; }"
    {{ $attributes }}
>
