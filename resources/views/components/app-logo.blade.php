@props([
    'title' => __('ui.app.name'),
    'subtitle' => __('ui.app.short_tagline'),
])

@php
    $siteLogoUrl = app(\App\Services\WebsiteService::class)->siteSettings()['logo_url'] ?? null;
    $fallbackLogoPath = public_path('storage/website/branding/logo.jpeg');
    $logoUrl = $siteLogoUrl ?: (file_exists($fallbackLogoPath) ? asset('storage/website/branding/logo.jpeg') : null);
@endphp

<div class="flex aspect-square size-11 items-center justify-center overflow-hidden rounded-2xl bg-white shadow-[0_18px_30px_-18px_rgba(0,107,45,0.6)]">
    @if ($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ __('ui.app.name') }}" class="h-full w-full object-cover" />
    @else
        <x-app-logo-icon class="size-6 fill-current text-[#006b2d]" />
    @endif
</div>
<div class="grid flex-1 text-left leading-tight">
    <span class="font-display truncate text-base text-white">{{ $title }}</span>
    <span class="mt-1 truncate text-[0.68rem] font-semibold uppercase tracking-[0.28em] text-neutral-400">{{ $subtitle }}</span>
</div>
