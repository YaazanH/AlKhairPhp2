@php
    $locale = app()->getLocale();
    $direction = config('app.supported_locales.'.$locale.'.direction', 'ltr');
    $copy = __('errors.pages.'.$status);

    if (! is_array($copy)) {
        $copy = __('errors.pages.'.($status >= 500 ? '5xx' : '4xx'));
    }

    // Preserve actionable validation details without exposing server exceptions.
    $detail = $status === 422 && isset($exception) && ! $exception->getPrevious()
        ? trim($exception->getMessage())
        : '';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $status }} · {{ $copy['title'] }} | {{ __('ui.app.name') }}</title>
        <link rel="preload" href="{{ asset('fonts/dubai/Dubai-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>
        <script>
            try {
                const appearance = localStorage.getItem('flux.appearance');
                if (appearance === 'dark' || appearance === 'light') {
                    document.documentElement.dataset.appearance = appearance;
                }
            } catch (_) {}
        </script>
        @include('errors.styles')
    </head>
    <body>
        <main class="error-page" aria-labelledby="error-title">
            <div class="error-page__content">
                <p class="error-page__label" aria-hidden="true">ERROR</p>
                <p class="error-page__code" dir="ltr" aria-label="{{ __('errors.label', ['code' => $status]) }}">{{ $status }}</p>
                <h1 class="error-page__title" id="error-title">{{ $copy['title'] }}</h1>
                @if ($detail !== '')
                    <p class="error-page__detail">{{ $detail }}</p>
                @endif
                <a class="error-page__home" href="{{ url('/login') }}">{{ __('errors.actions.login') }}</a>
            </div>
        </main>
    </body>
</html>
