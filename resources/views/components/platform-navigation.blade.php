@php($platformUser = auth('platform')->user())
<div class="app-sidebar-scroll-region">
    <div class="px-3 pt-4">
        <a href="{{ route('platform.dashboard') }}" class="flex items-center gap-3 text-white" data-platform-brand>
            <x-platform-brand variant="icon" class="h-12 w-12 shrink-0 rounded-2xl object-cover" />
            <span class="min-w-0">
                <span class="block truncate text-base font-bold">{{ app(\App\Support\BrandIdentity::class)->platformName() }}</span>
                <span class="mt-0.5 block text-xs text-zinc-400">{{ __('platform.brand.administration') }}</span>
            </span>
        </a>
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

@php($platformInitials = \Illuminate\Support\Str::of($platformUser->name)->trim()->explode(' ')->filter()->take(2)->map(fn ($part) => \Illuminate\Support\Str::substr($part, 0, 1))->implode(''))
<div
    class="app-sidebar-account"
    data-platform-account
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
    x-on:livewire:navigating.window="open = false"
>
    <div class="relative">
        <flux:profile
            :name="$platformUser->name"
            :initials="$platformInitials"
            icon-trailing="chevrons-up-down"
            class="w-full"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open.toString()"
            aria-controls="platform-account-menu"
        />

        <div
            id="platform-account-menu"
            x-cloak
            x-show="open"
            x-transition.opacity.duration.100ms
            class="app-sidebar-account-menu w-full rounded-lg border border-zinc-200 bg-white p-[.3125rem] shadow-xl dark:border-zinc-600 dark:bg-zinc-700"
        >
            <div class="flex items-center gap-2 px-1 py-1.5 text-sm">
                <flux:avatar :initials="$platformInitials" size="sm" />
                <div class="grid min-w-0 flex-1 text-start leading-tight">
                    <span class="truncate font-semibold">{{ $platformUser->name }}</span>
                    <span class="truncate text-xs">{{ $platformUser->email }}</span>
                </div>
            </div>

            <flux:menu.separator />
            <x-account-menu-preferences />
            <flux:menu.separator />

            <flux:menu.item href="{{ route('platform.settings.index') }}" icon="user-circle">{{ __('ui.common.my_account') }}</flux:menu.item>
            <flux:menu.item href="{{ route('home') }}" icon="globe-alt" target="_blank" rel="noopener">{{ __('ui.common.visit_site') }}</flux:menu.item>

            <flux:menu.separator />
            <form method="POST" action="{{ route('platform.logout') }}" class="w-full">
                @csrf
                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">{{ __('platform.dashboard.sign_out') }}</flux:menu.item>
            </form>
        </div>
    </div>
</div>
