@php($platformUser = auth('platform')->user())
<div class="app-sidebar-scroll-region">
    <div class="px-3 pt-4">
        <a href="{{ route('platform.dashboard') }}" class="text-lg font-bold text-white">{{ app(\App\Support\BrandIdentity::class)->platformName() }}</a>
        <p class="mt-1 text-xs text-zinc-400">{{ __('platform.brand.administration') }}</p>
    </div>

    <nav class="platform-workspace-navigation" aria-label="{{ __('platform-workspace.navigation') }}">
        @foreach(app(\App\Support\PlatformWorkspaceNavigation::class)->groups($platformUser) as $group => $items)
            <section class="platform-navigation-group" aria-labelledby="platform-nav-{{ $group }}">
                <h2 id="platform-nav-{{ $group }}">{{ __('platform-workspace.groups.'.$group) }}</h2>
                <flux:navlist variant="outline">
                    @foreach($items as [$key, $icon, $route, $patterns])
                        <flux:navlist.item :icon="$icon" :href="route($route)" :current="request()->routeIs(...$patterns)">{{ __('platform-workspace.destinations.'.$key) }}</flux:navlist.item>
                    @endforeach
                </flux:navlist>
            </section>
        @endforeach
    </nav>

    <a href="{{ route('home') }}" target="_blank" rel="noopener" class="mx-3 mt-6 flex items-center justify-between rounded-xl border border-zinc-300 px-3 py-2 text-xs font-medium text-zinc-700 transition hover:border-emerald-600 hover:text-emerald-800 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-emerald-500 dark:hover:text-white">
        <span>{{ __('platform.ui.navigation.public_site') }}</span><span aria-hidden="true">↗</span>
    </a>
</div>

<div class="p-3">
    <div class="rounded-xl border border-zinc-200 bg-zinc-100 p-3 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
        <p class="font-medium text-zinc-950 dark:text-white">{{ $platformUser->name }}</p>
        <p class="mt-0.5 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $platformUser->email }}</p>
        <div class="mt-3"><x-account-menu-preferences /></div>
        <form method="POST" action="{{ route('platform.logout') }}" class="mt-3">@csrf<button class="text-xs font-semibold text-emerald-800 dark:text-emerald-300">{{ __('platform.dashboard.sign_out') }}</button></form>
    </div>
</div>
