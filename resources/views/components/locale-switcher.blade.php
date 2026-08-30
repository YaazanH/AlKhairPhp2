@props([
    'compact' => false,
])

@php
    $supportedLocales = config('app.supported_locales', []);
    $currentLocale = app()->getLocale();
@endphp

@if ($compact)
    <div class="account-preference-switch account-preference-switch--language locale-compact-switch" role="radiogroup" aria-label="{{ __('ui.common.language') }}">
        @foreach ($supportedLocales as $localeCode => $localeConfig)
            <a
                href="{{ route('locale.switch', $localeCode) }}"
                @class(['account-preference-switch__option', 'is-active' => $currentLocale === $localeCode])
                role="radio"
                aria-checked="{{ $currentLocale === $localeCode ? 'true' : 'false' }}"
                aria-label="{{ $localeConfig['native'] }}"
                title="{{ $localeConfig['native'] }}"
                hreflang="{{ $localeCode }}"
                lang="{{ $localeCode }}"
                dir="{{ $localeConfig['direction'] ?? 'ltr' }}"
            >
                <span @class(['locale-compact-switch__label', 'locale-compact-switch__label--ar' => $localeCode === 'ar'])>
                    {{ $localeCode === 'en' ? 'EN' : ($localeCode === 'ar' ? 'ع' : strtoupper($localeCode)) }}
                </span>
            </a>
        @endforeach
    </div>
@else
    <div class="space-y-3">
        <div class="eyebrow">{{ __('ui.common.language') }}</div>

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
@endif
