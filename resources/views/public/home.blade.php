@extends('public.layout')

@section('content')
    @php
        $sections = collect($page->localizedSections());
        $hero = $sections->firstWhere('type', 'hero') ?? [];
        $stats = $sections->firstWhere('type', 'stats')['items'] ?? [];
        $story = $sections->firstWhere('type', 'story') ?? [];
        $programs = $sections->firstWhere('type', 'programs') ?? [];
        $siteAddress = data_get($site, 'contact_address.'.app()->getLocale())
            ?: data_get($site, 'contact_address.'.config('app.fallback_locale', 'en'));
        $programCards = collect($programs['cards'] ?? []);
        $featuredProgram = $programCards->first();
        $secondaryPrograms = $programCards->slice(1)->values();
    @endphp

    <section class="public-home-stage">
        <div class="public-home-stage__copy">
            @if (! empty($hero['eyebrow']))
                <div class="eyebrow">{{ $hero['eyebrow'] }}</div>
            @endif

            <h1 class="public-home-stage__title">{{ $hero['title'] ?? $page->localizedText('title') }}</h1>
            <p class="public-home-stage__subtitle">{{ $hero['subtitle'] ?? $page->localizedText('excerpt') }}</p>

            <div class="public-hero__actions">
                @if (! empty($hero['primary_cta_label']) && ! empty($hero['primary_cta_url']))
                    <a href="{{ $hero['primary_cta_url'] }}" class="pill-link pill-link--accent">{{ $hero['primary_cta_label'] }}</a>
                @endif
                @if (! empty($hero['secondary_cta_label']) && ! empty($hero['secondary_cta_url']))
                    <a href="{{ $hero['secondary_cta_url'] }}" class="pill-link">{{ $hero['secondary_cta_label'] }}</a>
                @endif
            </div>

            @if ($siteAddress || ! empty($site['contact_phone']) || ! empty($site['contact_email']))
                <div class="public-contact-strip">
                    @if ($siteAddress)
                        <div class="public-contact-strip__item">{{ $siteAddress }}</div>
                    @endif
                    @if (! empty($site['contact_phone']))
                        <div class="public-contact-strip__item"><span dir="ltr" class="inline-block text-left" style="unicode-bidi: isolate;">{{ $site['contact_phone'] }}</span></div>
                    @endif
                    @if (! empty($site['contact_email']))
                        <div class="public-contact-strip__item">{{ $site['contact_email'] }}</div>
                    @endif
                </div>
            @endif
        </div>

        <aside class="public-home-stage__side">
            <div class="public-media-card">
                @if (! empty($site['hero_image_url']))
                    <img src="{{ $site['hero_image_url'] }}" alt="{{ $site['site_name'] }}" class="public-media-card__image">
                @endif

                <div class="public-media-card__body">
                    <div class="eyebrow">{{ __('site.public.sections.visit') }}</div>
                    <h2 class="mt-3 font-display text-2xl text-white">{{ $site['site_name'] }}</h2>
                    @if ($siteAddress)
                        <p class="mt-3 text-sm leading-7 text-neutral-300">{{ $siteAddress }}</p>
                    @endif

                    <div class="public-hero__actions">
                        @if (! empty($site['maps_url']))
                            <a href="{{ $site['maps_url'] }}" target="_blank" class="pill-link">{{ __('site.public.cta.view_map') }}</a>
                        @endif
                        @if (! empty($site['featured_video_url']))
                            <a href="{{ $site['featured_video_url'] }}" target="_blank" class="pill-link pill-link--accent">{{ __('site.public.cta.watch_video') }}</a>
                        @endif
                        @if (! empty($site['whatsapp_url']))
                            <a href="{{ $site['whatsapp_url'] }}" target="_blank" class="pill-link">{{ __('site.public.cta.whatsapp') }}</a>
                        @endif
                    </div>
                </div>
            </div>
        </aside>
    </section>

    @if ($stats !== [])
        <section class="public-highlight-strip">
            <div class="public-stat-grid">
                @foreach ($stats as $item)
                    <article class="public-stat-card">
                        <div class="public-stat-card__value">{{ $item['value'] ?? '0' }}</div>
                        <div class="public-stat-card__label">{{ $item['label'] ?? '' }}</div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <div class="public-feature-grid">
        <section class="public-section">
            <div class="eyebrow">{{ __('site.public.sections.story') }}</div>
            <h2 class="public-section__title">{{ $story['title'] ?? $page->localizedText('title') }}</h2>
            <p class="public-section__text">{{ $story['body'] ?? $page->localizedText('body') }}</p>

            @if (! empty($story['quote']))
                <div class="public-quote-card mt-6">
                    <div class="eyebrow">{{ $site['site_name'] }}</div>
                    <p class="mt-4 font-display text-2xl leading-tight text-white">{{ $story['quote'] }}</p>
                </div>
            @endif
        </section>

        <section id="visit" class="public-section">
            <div class="eyebrow">{{ __('site.public.sections.visit') }}</div>
            <h2 class="public-section__title">{{ $site['site_name'] }}</h2>
            <div class="public-contact-list">
                @if (! empty($site['contact_phone']))
                    <div class="public-contact-list__item">
                        <span class="public-contact-list__label">{{ __('site.public.labels.phone') }}</span>
                        <span dir="ltr" class="inline-block text-left" style="unicode-bidi: isolate;">{{ $site['contact_phone'] }}</span>
                    </div>
                @endif
                @if (! empty($site['contact_email']))
                    <div class="public-contact-list__item">
                        <span class="public-contact-list__label">{{ __('site.public.labels.email') }}</span>
                        <span>{{ $site['contact_email'] }}</span>
                    </div>
                @endif
                @if ($siteAddress)
                    <div class="public-contact-list__item">
                        <span class="public-contact-list__label">{{ __('site.public.labels.address') }}</span>
                        <span>{{ $siteAddress }}</span>
                    </div>
                @endif
            </div>
            <div class="public-hero__actions">
                @if (! empty($site['maps_url']))
                    <a href="{{ $site['maps_url'] }}" target="_blank" class="pill-link">{{ __('site.public.cta.view_map') }}</a>
                @endif
                <a href="{{ route('login') }}" class="pill-link pill-link--accent">{{ __('site.public.nav.login') }}</a>
            </div>
        </section>
    </div>

    <section class="public-section">
        <div class="public-section__heading">
            <div>
                <div class="eyebrow">{{ __('site.public.sections.programs') }}</div>
                <h2 class="public-section__title">{{ $programs['title'] ?? __('site.public.sections.programs') }}</h2>
            </div>
            @if (! empty($programs['subtitle']))
                <p class="public-section__lede">{{ $programs['subtitle'] }}</p>
            @endif
        </div>

        <div class="public-program-layout">
            @if ($featuredProgram)
                <article class="public-program-feature">
                    <div class="eyebrow">{{ __('site.public.sections.programs') }}</div>
                    <h3 class="public-card__title mt-4">{{ $featuredProgram['title'] ?? '' }}</h3>
                    <p class="public-card__text">{{ $featuredProgram['summary'] ?? '' }}</p>
                    @if (! empty($featuredProgram['link_url']))
                        <a href="{{ $featuredProgram['link_url'] }}" class="pill-link pill-link--accent mt-6">{{ $featuredProgram['link_label'] ?? __('site.public.cta.open_page') }}</a>
                    @endif
                </article>
            @endif

            <div class="public-card-grid public-card-grid--stacked">
                @foreach ($secondaryPrograms as $card)
                    <article class="public-card">
                        <h3 class="public-card__title">{{ $card['title'] ?? '' }}</h3>
                        <p class="public-card__text">{{ $card['summary'] ?? '' }}</p>
                        @if (! empty($card['link_url']))
                            <a href="{{ $card['link_url'] }}" class="pill-link pill-link--compact mt-6">{{ $card['link_label'] ?? __('site.public.cta.open_page') }}</a>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="public-section">
        <div class="public-section__heading">
            <div>
                <div class="eyebrow">{{ __('site.public.sections.gallery') }}</div>
                <h2 class="public-section__title">{{ $site['site_name'] }}</h2>
            </div>
            <p class="public-section__lede">{{ $metaDescription }}</p>
        </div>

        <div class="public-gallery-showcase">
            <div class="public-gallery-slider" aria-label="{{ __('site.public.sections.gallery') }}">
                @foreach (($site['gallery_items'] ?? []) as $galleryItem)
                    <article class="public-gallery-slide">
                        <img src="{{ $galleryItem['url'] }}" alt="{{ $galleryItem['caption'] ?: $site['site_name'] }}" class="public-gallery-slide__image">
                        @if (! empty($galleryItem['caption']))
                            <div class="public-gallery-slide__caption">{{ $galleryItem['caption'] }}</div>
                        @endif
                    </article>
                @endforeach
            </div>

            @if ($navigationPages->isNotEmpty())
                <aside class="public-card-stack">
                    @foreach ($navigationPages as $navigationPage)
                        <article class="public-card">
                            <div class="eyebrow">{{ __('site.public.sections.latest_pages') }}</div>
                            <h3 class="public-card__title mt-4">{{ $navigationPage->localizedText('title') }}</h3>
                            <p class="public-card__text">{{ $navigationPage->localizedText('excerpt') }}</p>
                            <a href="{{ route('website.pages.show', $navigationPage) }}" class="pill-link pill-link--compact mt-6">{{ __('site.public.cta.open_page') }}</a>
                        </article>
                    @endforeach
                </aside>
            @endif
        </div>
    </section>
@endsection
