@props([
    'compact' => false,
])

@php
    $supportedLocales = config('app.supported_locales', []);
    $currentLocale = app()->getLocale();
@endphp

<div @class([
    'space-y-3' => ! $compact,
    'flex flex-wrap items-center gap-2' => $compact,
])>
    @unless ($compact)
        <div class="eyebrow">{{ __('ui.common.language') }}</div>
    @endunless

    <div class="flex flex-wrap gap-2">
        @foreach ($supportedLocales as $localeCode => $localeConfig)
            <a
                href="{{ route('locale.switch', $localeCode) }}"
                class="pill-link pill-link--compact {{ $currentLocale === $localeCode ? 'pill-link--accent' : '' }}"
            >
                {{ $localeConfig['native'] }}
            </a>
        @endforeach
    </div>
</div>
