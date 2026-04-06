@extends('public.layout')

@section('content')
    @php
        $heroUrl = $page->hero_media_path ? asset('storage/'.ltrim($page->hero_media_path, '/')) : null;
        $sections = $page->localizedSections();
    @endphp

    <section class="public-page-hero">
        <div class="public-page-hero__copy">
            <div class="eyebrow">{{ $site['site_name'] }}</div>
            <h1 class="public-page-hero__title">{{ $page->localizedText('title') }}</h1>

            @if ($page->localizedText('excerpt'))
                <p class="public-page-hero__subtitle">{{ $page->localizedText('excerpt') }}</p>
            @endif
        </div>

        @if ($heroUrl)
            <div class="public-page-hero__media">
                <img src="{{ $heroUrl }}" alt="{{ $page->localizedText('title') }}" class="public-page-hero__image">
            </div>
        @endif
    </section>

    @if ($sections !== [])
        @foreach ($sections as $section)
            @php
                $type = (string) data_get($section, 'type', 'rich_text');
                $heading = (string) data_get($section, 'heading', '');
                $body = (string) data_get($section, 'body', '');
                $secondary = (string) data_get($section, 'secondary', '');
                $buttonLabel = (string) data_get($section, 'button_label', '');
                $buttonUrl = (string) data_get($section, 'button_url', '');
                $embedCode = (string) data_get($section, 'embed_code', '');
                $customHtml = (string) data_get($section, 'custom_html', '');
            @endphp

            @continue(blank($heading) && blank($body) && blank($secondary) && blank($buttonLabel) && blank($buttonUrl) && blank($embedCode) && blank($customHtml))

            @switch($type)
                @case('two_columns')
                    <section class="public-section">
                        @if ($heading)
                            <div class="public-section__heading">
                                <h2 class="public-section__title">{{ $heading }}</h2>
                            </div>
                        @endif

                        <div class="public-columns-grid mt-6">
                            @if ($body)
                                <article class="public-card">
                                    <div class="public-card__text">{!! nl2br(e($body)) !!}</div>
                                </article>
                            @endif

                            @if ($secondary)
                                <article class="public-card">
                                    <div class="public-card__text">{!! nl2br(e($secondary)) !!}</div>
                                </article>
                            @endif
                        </div>
                    </section>
                @break

                @case('cta')
                    <section class="public-section">
                        @if ($heading)
                            <div class="public-section__heading">
                                <h2 class="public-section__title">{{ $heading }}</h2>
                            </div>
                        @endif

                        @if ($body)
                            <div class="public-section__text">{!! nl2br(e($body)) !!}</div>
                        @endif

                        @if ($buttonLabel && $buttonUrl)
                            <div class="public-section__actions public-hero__actions">
                                <a href="{{ $buttonUrl }}" class="pill-link pill-link--accent">{{ $buttonLabel }}</a>
                            </div>
                        @endif
                    </section>
                @break

                @case('map')
                    <section class="public-section">
                        @if ($heading)
                            <div class="public-section__heading">
                                <h2 class="public-section__title">{{ $heading }}</h2>
                            </div>
                        @endif

                        @if ($body)
                            <div class="public-section__text">{!! nl2br(e($body)) !!}</div>
                        @endif

                        @if ($embedCode)
                            <div class="public-embed-frame mt-6">{!! $embedCode !!}</div>
                        @endif

                        @if ($buttonLabel && $buttonUrl)
                            <div class="public-section__actions public-hero__actions">
                                <a href="{{ $buttonUrl }}" class="pill-link pill-link--accent" target="_blank" rel="noreferrer">{{ $buttonLabel }}</a>
                            </div>
                        @endif
                    </section>
                @break

                @case('embed')
                    <section class="public-section">
                        @if ($heading)
                            <div class="public-section__heading">
                                <h2 class="public-section__title">{{ $heading }}</h2>
                            </div>
                        @endif

                        @if ($embedCode)
                            <div class="public-embed-frame mt-6">{!! $embedCode !!}</div>
                        @endif
                    </section>
                @break

                @case('custom_html')
                    <section class="public-section">
                        @if ($heading)
                            <div class="public-section__heading">
                                <h2 class="public-section__title">{{ $heading }}</h2>
                            </div>
                        @endif

                        <article class="public-reading-surface">{!! $customHtml !!}</article>
                    </section>
                @break

                @default
                    <section class="public-section">
                        @if ($heading)
                            <div class="public-section__heading">
                                <h2 class="public-section__title">{{ $heading }}</h2>
                            </div>
                        @endif

                        <article class="public-reading-surface">{!! nl2br(e($body)) !!}</article>
                    </section>
            @endswitch
        @endforeach
    @else
        <section class="public-section">
            <article class="public-reading-surface">{!! nl2br(e($page->localizedText('body'))) !!}</article>
        </section>
    @endif
@endsection
