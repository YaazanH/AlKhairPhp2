@php
    $locale = app()->getLocale();
    $direction = config('app.supported_locales.'.$locale.'.direction', 'ltr');
    $oppositeLocale = $locale === 'ar' ? 'en' : 'ar';
    $defaultStoryImages = [
        asset('images/platform-landing/daily-overview.webp'),
        asset('images/platform-landing/learner-progress.webp'),
        asset('images/platform-landing/report-builder.webp'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $content['meta_description'] }}">
    <meta name="theme-color" content="#062d25">
    <title>{{ $content['meta_title'] }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="landing-body">
    <a class="landing-skip-link" href="#main-content">{{ __('landing.skip_to_content') }}</a>

    <header class="landing-header">
        <div class="landing-container landing-header__inner">
            <a href="{{ route('home') }}" class="landing-brand" aria-label="{{ $content['brand'] }}">
                <span class="landing-brand__mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>{{ $content['brand'] }}</span>
            </a>

            <nav class="landing-nav" aria-label="{{ __('landing.primary_navigation') }}">
                @foreach($content['section_order'] as $section)
                    @if($content['enabled_sections'][$section] ?? false)
                        <a href="#{{ $section }}">{{ __('landing.navigation.'.$section) }}</a>
                    @endif
                @endforeach
            </nav>

            <div class="landing-header__actions">
                <a class="landing-language" href="{{ route('locale.switch', $oppositeLocale) }}" lang="{{ $oppositeLocale }}" dir="{{ config('app.supported_locales.'.$oppositeLocale.'.direction') }}">
                    {{ $oppositeLocale === 'ar' ? 'العربية' : 'English' }}
                </a>
                <a class="landing-sign-in" href="{{ route('platform.login') }}">{{ __('landing.sign_in') }}</a>
                <a class="landing-button landing-button--small" href="#contact">{{ __('landing.request_demo') }}</a>
            </div>

            <details class="landing-mobile-nav">
                <summary aria-label="{{ __('landing.open_menu') }}"><span aria-hidden="true"><i></i><i></i><i></i></span></summary>
                <div>
                    @foreach($content['section_order'] as $section)
                        @if($content['enabled_sections'][$section] ?? false)
                            <a href="#{{ $section }}">{{ __('landing.navigation.'.$section) }}</a>
                        @endif
                    @endforeach
                    <a href="{{ route('platform.login') }}">{{ __('landing.sign_in') }}</a>
                </div>
            </details>
        </div>
    </header>

    <main id="main-content">
        <section class="landing-hero">
            <div class="landing-hero__glow landing-hero__glow--one"></div>
            <div class="landing-hero__glow landing-hero__glow--two"></div>
            <div class="landing-hero__grid-pattern" aria-hidden="true"></div>
            <div class="landing-container">
                <div class="landing-hero__grid">
                    <div class="landing-hero__copy">
                        <p class="landing-kicker"><span></span>{{ $content['hero_eyebrow'] }}</p>
                        <h1>{{ $content['hero_title'] }}</h1>
                        <p class="landing-lead">{{ $content['hero_body'] }}</p>
                        <div class="landing-hero__actions">
                            <a class="landing-button" href="#contact">{{ $content['hero_primary_label'] }} <span aria-hidden="true">→</span></a>
                            <a class="landing-text-link" href="#showcase">{{ __('landing.explore_platform') }} <span aria-hidden="true">↘</span></a>
                        </div>
                        <div class="landing-trust-row" aria-label="{{ __('landing.platform_qualities') }}">
                            <span><i></i>{{ __('landing.quality_private') }}</span>
                            <span><i></i>{{ __('landing.quality_bilingual') }}</span>
                            <span><i></i>{{ __('landing.quality_grows') }}</span>
                        </div>
                    </div>

                    <div class="landing-hero__visual" aria-label="{{ __('landing.platform_preview') }}">
                        <div class="landing-device landing-device--hero">
                            <div class="landing-device__bar"><span></span><span></span><span></span><b>{{ $content['brand'] }}</b></div>
                            <div class="landing-device__layout">
                                <aside><strong>◆</strong>@foreach(range(1, 6) as $item)<i></i>@endforeach</aside>
                                <div class="landing-device__canvas">
                                    <div class="landing-device__welcome">
                                        <div><small>{{ __('landing.preview_greeting') }}</small><strong>{{ __('landing.preview_priority') }}</strong></div>
                                        <span>12 {{ __('landing.preview_tasks') }}</span>
                                    </div>
                                    <div class="landing-device__stats">
                                        <article><small>{{ __('landing.preview_attendance') }}</small><em>92%</em><span>+4.6%</span></article>
                                        <article><small>{{ __('landing.preview_learners') }}</small><em>248</em><span>+12</span></article>
                                        <article><small>{{ __('landing.preview_groups') }}</small><em>18</em><span>+2</span></article>
                                    </div>
                                    <div class="landing-device__body">
                                        <div class="landing-device__chart">
                                            <div class="landing-device__chart-head"><span>{{ __('landing.preview_progress') }}</span><small>{{ __('landing.preview_period') }}</small></div>
                                            <div class="landing-device__bars">@foreach([38,55,48,72,66,86,78,94] as $height)<i style="--bar: {{ $height }}%"></i>@endforeach</div>
                                        </div>
                                        <div class="landing-device__attention"><small>{{ __('landing.preview_attention') }}</small>@foreach([82,65,48] as $progress)<i><span style="--progress: {{ $progress }}%"></span></i>@endforeach</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="landing-float-card landing-float-card--one"><span>✓</span><div><strong>{{ __('landing.preview_synced') }}</strong><small>{{ __('landing.preview_records') }}</small></div></div>
                        <div class="landing-float-card landing-float-card--two"><span>↗</span><div><strong>{{ __('landing.preview_insight') }}</strong><small>{{ __('landing.preview_act') }}</small></div></div>
                    </div>
                </div>

                <div class="landing-hero__proof">
                    <div><strong>01</strong><span>{{ __('landing.proof_workspace') }}</span></div>
                    <div><strong>02</strong><span>{{ __('landing.proof_modules') }}</span></div>
                    <div><strong>03</strong><span>{{ __('landing.proof_decisions') }}</span></div>
                </div>
            </div>
        </section>

        @foreach($content['section_order'] as $section)
            @continue(!($content['enabled_sections'][$section] ?? false))

            @if($section === 'features')
                <section id="features" class="landing-section landing-features">
                    <div class="landing-container">
                        <div class="landing-section-heading landing-section-heading--split">
                            <div><p class="landing-kicker"><span></span>{{ $content['features_kicker'] }}</p><h2>{{ $content['features_title'] }}</h2></div>
                            <p>{{ $content['features_body'] }}</p>
                        </div>
                        <div class="landing-feature-grid">
                            @foreach($content['feature_items'] as $index => $feature)
                                <article class="landing-feature-card landing-feature-card--{{ $index + 1 }}">
                                    <span class="landing-feature-card__number">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                    <div class="landing-feature-card__icon" aria-hidden="true">@if($index === 0)◎@elseif($index === 1)⌘@else↗@endif</div>
                                    <h3>{{ $feature['title'] }}</h3>
                                    <p>{{ $feature['body'] }}</p>
                                    <div class="landing-feature-card__line" aria-hidden="true"></div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </section>

            @elseif($section === 'showcase')
                <section id="showcase" class="landing-section landing-showcase">
                    <div class="landing-container">
                        <div class="landing-section-heading landing-section-heading--light landing-section-heading--split">
                            <div><p class="landing-kicker"><span></span>{{ $content['showcase_kicker'] }}</p><h2>{{ $content['showcase_title'] }}</h2></div>
                            <p>{{ $content['showcase_body'] }}</p>
                        </div>
                        <div class="landing-story" data-landing-story>
                            <div class="landing-story__steps" role="tablist" aria-label="{{ $content['showcase_title'] }}">
                                @foreach($content['showcase_items'] as $index => $item)
                                    <button type="button" role="tab" aria-selected="{{ $index === 0 ? 'true' : 'false' }}" class="landing-story__step {{ $index === 0 ? 'is-active' : '' }}" data-landing-story-step="{{ $index }}">
                                        <span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                        <span><strong>{{ $item['title'] }}</strong><small>{{ $item['body'] }}</small></span>
                                        <i aria-hidden="true">→</i>
                                    </button>
                                @endforeach
                            </div>
                            <div class="landing-story__screen">
                                @foreach($content['showcase_items'] as $index => $item)
                                    @php
                                        $storyImage = $item['image_path']
                                            ? route('platform-site.media', ['path' => $item['image_path']])
                                            : $defaultStoryImages[$index % count($defaultStoryImages)];
                                    @endphp
                                    <figure role="tabpanel" class="landing-story__image {{ $index === 0 ? 'is-active' : '' }}" data-landing-story-image="{{ $index }}" aria-hidden="{{ $index === 0 ? 'false' : 'true' }}">
                                        <img src="{{ $storyImage }}" alt="{{ $item['title'] }}" loading="{{ $index === 0 ? 'eager' : 'lazy' }}">
                                        <figcaption><span>{{ __('landing.product_view') }} {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><strong>{{ $item['title'] }}</strong></figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>

            @elseif($section === 'packages')
                <section id="packages" class="landing-section landing-packages">
                    <div class="landing-container">
                        <div class="landing-section-heading landing-section-heading--split">
                            <div><p class="landing-kicker"><span></span>{{ $content['packages_kicker'] }}</p><h2>{{ $content['packages_title'] }}</h2></div>
                            <p>{{ $content['packages_body'] }}</p>
                        </div>
                        <div class="landing-package-grid">
                            @forelse($plans as $plan)
                                @php
                                    $isFeatured = $plans->count() > 1 && $loop->iteration === 2;
                                @endphp
                                <article class="landing-package-card {{ $isFeatured ? 'landing-package-card--featured' : '' }}">
                                    <div class="landing-package-card__topline">
                                        <span class="landing-package-card__code">{{ $plan->code }}</span>
                                        @if($isFeatured)<span class="landing-package-card__badge">{{ __('landing.recommended') }}</span>@endif
                                    </div>
                                    <h3>{{ $plan->name }}</h3>
                                    <p class="landing-package-card__description">{{ $plan->description ?: __('landing.package_default_description') }}</p>
                                    <div class="landing-package-card__price">
                                        @if($plan->price_syp > 0)
                                            <small>{{ __('landing.package_from') }}</small>
                                            <strong>{{ number_format($plan->price_syp) }} <em>SYP</em></strong>
                                            <span>{{ __('landing.per_period', ['days' => $plan->billing_period_days]) }}</span>
                                        @else
                                            <small>{{ __('landing.pricing') }}</small>
                                            <strong>{{ __('landing.price_on_request') }}</strong>
                                            <span>{{ __('landing.contact_for_terms') }}</span>
                                        @endif
                                    </div>
                                    <div class="landing-package-card__facts">
                                        <span><b>{{ $plan->features->count() }}</b>{{ trans_choice('landing.capabilities_count', $plan->features->count(), ['count' => $plan->features->count()]) }}</span>
                                    </div>
                                    <ul class="landing-package-card__highlights">
                                        @foreach($plan->features->take(4) as $feature)<li><span>✓</span>{{ $feature->name }}</li>@endforeach
                                    </ul>
                                    <details class="landing-package-details">
                                        <summary><span>{{ __('landing.view_package_details') }}</span><i aria-hidden="true"></i></summary>
                                        <div>
                                            <h4>{{ __('landing.included_capabilities') }}</h4>
                                            <ul>
                                                @forelse($plan->features as $feature)<li><span>✓</span>{{ $feature->name }}</li>@empty<li>{{ __('landing.no_capabilities_listed') }}</li>@endforelse
                                            </ul>
                                            <dl>
                                                <div><dt>{{ __('landing.billing_cycle') }}</dt><dd>{{ trans_choice('landing.days_count', $plan->billing_period_days, ['count' => $plan->billing_period_days]) }}</dd></div>
                                            </dl>
                                        </div>
                                    </details>
                                    <a class="landing-package-card__cta" href="#contact">{{ __('landing.request_demo') }} <span aria-hidden="true">→</span></a>
                                </article>
                            @empty
                                <div class="landing-package-empty"><strong>{{ __('landing.packages_coming') }}</strong><p>{{ __('landing.packages_coming_body') }}</p><a href="#contact">{{ __('landing.contact_us') }}</a></div>
                            @endforelse
                        </div>
                    </div>
                </section>

            @elseif($section === 'faq')
                <section id="faq" class="landing-section landing-faq">
                    <div class="landing-container landing-faq__grid">
                        <div class="landing-section-heading"><p class="landing-kicker"><span></span>{{ $content['faq_kicker'] }}</p><h2>{{ $content['faq_title'] }}</h2><p>{{ __('landing.faq_support') }}</p></div>
                        <div class="landing-faq__items">
                            @foreach($content['faq_items'] as $item)<details @if($loop->first) open @endif><summary>{{ $item['question'] }}<span></span></summary><p>{{ $item['answer'] }}</p></details>@endforeach
                        </div>
                    </div>
                </section>

            @elseif($section === 'contact')
                <section id="contact" class="landing-section landing-contact">
                    <div class="landing-container landing-contact__grid">
                        <div class="landing-contact__copy">
                            <p class="landing-kicker landing-kicker--light"><span></span>{{ $content['contact_kicker'] }}</p>
                            <h2>{{ $content['contact_title'] }}</h2>
                            <p>{{ $content['contact_body'] }}</p>
                            <div class="landing-contact__promise"><span>✓</span>{{ __('landing.contact_promise') }}</div>
                            <div class="landing-contact__steps"><span><b>1</b>{{ __('landing.contact_step_one') }}</span><span><b>2</b>{{ __('landing.contact_step_two') }}</span><span><b>3</b>{{ __('landing.contact_step_three') }}</span></div>
                        </div>
                        <form method="POST" action="{{ route('platform-site.enquire') }}" class="landing-contact__form">@csrf
                            <div class="landing-contact__form-heading"><span>{{ __('landing.demo_request') }}</span><strong>{{ __('landing.demo_request_body') }}</strong></div>
                            @if(session('landing_status'))<div class="landing-form-success" role="status">{{ session('landing_status') }}</div>@endif
                            @if($errors->any())<div class="landing-form-error" role="alert">{{ $errors->first() }}</div>@endif
                            <div class="landing-form-row"><label>{{ __('landing.contact.name') }}<input name="name" value="{{ old('name') }}" required maxlength="120"></label><label>{{ __('landing.contact.organisation') }}<input name="organisation_name" value="{{ old('organisation_name') }}" required maxlength="180"></label></div>
                            <div class="landing-form-row"><label>{{ __('landing.contact.email') }}<input type="email" name="email" value="{{ old('email') }}" required maxlength="190"></label><label>{{ __('landing.contact.phone') }}<input name="phone" value="{{ old('phone') }}" maxlength="40"></label></div>
                            <label>{{ __('landing.contact.message') }}<textarea name="message" rows="4" maxlength="3000">{{ old('message') }}</textarea></label>
                            <div class="landing-form-footer"><small>{{ __('landing.contact.privacy') }}</small><button type="submit">{{ __('landing.contact.submit') }} <span aria-hidden="true">→</span></button></div>
                        </form>
                    </div>
                </section>
            @endif
        @endforeach
    </main>

    <footer class="landing-footer">
        <div class="landing-container">
            <a class="landing-brand" href="{{ route('home') }}"><span class="landing-brand__mark"><span></span><span></span><span></span></span><span>{{ $content['brand'] }}</span></a>
            <p>{{ __('landing.footer') }}</p>
            <div><a href="{{ route('platform.login') }}">{{ __('landing.sign_in') }}</a><a href="{{ route('locale.switch', $oppositeLocale) }}">{{ $oppositeLocale === 'ar' ? 'العربية' : 'English' }}</a></div>
        </div>
    </footer>
</body>
</html>
