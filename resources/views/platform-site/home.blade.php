@php
    $locale = app()->getLocale();
    $direction = config('app.supported_locales.'.$locale.'.direction', 'ltr');
    $oppositeLocale = $locale === 'ar' ? 'en' : 'ar';
    $branding = app(\App\Support\BrandIdentity::class);
    $socialLinks = collect($content['social_links'] ?? [])->filter(fn ($url) => filled($url));
    $defaultStoryImages = [
        'A useful daily overview' => asset('images/platform-landing/daily-overview-v2.webp'),
        'Progress with context' => asset('images/platform-landing/learner-progress-v2.webp'),
        'Revenue and finance in one view' => asset('images/platform-landing/revenue-finance-v2.webp'),
        'Parent and student portals' => asset('images/platform-landing/parent-student-portal-v2.webp'),
        'Reports shaped for decisions' => asset('images/platform-landing/report-builder-v2.webp'),
    ];
    $capabilityCatalog = __('landing.public_capabilities');
    $storyCapabilities = [
        'A useful daily overview' => ['student_attendance', 'classes', 'assessments'],
        'Progress with context' => ['students', 'memorization', 'points_rewards'],
        'Revenue and finance in one view' => ['finance_income', 'finance_expenses', 'finance_reports'],
        'Parent and student portals' => ['students', 'parents', 'parent_portal'],
        'Reports shaped for decisions' => ['assessments', 'finance_reports', 'printing_documents'],
    ];
    $expandPublicCapability = function ($feature) use ($capabilityCatalog) {
        $codes = match ($feature->code) {
            'core' => ['students', 'teachers', 'parents', 'parent_portal', 'classes', 'student_attendance', 'teacher_attendance', 'memorization', 'quran_tests', 'assessments', 'points_rewards', 'activities', 'curriculum', 'public_website'],
            'finance' => ['finance_income', 'finance_expenses', 'finance_payments', 'finance_cash', 'finance_approvals', 'finance_reports'],
            'custom_printing' => ['printing_templates', 'printing_documents', 'printing_branding'],
            default => [$feature->code],
        };

        return collect($codes)->map(function ($code) use ($capabilityCatalog, $feature) {
            $copy = $capabilityCatalog[$code] ?? ['title' => $feature->name, 'description' => $feature->description];

            return ['key' => $code, 'title' => $copy['title'], 'description' => $copy['description'] ?? null];
        });
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $content['meta_description'] }}">
    <meta name="theme-color" content="#f4efe5">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $branding->platformName() }}">
    <meta property="og:title" content="{{ $content['meta_title'] }}">
    <meta property="og:description" content="{{ $content['meta_description'] }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $branding->platformSocialImageUrl() }}">
    <meta property="og:image:secure_url" content="{{ $branding->platformSocialImageUrl() }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="2400">
    <meta property="og:image:height" content="1260">
    <meta property="og:image:alt" content="{{ $branding->platformName() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $content['meta_title'] }}">
    <meta name="twitter:description" content="{{ $content['meta_description'] }}">
    <meta name="twitter:image" content="{{ $branding->platformSocialImageUrl() }}">
    <meta name="twitter:image:alt" content="{{ $branding->platformName() }}">
    <title>{{ $content['meta_title'] }}</title>
    <link rel="icon" href="{{ $branding->platformIconUrl() }}">
    <link rel="shortcut icon" href="{{ $branding->platformIconUrl() }}">
    <link rel="apple-touch-icon" href="{{ $branding->platformIconUrl() }}">
    @vite(['resources/css/app.css', 'resources/css/platform-landing.css', 'resources/js/platform-landing.js'])
