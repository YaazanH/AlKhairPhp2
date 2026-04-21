<div class="surface-panel p-4">
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
        <section class="rounded-2xl border border-white/8 bg-white/4 p-3">
            <div class="mb-3 px-2">
                <div class="eyebrow">{{ __('settings.navigation.groups.dashboard.meta') }}</div>
                <div class="mt-1 text-sm font-semibold text-white">{{ __('settings.navigation.groups.dashboard.title') }}</div>
            </div>

            <div class="settings-tabs">
                <a href="{{ route('settings.organization') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.organization') ? 'is-active' : '' }}">
                    <span class="settings-tab__meta">{{ __('settings.navigation.organization.meta') }}</span>
                    <span class="settings-tab__title">{{ __('settings.navigation.organization.title') }}</span>
                </a>
                <a href="{{ route('settings.tracking') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.tracking') ? 'is-active' : '' }}">
                    <span class="settings-tab__meta">{{ __('settings.navigation.tracking.meta') }}</span>
                    <span class="settings-tab__title">{{ __('settings.navigation.tracking.title') }}</span>
                </a>
                <a href="{{ route('settings.points') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.points') ? 'is-active' : '' }}">
                    <span class="settings-tab__meta">{{ __('settings.navigation.points.meta') }}</span>
                    <span class="settings-tab__title">{{ __('settings.navigation.points.title') }}</span>
                </a>
                <a href="{{ route('settings.finance') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.finance') ? 'is-active' : '' }}">
                    <span class="settings-tab__meta">{{ __('settings.navigation.finance.meta') }}</span>
                    <span class="settings-tab__title">{{ __('settings.navigation.finance.title') }}</span>
                </a>
            </div>
        </section>

        @can('website.manage')
            <section class="rounded-2xl border border-white/8 bg-white/4 p-3">
                <div class="mb-3 px-2">
                    <div class="eyebrow">{{ __('settings.navigation.groups.website.meta') }}</div>
                    <div class="mt-1 text-sm font-semibold text-white">{{ __('settings.navigation.groups.website.title') }}</div>
                </div>

                <div class="settings-tabs">
                    <a href="{{ route('settings.website') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.website') ? 'is-active' : '' }}">
                        <span class="settings-tab__meta">{{ __('site.admin.nav.meta') }}</span>
                        <span class="settings-tab__title">{{ __('site.admin.nav.website') }}</span>
                    </a>
                    <a href="{{ route('settings.website.pages') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.website.pages') ? 'is-active' : '' }}">
                        <span class="settings-tab__meta">{{ __('site.admin.nav.meta') }}</span>
                        <span class="settings-tab__title">{{ __('site.admin.nav.pages') }}</span>
                    </a>
                    <a href="{{ route('settings.website.navigation') }}" wire:navigate class="settings-tab {{ request()->routeIs('settings.website.navigation') ? 'is-active' : '' }}">
                        <span class="settings-tab__meta">{{ __('site.admin.nav.meta') }}</span>
                        <span class="settings-tab__title">{{ __('site.admin.nav.menus') }}</span>
                    </a>
                </div>
            </section>
        @endcan
    </div>
</div>
