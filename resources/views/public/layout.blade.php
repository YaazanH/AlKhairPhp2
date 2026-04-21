@php
    $currentLocale = app()->getLocale();
    $currentLocaleConfig = config('app.supported_locales.'.$currentLocale, []);
    $textDirection = $currentLocaleConfig['direction'] ?? 'ltr';
    $siteTagline = data_get($site, 'site_tagline.'.$currentLocale)
        ?: data_get($site, 'site_tagline.'.config('app.fallback_locale', 'en'));
    $siteDescription = data_get($site, 'site_description.'.$currentLocale)
        ?: data_get($site, 'site_description.'.config('app.fallback_locale', 'en'));
    $siteAddress = data_get($site, 'contact_address.'.$currentLocale)
        ?: data_get($site, 'contact_address.'.config('app.fallback_locale', 'en'));
    $metaTitle = $title ?? $site['site_name'];
    $metaDescription = $metaDescription ?? $siteDescription ?? $siteTagline ?? $site['site_name'];
    $metaImage = $site['logo_url'] ?? null;
    $metaImageAlt = $site['site_name'];
    $metaUrl = url()->current();
    $themeColor = $site['primary_color'] ?? '#006b2d';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}" dir="{{ $textDirection }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="public-site" style="--site-primary: {{ $site['primary_color'] ?? '#006b2d' }}; --site-accent: {{ $site['accent_color'] ?? '#0b8f43' }};">
        <div class="public-backdrop">
            <div class="public-backdrop__orb public-backdrop__orb--primary"></div>
            <div class="public-backdrop__orb public-backdrop__orb--accent"></div>
        </div>

        <div class="public-shell">
            <header class="public-header">
                <div class="public-header__inner">
                    <a href="{{ route('home') }}" class="public-brand">
                        <div class="public-brand__mark">
                            @if (! empty($site['logo_url']))
                                <img src="{{ $site['logo_url'] }}" alt="{{ $site['site_name'] }}" class="public-brand__image">
                            @else
                                <x-app-logo-icon class="h-8 w-8 fill-current text-neutral-950" />
                            @endif
                        </div>
                        <div class="public-brand__copy">
                            <span class="public-brand__name">{{ $site['site_name'] }}</span>
                            @if ($siteTagline)
                                <span class="public-brand__tagline">{{ $siteTagline }}</span>
                            @endif
                        </div>
                    </a>

                    <nav class="public-nav">
                        <a href="{{ route('home') }}" class="public-nav__link">{{ __('site.public.nav.home') }}</a>
                        @foreach (($navigationMenu ?? []) as $item)
                            @php
                                $children = $item['children'] ?? [];
                                $hasChildren = $children !== [];
                            @endphp

                            @if ($hasChildren)
                                <details class="public-nav__group">
                                    <summary class="public-nav__link public-nav__toggle">
                                        <span>{{ $item['label'] }}</span>
                                        <span class="public-nav__caret"></span>
                                    </summary>

                                    <div class="public-nav__dropdown">
                                        @if (! empty($item['url']))
                                            <a href="{{ $item['url'] }}" @if(! empty($item['open_in_new_tab'])) target="_blank" rel="noreferrer" @endif class="public-nav__dropdown-link public-nav__dropdown-link--parent">
                                                {{ $item['label'] }}
                                            </a>
                                        @endif

                                        @foreach ($children as $child)
                                            <a href="{{ $child['url'] ?: '#' }}" @if(! empty($child['open_in_new_tab'])) target="_blank" rel="noreferrer" @endif class="public-nav__dropdown-link">
                                                {{ $child['label'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </details>
                            @elseif (! empty($item['url']))
                                <a href="{{ $item['url'] }}" @if(! empty($item['open_in_new_tab'])) target="_blank" rel="noreferrer" @endif class="public-nav__link">
                                    {{ $item['label'] }}
                                </a>
                            @endif
                        @endforeach
                    </nav>

                    <div class="public-actions">
                        <x-locale-switcher compact />

                        @auth
                            <a href="{{ route('dashboard') }}" class="pill-link pill-link--accent">{{ __('site.public.nav.dashboard') }}</a>
                        @else
                            <a href="{{ route('login') }}" class="pill-link">{{ __('site.public.nav.login') }}</a>
                        @endauth
                    </div>
                </div>
            </header>

            <main class="public-main">
                @yield('content')
            </main>

            <footer class="public-footer">
                <div class="public-footer__grid">
                    <div class="public-footer__panel">
                        <div class="eyebrow">{{ $site['site_name'] }}</div>
                        <h2 class="font-display mt-4 text-3xl text-white">{{ $siteTagline }}</h2>
                        @if ($siteDescription)
                            <p class="mt-4 text-sm leading-7 text-neutral-300">{{ $siteDescription }}</p>
                        @endif
                    </div>

                    <div class="public-footer__panel">
                        <div class="eyebrow">{{ __('site.public.sections.visit') }}</div>
                        <div class="mt-5 space-y-3 text-sm text-neutral-200">
                            @if (! empty($site['contact_phone']))
                                <div><span class="text-neutral-400">{{ __('site.public.labels.phone') }}:</span> <span dir="ltr" class="inline-block text-left" style="unicode-bidi: isolate;">{{ $site['contact_phone'] }}</span></div>
                            @endif
                            @if (! empty($site['contact_email']))
                                <div><span class="text-neutral-400">{{ __('site.public.labels.email') }}:</span> {{ $site['contact_email'] }}</div>
                            @endif
                            @if ($siteAddress)
                                <div><span class="text-neutral-400">{{ __('site.public.labels.address') }}:</span> {{ $siteAddress }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </body>
</html>
