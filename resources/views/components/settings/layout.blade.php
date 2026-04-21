<div class="page-stack">
    <section class="surface-panel p-4">
        <div class="settings-tabs">
            <a href="{{ route('settings.profile') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.profile') ? 'is-active' : '' }}">
                <span class="settings-tab__meta">{{ __('settings.account.nav.meta') }}</span>
                <span class="settings-tab__title">{{ __('settings.account.nav.profile') }}</span>
            </a>
            <a href="{{ route('settings.password') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.password') ? 'is-active' : '' }}">
                <span class="settings-tab__meta">{{ __('settings.account.nav.meta') }}</span>
                <span class="settings-tab__title">{{ __('settings.account.nav.password') }}</span>
            </a>
            <a href="{{ route('settings.appearance') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.appearance') ? 'is-active' : '' }}">
                <span class="settings-tab__meta">{{ __('settings.account.nav.meta') }}</span>
                <span class="settings-tab__title">{{ __('settings.account.nav.appearance') }}</span>
            </a>
        </div>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        <div class="mb-6">
            <div class="eyebrow">{{ __('settings.account.eyebrow') }}</div>
            <h2 class="font-display mt-3 text-3xl text-white">{{ $heading ?? '' }}</h2>
            @if (! empty($subheading))
                <p class="mt-3 max-w-2xl text-sm leading-7 text-neutral-300">{{ $subheading }}</p>
            @endif
        </div>

        <div class="max-w-3xl">
            {{ $slot }}
        </div>
    </section>
</div>
