@php
    $locale = app()->getLocale();
    $direction = config('app.supported_locales.'.$locale.'.direction', 'ltr');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
    <head>
        @include('partials.head', ['title' => __('access.login.title')])
        @vite('resources/css/auth-design.css')
    </head>
    <body class="auth-studio-body antialiased">
        <main class="auth-studio auth-studio--tenant">
            <section class="auth-studio__intro">
                <a class="auth-studio__brand" href="{{ route('home') }}"><x-app-logo /></a>
                <div class="auth-studio__story">
                    <p class="auth-studio__eyebrow">{{ __('auth-design.welcome') }}</p>
                    <h1>{{ __('auth-design.title') }}</h1>
                    <p class="auth-studio__description">{{ __('auth-design.body') }}</p>
                    <div class="auth-studio__illustration" aria-hidden="true"><div><span></span><span></span><span></span></div><section><i></i><div><b></b><b></b><b></b></div><footer><em></em><em></em><em></em><em></em><em></em></footer></section></div>
                    <ul class="auth-studio__qualities">@foreach(['learning', 'people', 'operations'] as $quality)<li><span aria-hidden="true">✓</span>{{ __('auth-design.'.$quality) }}</li>@endforeach</ul>
                </div>
                <p class="auth-studio__footnote">{{ __('auth-design.account_help') }}</p>
            </section>
            <section class="auth-studio__panel">
                <div class="auth-studio__preferences"><x-account-menu-preferences /></div>
                <div class="auth-studio__mobile-brand"><a href="{{ route('home') }}"><x-app-logo /></a></div>
                <div class="auth-studio__card">{{ $slot }}</div>
                <a class="auth-studio__back" href="{{ route('home') }}">{{ __('auth-design.back') }} <span aria-hidden="true">↗</span></a>
            </section>
        </main>
        @fluxScripts
    </body>
</html>
