<div class="surface-panel p-3">
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
        @can('website.manage')
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
        @endcan
    </div>
</div>
