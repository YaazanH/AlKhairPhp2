@props([
    'title' => __('ui.app.name'),
    'subtitle' => __('ui.app.short_tagline'),
    'justifySubtitleToTitle' => false,
])

@php
    $siteLogoUrl = app(\App\Services\WebsiteService::class)->siteSettings()['logo_url'] ?? null;
    $fallbackLogoPath = public_path('storage/website/branding/logo.jpeg');
    $logoUrl = $siteLogoUrl ?: (file_exists($fallbackLogoPath) ? asset('storage/website/branding/logo.jpeg') : null);
    $measureArabicSubtitleToTitle = app()->isLocale('ar') && (bool) $justifySubtitleToTitle;
    $useJustifiedArabicSubtitle = app()->isLocale('ar') && ! $measureArabicSubtitleToTitle && $subtitle === __('ui.app.short_tagline');
    $displaySubtitle = $useJustifiedArabicSubtitle ? 'مــنــصــة الــتــعــلــم' : $subtitle;
@endphp

<div class="flex aspect-square size-11 items-center justify-center overflow-hidden rounded-2xl bg-white shadow-[0_18px_30px_-18px_rgba(0,107,45,0.6)]">
    @if ($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ __('ui.app.name') }}" class="h-full w-full object-cover" />
    @else
        <x-app-logo-icon class="size-6 fill-current text-[#006b2d]" />
    @endif
</div>
<div @class(['grid flex-1 text-left leading-tight', 'app-logo-period-lockup' => $measureArabicSubtitleToTitle]) @if($measureArabicSubtitleToTitle) data-app-logo-period-lockup @endif>
    <span @class(['font-display truncate text-base text-white', 'app-logo-period-title' => $measureArabicSubtitleToTitle]) @if($measureArabicSubtitleToTitle) data-app-logo-period-title @endif>{{ $title }}</span>
    <span
        @class([
            'mt-1 truncate font-semibold text-neutral-400',
            'text-[0.72rem]' => $useJustifiedArabicSubtitle,
            'app-logo-period-subtitle text-[0.64rem]' => $measureArabicSubtitleToTitle,
            'text-[0.68rem]' => ! $useJustifiedArabicSubtitle && ! $measureArabicSubtitleToTitle,
        ])
        @if ($useJustifiedArabicSubtitle) aria-label="{{ $subtitle }}" data-app-logo-kashida-subtitle @endif
        @if ($measureArabicSubtitleToTitle) aria-label="{{ $subtitle }}" data-app-logo-period-subtitle @endif
    >{{ $displaySubtitle }}</span>
</div>