</head>
<body class="landing-body landing-body--studio">
    @if($preview ?? false)
        <aside class="landing-private-preview" role="status"><strong>{{ isset($previewRevision) ? __('platform-content.preview_revision_banner', ['version' => $previewRevision]) : __('platform-content.preview_banner') }}</strong><a href="{{ route('platform.landing.edit', ['view' => 'publishing']) }}">{{ __('platform-content.back_studio') }}</a></aside>
    @endif
    <a class="landing-skip-link" href="#main-content">{{ __('landing.skip_to_content') }}</a>

    <header class="landing-header">
        <div class="landing-container landing-header__inner">
            <a href="{{ route('home') }}" class="landing-brand" aria-label="{{ $content['brand'] }}">
                <x-platform-brand variant="icon" class="landing-brand__mark" />
                <span class="landing-brand__wordmark">{{ $content['brand'] }}</span>
            </a>

            <nav class="landing-nav" aria-label="{{ __('landing.primary_navigation') }}">
                @foreach($content['section_order'] as $section)
                    @if($content['enabled_sections'][$section] ?? false)
                        <a href="#{{ $section }}">{{ __('landing.navigation.'.$section) }}</a>
                    @endif
                @endforeach
            </nav>

            <div class="landing-header__actions">
                <a class="landing-language" href="{{ ($preview ?? false) ? route('platform.landing.preview', ['language' => $oppositeLocale, 'revision' => request('revision')]) : route('locale.switch', $oppositeLocale) }}" lang="{{ $oppositeLocale }}" dir="{{ config('app.supported_locales.'.$oppositeLocale.'.direction') }}">
                    {{ $oppositeLocale === 'ar' ? 'العربية' : 'English' }}
                </a>
                <a class="landing-sign-in" href="{{ route('platform.login') }}">{{ __('landing.sign_in') }}</a>
                <a class="landing-button landing-button--small" href="#contact"><span>{{ __('landing.request_demo') }}</span><i aria-hidden="true">↗</i></a>
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

    <main id="main-content" tabindex="-1">
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
                            <a class="landing-button" href="#contact"><span>{{ $content['hero_primary_label'] }}</span><i aria-hidden="true">↗</i></a>
                            <a class="landing-text-link" href="#showcase">{{ __('landing.explore_platform') }} <span aria-hidden="true">↘</span></a>
                        </div>
                        <div class="landing-trust-row" aria-label="{{ __('landing.platform_qualities') }}">
                            <span><i></i>{{ __('landing.quality_private') }}</span>
                            <span><i></i>{{ __('landing.quality_bilingual') }}</span>
                            <span><i></i>{{ __('landing.quality_grows') }}</span>
                        </div>
                    </div>

                    <div class="landing-hero__visual" aria-label="{{ __('landing.platform_preview') }}">
                        <span class="landing-preview-label">{{ __('landing.illustrative_preview') }}</span>
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
                                    <div class="landing-feature-card__icon" aria-hidden="true">
                                        @if($index === 0)
                                            <svg viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="10"/><circle cx="16" cy="16" r="3"/><path d="M16 2v4M16 26v4M2 16h4M26 16h4"/></svg>
                                        @elseif($index === 1)
                                            <svg viewBox="0 0 32 32" fill="none"><rect x="4" y="5" width="10" height="9" rx="2"/><rect x="18" y="5" width="10" height="9" rx="2"/><rect x="4" y="18" width="10" height="9" rx="2"/><rect x="18" y="18" width="10" height="9" rx="2"/></svg>
                                        @elseif($index === 2)
                                            <svg viewBox="0 0 32 32" fill="none"><ellipse cx="16" cy="8" rx="9" ry="4"/><path d="M7 8v8c0 2.2 4 4 9 4s9-1.8 9-4V8M7 16v8c0 2.2 4 4 9 4s9-1.8 9-4v-8"/></svg>
                                        @else
                                            <svg viewBox="0 0 32 32" fill="none"><path d="M5 25 13 17l5 5 9-12"/><path d="M20 10h7v7"/></svg>
                                        @endif
                                    </div>
                                    <div class="landing-feature-visual landing-feature-visual--{{ $index % 4 }}" aria-hidden="true">
                                        @if($index % 4 === 0)
                                            <div class="landing-mini-profile"><i></i><span>{{ __('landing.preview_learners') }}<b></b></span><em>✓</em></div>
                                            <div class="landing-mini-track"><span>{{ __('landing.preview_progress') }}</span><div>@foreach([46, 63, 78, 91] as $progress)<i style="--value: {{ $progress }}%"></i>@endforeach</div></div>
                                        @elseif($index % 4 === 1)
                                            <div class="landing-mini-connections"><span>{{ $capabilityCatalog['students']['title'] }}</span><i></i><strong>{{ $content['brand'] }}</strong><i></i><span>{{ $capabilityCatalog['teachers']['title'] }}</span></div>
                                            <div class="landing-mini-tags"><span>{{ $capabilityCatalog['classes']['title'] }}</span><span>{{ $capabilityCatalog['parents']['title'] }}</span></div>
                                        @elseif($index % 4 === 2)
                                            <div class="landing-mini-finance"><span>{{ $capabilityCatalog['finance_income']['title'] }}<i>↗</i></span><div>@foreach([32, 47, 39, 64, 56, 78, 91] as $bar)<b style="--value: {{ $bar }}%"></b>@endforeach</div></div>
                                        @else
                                            <div class="landing-mini-report"><span>{{ $capabilityCatalog['finance_reports']['title'] }}</span>@foreach([86, 64, 74] as $bar)<div><i></i><b style="--value: {{ $bar }}%"></b><em></em></div>@endforeach</div>
                                        @endif
                                    </div>
                                    <h3>{{ $feature['title'] }}</h3>
                                    <p>{{ $feature['body'] }}</p>
                                    <div class="landing-feature-card__line" aria-hidden="true"></div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </section>

            @elseif($section === 'showcase')
                <section id="showcase" class="landing-section landing-showcase landing-showcase--gallery">
                    <div class="landing-container">
                        <div class="landing-section-heading landing-section-heading--light landing-section-heading--gallery">
                            <div><p class="landing-kicker"><span></span>{{ $content['showcase_kicker'] }}</p><h2>{{ $content['showcase_title'] }}</h2></div>
                            <p>{{ $content['showcase_body'] }}</p>
                        </div>
                        <div class="landing-story" data-landing-story>
                            <div class="landing-story__steps" role="tablist" aria-label="{{ $content['showcase_title'] }}">
                                @foreach($content['showcase_items'] as $index => $item)
                                    <button type="button" role="tab" id="landing-story-tab-{{ $index }}" aria-controls="landing-story-panel-{{ $index }}" aria-selected="{{ $index === 0 ? 'true' : 'false' }}" tabindex="{{ $index === 0 ? '0' : '-1' }}" class="landing-story__step {{ $index === 0 ? 'is-active' : '' }}" data-landing-story-step="{{ $index }}">
                                        <span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                        <strong>{{ $item['title'] }}</strong>
                                    </button>
                                @endforeach
                            </div>
                            <div class="landing-story__canvas">
                            <div class="landing-story__stage" data-landing-story-stage style="--story-progress: {{ 100 / max(count($content['showcase_items']), 1) }}%">
                                <div class="landing-story__window-bar" aria-hidden="true">
                                    <span></span><span></span><span></span>
                                    <b>{{ $content['brand'] }}</b>
                                    <em data-landing-story-counter>01 / {{ str_pad((string) count($content['showcase_items']), 2, '0', STR_PAD_LEFT) }}</em>
                                </div>
                                <div class="landing-story__screen">
                                    @foreach($content['showcase_items'] as $index => $item)
                                        @php
                                            $storyKey = data_get($rawContent, "showcase_items.{$index}.title.en");
                                            $storyImage = $item['image_path']
                                                ? route('platform-site.media', ['path' => $item['image_path']])
                                                : ($defaultStoryImages[$storyKey] ?? array_values($defaultStoryImages)[$index % count($defaultStoryImages)]);
                                        @endphp
                                        <figure role="tabpanel" tabindex="{{ $index === 0 ? '0' : '-1' }}" id="landing-story-panel-{{ $index }}" aria-labelledby="landing-story-tab-{{ $index }}" class="landing-story__image {{ $index === 0 ? 'is-active' : '' }}" data-landing-story-image="{{ $index }}" aria-hidden="{{ $index === 0 ? 'false' : 'true' }}">
                                            <img src="{{ $storyImage }}" alt="{{ $item['title'] }}" loading="lazy" decoding="async">
                                        </figure>
                                    @endforeach
                                </div>
                                <div class="landing-story__progress" aria-hidden="true"><span></span></div>
                            </div>
                            <div class="landing-story__summary" aria-live="polite">
                                @foreach($content['showcase_items'] as $index => $item)
                                    <article class="{{ $index === 0 ? 'is-active' : '' }}" data-landing-story-copy="{{ $index }}" aria-hidden="{{ $index === 0 ? 'false' : 'true' }}">
                                        <span>{{ __('landing.product_view') }} {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                        <h3>{{ $item['title'] }}</h3>
                                        <p>{{ $item['body'] }}</p>
                                        @if(isset($storyCapabilities[data_get($rawContent, "showcase_items.{$index}.title.en")]))
                                            <ul class="landing-story__capabilities">
                                                @foreach($storyCapabilities[data_get($rawContent, "showcase_items.{$index}.title.en")] as $code)
                                                    <li><span aria-hidden="true">✓</span>{{ $capabilityCatalog[$code]['title'] }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        <i aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</i>
                                    </article>
                                @endforeach
                            </div>
                            </div>
                            <div class="landing-story__toolbar">
                                <p>{{ __('landing.showcase_preview_note') }}</p>
                                <div>
                                    <button type="button" class="landing-story__expand" data-landing-story-expand aria-haspopup="dialog">{{ __('landing.enlarge_preview') }} <span aria-hidden="true">⤢</span></button>
                                    <button type="button" data-landing-story-previous aria-label="{{ __('landing.previous_view') }}"><span aria-hidden="true">←</span></button>
                                    <button type="button" data-landing-story-next aria-label="{{ __('landing.next_view') }}"><span aria-hidden="true">→</span></button>
                                </div>
                            </div>
                            <dialog class="landing-preview-dialog" data-landing-preview-dialog aria-labelledby="landing-preview-title">
                                <div class="landing-preview-dialog__header"><div><span>{{ __('landing.product_view') }}</span><h3 id="landing-preview-title" data-landing-preview-title></h3></div><div class="landing-preview-dialog__actions"><button type="button" class="landing-preview-dialog__zoom" data-landing-preview-zoom data-zoom-label="{{ __('landing.zoom_preview') }}" data-fit-label="{{ __('landing.fit_preview') }}" aria-pressed="false">{{ __('landing.zoom_preview') }}</button><button type="button" data-landing-preview-close aria-label="{{ __('landing.close_preview') }}" autofocus>×</button></div></div>
                                <div class="landing-preview-dialog__image" data-landing-preview-viewport role="region" aria-label="{{ __('landing.preview_pan_help') }}" tabindex="-1"><img data-landing-preview-image alt=""></div>
                                <div class="landing-preview-dialog__footer"><p data-landing-preview-description></p><div><button type="button" data-landing-preview-previous aria-label="{{ __('landing.previous_view') }}">←</button><span data-landing-preview-counter></span><button type="button" data-landing-preview-next aria-label="{{ __('landing.next_view') }}">→</button></div></div>
                            </dialog>
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
                        @php
                            $featuredPackageIndex = $plans->count() > 1 ? 1 : 0;
                            $packageRows = $plans->values()->map(function ($plan) use ($expandPublicCapability) {
                                $capabilities = $plan->features->flatMap($expandPublicCapability)->unique('key')->values();
                                $highlights = $plan->features
                                    ->flatMap(fn ($feature) => $expandPublicCapability($feature)->take(2))
                                    ->unique('key')
                                    ->take(6)
                                    ->values();
                                $highlights = $highlights
                                    ->concat($capabilities->whereNotIn('key', $highlights->pluck('key'))->take(6 - $highlights->count()))
                                    ->values();

                                return compact('plan', 'capabilities', 'highlights');
                            });
                        @endphp
                        @if($packageRows->isNotEmpty())
                            <div class="landing-package-explorer" data-landing-packages>
                                <div class="landing-package-selector" role="tablist" aria-label="{{ __('landing.select_package') }}">
                                    @foreach($packageRows as $index => $packageRow)
                                        <button type="button" role="tab" id="landing-package-tab-{{ $index }}" aria-controls="landing-package-panel-{{ $index }}" aria-selected="{{ $index === $featuredPackageIndex ? 'true' : 'false' }}" tabindex="{{ $index === $featuredPackageIndex ? '0' : '-1' }}" class="landing-package-selector__option {{ $index === $featuredPackageIndex ? 'is-active' : '' }}" data-landing-package-tab="{{ $index }}">
                                            <span>{{ __('landing.package_label', ['number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)]) }}</span>
                                            <strong>{{ $packageRow['plan']->name }}</strong>
                                            <small><b>{{ $packageRow['capabilities']->count() }}</b> {{ trans_choice('landing.capabilities_count', $packageRow['capabilities']->count(), ['count' => $packageRow['capabilities']->count()]) }}</small>
                                            @if($index === $featuredPackageIndex)<em>{{ __('landing.recommended') }}</em>@endif
                                        </button>
                                    @endforeach
                                </div>

                                <div class="landing-package-panels">
                                    @foreach($packageRows as $index => $packageRow)
                                        @php
                                            $plan = $packageRow['plan'];
                                            $planCapabilities = $packageRow['capabilities'];
                                            $highlightCapabilities = $packageRow['highlights'];
                                            $isFeatured = $index === $featuredPackageIndex;
                                        @endphp
                                        <article role="tabpanel" tabindex="{{ $isFeatured ? '0' : '-1' }}" id="landing-package-panel-{{ $index }}" aria-labelledby="landing-package-tab-{{ $index }}" aria-hidden="{{ $isFeatured ? 'false' : 'true' }}" class="landing-package-card landing-package-card--focused {{ $isFeatured ? 'is-active' : '' }}" data-landing-package-panel="{{ $index }}">
                                            <div class="landing-package-card__intro">
                                                <div class="landing-package-card__topline">
                                                    <span class="landing-package-card__code">{{ __('landing.package_label', ['number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)]) }}</span>
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
                                                    <span><b>{{ $planCapabilities->count() }}</b>{{ trans_choice('landing.capabilities_count', $planCapabilities->count(), ['count' => $planCapabilities->count()]) }}</span>
                                                </div>
                                                <a class="landing-package-card__cta" href="#contact"><span>{{ __('landing.request_demo') }}</span><i aria-hidden="true">↗</i></a>
                                            </div>
                                            <div class="landing-package-card__capabilities">
                                                <span class="landing-package-card__capabilities-label">{{ __('landing.package_highlights') }}</span>
                                                <ul class="landing-package-card__highlights">
                                                    @foreach($highlightCapabilities as $capability)
                                                        <li><span aria-hidden="true">✓</span><div><strong>{{ $capability['title'] }}</strong>@if($capability['description'])<small>{{ $capability['description'] }}</small>@endif</div></li>
                                                    @endforeach
                                                </ul>
                                                <details class="landing-package-details">
                                                    <summary><span>{{ __('landing.view_package_details') }}</span><i aria-hidden="true"></i></summary>
                                                    <div>
                                                        <h4>{{ __('landing.included_capabilities') }}</h4>
                                                        <ul>
                                                            @forelse($planCapabilities as $capability)
                                                                <li><span aria-hidden="true">✓</span><div><strong>{{ $capability['title'] }}</strong>@if($capability['description'])<small>{{ $capability['description'] }}</small>@endif</div></li>
                                                            @empty<li>{{ __('landing.no_capabilities_listed') }}</li>@endforelse
                                                        </ul>
                                                        <dl>
                                                            <div><dt>{{ __('landing.billing_cycle') }}</dt><dd>{{ trans_choice('landing.days_count', $plan->billing_period_days, ['count' => $plan->billing_period_days]) }}</dd></div>
                                                        </dl>
                                                    </div>
                                                </details>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="landing-package-empty"><strong>{{ __('landing.packages_coming') }}</strong><p>{{ __('landing.packages_coming_body') }}</p><a href="#contact">{{ __('landing.contact_us') }}</a></div>
                        @endif
                    </div>
                </section>

            @elseif($section === 'faq')
                <section id="faq" class="landing-section landing-faq">
                    <div class="landing-container landing-faq__grid">
                        <div class="landing-faq__intro">
                            <div class="landing-section-heading"><p class="landing-kicker"><span></span>{{ $content['faq_kicker'] }}</p><h2>{{ $content['faq_title'] }}</h2><p>{{ __('landing.faq_support') }}</p></div>
                            <a class="landing-faq__cta" href="#contact">
                                <span>{{ __('landing.faq_cta_kicker') }}</span>
                                <strong>{{ __('landing.faq_cta_title') }}</strong>
                                <small>{{ __('landing.faq_cta_body') }}</small>
                                <i aria-hidden="true">↘</i>
                            </a>
                        </div>
                        <div class="landing-faq__items" data-landing-faq>
                            @foreach($content['faq_items'] as $item)
                                <details @if($loop->first) open @endif>
                                    <summary><b>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</b><strong>{{ $item['question'] }}</strong><span aria-hidden="true"></span></summary>
                                    <p>{{ $item['answer'] }}</p>
                                </details>
                            @endforeach
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
                            <div class="landing-contact__steps" aria-label="{{ __('landing.contact_steps_label') }}">
                                <div><b>01</b><span>{{ __('landing.contact_step_one') }}</span></div>
                                <div><b>02</b><span>{{ __('landing.contact_step_two') }}</span></div>
                                <div><b>03</b><span>{{ __('landing.contact_step_three') }}</span></div>
                            </div>
                        </div>
                        <form method="{{ ($preview ?? false) ? 'GET' : 'POST' }}" action="{{ ($preview ?? false) ? '#' : route('platform-site.enquire') }}" class="landing-contact__form" @if($preview ?? false) onsubmit="return false" @endif>@csrf
                            @if($preview ?? false)<fieldset disabled class="landing-private-preview-fields">@endif
                            <div class="landing-contact__form-heading"><span>{{ __('landing.demo_request') }}</span><strong>{{ __('landing.demo_request_body') }}</strong></div>
                            @if(session('landing_status'))<div class="landing-form-success" role="status">{{ session('landing_status') }}</div>@endif
                            @if($errors->any())<div class="landing-form-error" role="alert">{{ $errors->first() }}</div>@endif
                            <div class="landing-form-row"><label>{{ __('landing.contact.name') }}<input name="name" autocomplete="name" value="{{ old('name') }}" required maxlength="120"></label><label>{{ __('landing.contact.organisation') }}<input name="organisation_name" autocomplete="organization" value="{{ old('organisation_name') }}" required maxlength="180"></label></div>
                            <div class="landing-form-row"><label>{{ __('landing.contact.email') }}<input type="email" name="email" autocomplete="email" dir="ltr" value="{{ old('email') }}" required maxlength="190"></label><label>{{ __('landing.contact.phone') }}<input type="tel" name="phone" autocomplete="tel" dir="ltr" value="{{ old('phone') }}" maxlength="40"></label></div>
                            <label>{{ __('landing.contact.message') }}<textarea name="message" rows="4" maxlength="3000">{{ old('message') }}</textarea></label>
                            <div class="landing-form-footer"><small>{{ __('landing.contact.privacy') }}</small><button type="submit"><span>{{ __('landing.contact.submit') }}</span><i aria-hidden="true">↗</i></button></div>
                            @if($preview ?? false)</fieldset>@endif
                        </form>
                    </div>
                </section>
            @endif
        @endforeach
    </main>

    <footer class="landing-footer">
        <div class="landing-container landing-footer__inner">
            <div @class(['landing-footer__top', 'landing-footer__top--without-social' => $socialLinks->isEmpty()])>
                <div class="landing-footer__identity">
                    <a class="landing-brand" href="{{ route('home') }}"><x-platform-brand variant="icon" class="landing-brand__mark" /><span class="landing-brand__wordmark">{{ $content['brand'] }}</span></a>
                    <p>{{ __('landing.footer') }}</p>
                </div>
                <nav class="landing-footer__nav" aria-label="{{ __('landing.primary_navigation') }}">
                    @foreach($content['section_order'] as $section)
                        @if($content['enabled_sections'][$section] ?? false)
                            <a href="#{{ $section }}">{{ __('landing.navigation.'.$section) }}</a>
                        @endif
                    @endforeach
                </nav>
                @if($socialLinks->isNotEmpty())
                    <div class="landing-footer__social">
                        <h2>{{ __('landing.follow_us') }}</h2>
                        <div>
                            @foreach($socialLinks as $platform => $url)
                                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('landing.social_visit', ['platform' => __('landing.social_links.'.$platform)]) }}">
                                    <span aria-hidden="true">{{ $platform === 'x' ? 'X' : strtoupper(substr($platform, 0, 1)) }}</span>
                                    {{ __('landing.social_links.'.$platform) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="landing-footer__actions"><a href="{{ route('platform.login') }}">{{ __('landing.sign_in') }}</a><a href="{{ ($preview ?? false) ? route('platform.landing.preview', ['language' => $oppositeLocale, 'revision' => request('revision')]) : route('locale.switch', $oppositeLocale) }}">{{ $oppositeLocale === 'ar' ? 'العربية' : 'English' }}</a></div>
            </div>
            <div class="landing-footer__bottom"><span>{{ __('landing.footer_note') }}</span><a href="#main-content">{{ __('landing.back_to_top') }} <i aria-hidden="true">↑</i></a></div>
        </div>
    </footer>
</body>
</html>
