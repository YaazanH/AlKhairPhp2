@extends('public.layout')

@section('content')
    @php
        $locale = app()->getLocale();
        $fallbackLocale = config('app.fallback_locale', 'en');
        $maintenanceTitle = data_get($site, 'maintenance_title.'.$locale)
            ?: data_get($site, 'maintenance_title.'.$fallbackLocale)
            ?: __('site.public.maintenance.default_title');
        $maintenanceMessage = data_get($site, 'maintenance_message.'.$locale)
            ?: data_get($site, 'maintenance_message.'.$fallbackLocale)
            ?: __('site.public.maintenance.default_message');
    @endphp

    <section class="maintenance-page" aria-labelledby="maintenance-title">
        <div class="maintenance-card">
            <div class="maintenance-card__visual" aria-hidden="true">
                <div class="maintenance-gears">
                    <span class="maintenance-gear maintenance-gear--large">&#9881;</span>
                    <span class="maintenance-gear maintenance-gear--small">&#9881;</span>
                    <span class="maintenance-gear maintenance-gear--tiny">&#9881;</span>
                </div>
            </div>

            <div class="maintenance-card__content">
                <div class="maintenance-card__brand">
                    <div class="maintenance-card__mark">
                        @if (! empty($site['logo_url']))
                            <img src="{{ $site['logo_url'] }}" alt="{{ $site['site_name'] }}" class="maintenance-card__logo">
                        @else
                            <x-app-logo-icon class="h-8 w-8 fill-current text-white" />
                        @endif
                    </div>
                    <span>{{ $site['site_name'] }}</span>
                </div>

                <div class="maintenance-status">
                    <span class="maintenance-status__dot"></span>
                    <span>{{ __('site.public.maintenance.eyebrow') }}</span>
                </div>

                <h1 id="maintenance-title" class="maintenance-card__title">{{ $maintenanceTitle }}</h1>
                <p class="maintenance-card__message">{{ $maintenanceMessage }}</p>
            </div>
        </div>
    </section>
@endsection
