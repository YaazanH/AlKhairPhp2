@php($platformUser = auth('platform')->user())
<div class="app-sidebar-scroll-region">
    <div class="px-3 pt-4">
        <a href="{{ route('platform.dashboard') }}" class="text-lg font-bold text-white">{{ app(\App\Support\BrandIdentity::class)->platformName() }}</a>
        <p class="mt-1 text-xs text-zinc-400">{{ __('platform.brand.administration') }}</p>
    </div>

    <flux:navlist variant="outline" class="mt-6">
        @if($platformUser->hasPlatformPermission('view.dashboard'))
            <flux:navlist.item icon="squares-2x2" href="{{ route('platform.dashboard') }}" :current="request()->routeIs('platform.dashboard')">{{ __('platform.ui.navigation.tenants') }}</flux:navlist.item>
        @endif
        @if($platformUser->hasPlatformPermission('manage.tenants'))
            <flux:navlist.item icon="plus-circle" href="{{ route('platform.tenants.create') }}" :current="request()->routeIs('platform.tenants.create')">{{ __('platform.ui.navigation.new_tenant') }}</flux:navlist.item>
        @endif
        @if($platformUser->hasPlatformPermission('manage.plans'))
            <flux:navlist.item icon="building-office-2" href="{{ route('platform.plans.index') }}" :current="request()->routeIs('platform.plans.*')">{{ __('platform.ui.navigation.packages') }}</flux:navlist.item>
        @endif
        @if($platformUser->hasPlatformPermission('view.subscriptions') || $platformUser->hasPlatformPermission('manage.subscriptions'))
            <flux:navlist.item icon="credit-card" href="{{ route('platform.subscription-settings.edit') }}" :current="request()->routeIs('platform.subscription-settings.*') || request()->routeIs('platform.vouchers.*')">{{ __('platform.ui.navigation.subscriptions') }}</flux:navlist.item>
        @endif

        @if($platformUser->hasPlatformPermission('view.backups'))
            <flux:navlist.item icon="archive-box" href="{{ route('platform.backups.index') }}" :current="request()->routeIs('platform.backups.*')">{{ __('platform.ui.navigation.backups') }}</flux:navlist.item>
        @endif
        @if($platformUser->hasPlatformPermission('view.storage'))
            <flux:navlist.item icon="circle-stack" href="{{ route('platform.storage.index') }}" :current="request()->routeIs('platform.storage.*')">{{ __('platform.ui.navigation.storage') }}</flux:navlist.item>
        @endif
        @if($platformUser->hasPlatformPermission('manage.support.problems') || $platformUser->hasPlatformPermission('manage.support.suggestions'))
            <flux:navlist.item icon="chat-bubble-left-right" href="{{ route('platform.support.index') }}" :current="request()->routeIs('platform.support.*')">{{ __('support.platform.navigation') }}</flux:navlist.item>
        @endif

        @if($platformUser->hasPlatformPermission('manage.landing-page') || $platformUser->hasPlatformPermission('publish.landing-page'))
            <flux:navlist.item icon="globe-alt" href="{{ route('platform.landing.edit') }}" :current="request()->routeIs('platform.landing.*')">{{ __('platform.ui.navigation.landing') }}</flux:navlist.item>
        @endif
        @if($platformUser->hasPlatformPermission('manage.report-library') || $platformUser->hasPlatformPermission('publish.report-library'))
            <flux:navlist.item icon="chart-bar-square" href="{{ route('platform.report-library.index') }}" :current="request()->routeIs('platform.report-library.*')">{{ __('platform.ui.navigation.reports') }}</flux:navlist.item>
        @endif
        @if($platformUser->hasPlatformPermission('manage.platform-users'))
            <flux:navlist.item icon="users" href="{{ route('platform.access.index') }}" :current="request()->routeIs('platform.access.*')">{{ __('platform.ui.navigation.users') }}</flux:navlist.item>
        @endif
    </flux:navlist>

    <a href="{{ route('home') }}" target="_blank" rel="noopener" class="mx-3 mt-6 flex items-center justify-between rounded-xl border border-zinc-700 px-3 py-2 text-xs font-medium text-zinc-300 transition hover:border-emerald-500 hover:text-white">
        <span>{{ __('platform.ui.navigation.public_site') }}</span><span aria-hidden="true">↗</span>
    </a>
</div>

<div class="p-3">
    <div class="rounded-xl bg-zinc-800 p-3 text-sm text-zinc-300">
        <p class="font-medium text-white">{{ $platformUser->name }}</p>
        <p class="mt-0.5 truncate text-xs text-zinc-500">{{ $platformUser->email }}</p>
        <div class="mt-3"><x-account-menu-preferences /></div>
        <form method="POST" action="{{ route('platform.logout') }}" class="mt-3">@csrf<button class="text-xs font-medium text-emerald-400">{{ __('platform.dashboard.sign_out') }}</button></form>
    </div>
</div>
