@php
    $brandLogoPath = public_path('storage/website/branding/logo.jpeg');
@endphp

<div class="flex aspect-square size-11 items-center justify-center overflow-hidden rounded-2xl bg-white shadow-[0_18px_30px_-18px_rgba(0,107,45,0.6)]">
    @if (file_exists($brandLogoPath))
        <img src="{{ asset('storage/website/branding/logo.jpeg') }}" alt="{{ __('ui.app.name') }}" class="h-full w-full object-cover" />
    @else
        <x-app-logo-icon class="size-6 fill-current text-[#006b2d]" />
    @endif
</div>
<div class="grid flex-1 text-left leading-tight">
    <span class="font-display truncate text-base text-white">{{ __('ui.app.name') }}</span>
    <span class="mt-1 truncate text-[0.68rem] font-semibold uppercase tracking-[0.28em] text-neutral-400">{{ __('ui.app.short_tagline') }}</span>
</div>
