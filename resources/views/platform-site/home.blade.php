@php
    $locale = app()->getLocale();
    $direction = config('app.supported_locales.'.$locale.'.direction', 'ltr');
    $oppositeLocale = $locale === 'ar' ? 'en' : 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $content['meta_description'] }}">
    <meta name="theme-color" content="#082f26">
    <title>{{ $content['meta_title'] }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="landing-body">
    <header class="landing-header">
        <div class="landing-container landing-header__inner">
            <a href="{{ route('home') }}" class="landing-brand" aria-label="{{ $content['brand'] }}">
                <span class="landing-brand__mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>{{ $content['brand'] }}</span>
            </a>
            <nav class="landing-nav" aria-label="Primary navigation">
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
        </div>
    </header>

    <main>
        <section class="landing-hero">
            <div class="landing-hero__glow landing-hero__glow--one"></div>
            <div class="landing-hero__glow landing-hero__glow--two"></div>
            <div class="landing-container landing-hero__grid">
                <div class="landing-hero__copy">
                    <p class="landing-kicker"><span></span>{{ $content['hero_eyebrow'] }}</p>
                    <h1>{{ $content['hero_title'] }}</h1>
                    <p class="landing-lead">{{ $content['hero_body'] }}</p>
                    <div class="landing-hero__actions">
                        <a class="landing-button" href="#contact">{{ $content['hero_primary_label'] }} <span aria-hidden="true">→</span></a>
                        <a class="landing-button landing-button--ghost" href="{{ route('platform.login') }}">{{ $content['hero_secondary_label'] }}</a>
                    </div>
                    <div class="landing-trust-row" aria-label="Platform qualities">
                        <span><i></i>{{ $locale === 'ar' ? 'مساحة مستقلة لكل مؤسسة' : 'A private workspace per organisation' }}</span>
                        <span><i></i>{{ $locale === 'ar' ? 'العربية والإنجليزية' : 'Arabic and English' }}</span>
                        <span><i></i>{{ $locale === 'ar' ? 'ينمو مع احتياجك' : 'Grows with your needs' }}</span>
                    </div>
                </div>
                <div class="landing-hero__visual" aria-label="{{ __('landing.platform_preview') }}">
                    <div class="landing-device landing-device--hero">
                        <div class="landing-device__bar"><span></span><span></span><span></span><b>{{ $content['brand'] }}</b></div>
                        <div class="landing-device__layout">
                            <aside><strong>◆</strong>@foreach(range(1, 6) as $item)<i></i>@endforeach</aside>
                            <div class="landing-device__canvas">
                                <div class="landing-device__welcome"><div><small>{{ $locale === 'ar' ? 'صباح الخير' : 'Good morning' }}</small><strong>{{ $locale === 'ar' ? 'هذه أولويات اليوم' : 'Here is what needs attention today' }}</strong></div><span>12 {{ $locale === 'ar' ? 'مهمة' : 'tasks' }}</span></div>
                                <div class="landing-device__stats"><article><em>92%</em><small>{{ $locale === 'ar' ? 'الحضور' : 'Attendance' }}</small></article><article><em>248</em><small>{{ $locale === 'ar' ? 'متعلماً' : 'Learners' }}</small></article><article><em>18</em><small>{{ $locale === 'ar' ? 'مجموعة' : 'Groups' }}</small></article></div>
                                <div class="landing-device__chart"><div class="landing-device__chart-head"><span>{{ $locale === 'ar' ? 'نظرة على التقدم' : 'Progress at a glance' }}</span><small>{{ $locale === 'ar' ? 'هذا الشهر' : 'This month' }}</small></div><div class="landing-device__bars">@foreach([38,55,48,72,66,86,78,94] as $height)<i style="--bar: {{ $height }}%"></i>@endforeach</div></div>
                            </div>
                        </div>
                    </div>
                    <div class="landing-float-card landing-float-card--one"><span>✓</span><div><strong>{{ $locale === 'ar' ? 'اكتمل التحديث' : 'Progress updated' }}</strong><small>{{ $locale === 'ar' ? 'السجلات متزامنة' : 'Records are in sync' }}</small></div></div>
                    <div class="landing-float-card landing-float-card--two"><span>↗</span><div><strong>{{ $locale === 'ar' ? 'رؤية أوضح' : 'Clearer insight' }}</strong><small>{{ $locale === 'ar' ? 'قرار في الوقت المناسب' : 'Act at the right time' }}</small></div></div>
                </div>
            </div>
        </section>

        @foreach($content['section_order'] as $section)
            @continue(!($content['enabled_sections'][$section] ?? false))
            @if($section === 'features')
                <section id="features" class="landing-section landing-features">
                    <div class="landing-container">
                        <div class="landing-section-heading"><p class="landing-kicker"><span></span>{{ $content['features_kicker'] }}</p><h2>{{ $content['features_title'] }}</h2><p>{{ $content['features_body'] }}</p></div>
                        <div class="landing-feature-grid">
                            @foreach($content['feature_items'] as $index => $feature)
                                <article class="landing-feature-card"><span class="landing-feature-card__number">0{{ $index + 1 }}</span><div class="landing-feature-card__icon">@if($index === 0)◎@elseif($index === 1)⌘@else⌁@endif</div><h3>{{ $feature['title'] }}</h3><p>{{ $feature['body'] }}</p></article>
                            @endforeach
                        </div>
                    </div>
                </section>
            @elseif($section === 'showcase')
                <section id="showcase" class="landing-section landing-showcase">
                    <div class="landing-container">
                        <div class="landing-section-heading landing-section-heading--light"><p class="landing-kicker"><span></span>{{ $content['showcase_kicker'] }}</p><h2>{{ $content['showcase_title'] }}</h2><p>{{ $content['showcase_body'] }}</p></div>
                        <div class="landing-story" data-landing-story>
                            <div class="landing-story__steps">
                                @foreach($content['showcase_items'] as $index => $item)
                                    <article class="landing-story__step {{ $index === 0 ? 'is-active' : '' }}" data-landing-story-step="{{ $index }}"><span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $item['title'] }}</h3><p>{{ $item['body'] }}</p></article>
                                @endforeach
                            </div>
                            <div class="landing-story__screen">
                                @foreach($content['showcase_items'] as $index => $item)
                                    <div class="landing-story__image {{ $index === 0 ? 'is-active' : '' }}" data-landing-story-image="{{ $index }}">
                                        @if($item['image_path'])
                                            <img src="{{ route('platform-site.media', ['path' => $item['image_path']]) }}" alt="{{ $item['title'] }}">
                                        @else
                                            <div class="landing-screen-placeholder"><div class="landing-screen-placeholder__top"><i></i><i></i><i></i></div><div class="landing-screen-placeholder__body"><aside></aside><main><span></span><h4>{{ $item['title'] }}</h4><div class="landing-screen-placeholder__cards"><b></b><b></b><b></b></div><div class="landing-screen-placeholder__table">@foreach(range(1,4) as $row)<i></i>@endforeach</div></main></div></div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>
            @elseif($section === 'packages')
                <section id="packages" class="landing-section landing-packages"><div class="landing-container"><div class="landing-section-heading"><p class="landing-kicker"><span></span>{{ $content['packages_kicker'] }}</p><h2>{{ $content['packages_title'] }}</h2><p>{{ $content['packages_body'] }}</p></div><div class="landing-package-grid">
                    @foreach($plans as $plan)<article class="landing-package-card {{ $loop->iteration === 2 ? 'landing-package-card--featured' : '' }}"><div><span>{{ $plan->code }}</span><h3>{{ $plan->name }}</h3><p>{{ $plan->description }}</p></div><div class="landing-package-card__price">@if($plan->price_syp > 0)<small>{{ __('landing.package_from') }}</small><strong>{{ number_format($plan->price_syp) }} <em>SYP</em></strong><span>{{ __('landing.per_period', ['days' => $plan->billing_period_days]) }}</span>@else<strong>{{ __('landing.price_on_request') }}</strong>@endif</div><ul>@foreach($plan->features->take(5) as $feature)<li><span>✓</span>{{ $feature->name }}</li>@endforeach</ul><a href="#contact">{{ __('landing.request_demo') }} <span>→</span></a></article>@endforeach
                </div></div></section>
            @elseif($section === 'faq')
                <section id="faq" class="landing-section landing-faq"><div class="landing-container landing-faq__grid"><div class="landing-section-heading"><p class="landing-kicker"><span></span>{{ $content['faq_kicker'] }}</p><h2>{{ $content['faq_title'] }}</h2></div><div class="landing-faq__items">@foreach($content['faq_items'] as $item)<details @if($loop->first) open @endif><summary>{{ $item['question'] }}<span>+</span></summary><p>{{ $item['answer'] }}</p></details>@endforeach</div></div></section>
            @elseif($section === 'contact')
                <section id="contact" class="landing-section landing-contact"><div class="landing-container landing-contact__grid"><div><p class="landing-kicker landing-kicker--light"><span></span>{{ $content['contact_kicker'] }}</p><h2>{{ $content['contact_title'] }}</h2><p>{{ $content['contact_body'] }}</p><div class="landing-contact__promise"><span>✓</span>{{ $locale === 'ar' ? 'نركز العرض على عمل مؤسستك واحتياجاتها الفعلية.' : 'We focus the conversation on your real workflow and priorities.' }}</div></div><form method="POST" action="{{ route('platform-site.enquire') }}" class="landing-contact__form">@csrf
                    @if(session('landing_status'))<div class="landing-form-success" role="status">{{ session('landing_status') }}</div>@endif
                    @if($errors->any())<div class="landing-form-error" role="alert">{{ $errors->first() }}</div>@endif
                    <div class="landing-form-row"><label>{{ __('landing.contact.name') }}<input name="name" value="{{ old('name') }}" required maxlength="120"></label><label>{{ __('landing.contact.organisation') }}<input name="organisation_name" value="{{ old('organisation_name') }}" required maxlength="180"></label></div>
                    <div class="landing-form-row"><label>{{ __('landing.contact.email') }}<input type="email" name="email" value="{{ old('email') }}" required maxlength="190"></label><label>{{ __('landing.contact.phone') }}<input name="phone" value="{{ old('phone') }}" maxlength="40"></label></div>
                    <label>{{ __('landing.contact.message') }}<textarea name="message" rows="4" maxlength="3000">{{ old('message') }}</textarea></label>
                    <div class="landing-form-footer"><small>{{ __('landing.contact.privacy') }}</small><button type="submit">{{ __('landing.contact.submit') }} <span>→</span></button></div>
                </form></div></section>
            @endif
        @endforeach
    </main>
    <footer class="landing-footer"><div class="landing-container"><a class="landing-brand" href="{{ route('home') }}"><span class="landing-brand__mark"><span></span><span></span><span></span></span><span>{{ $content['brand'] }}</span></a><p>{{ __('landing.footer') }}</p><div><a href="{{ route('platform.login') }}">{{ __('landing.sign_in') }}</a><a href="{{ route('locale.switch', $oppositeLocale) }}">{{ $oppositeLocale === 'ar' ? 'العربية' : 'English' }}</a></div></div></footer>
</body>
</html>
