@php
    $currentLocale = app()->getLocale();
    $currentLocaleConfig = config('app.supported_locales.'.$currentLocale, []);
    $textDirection = $currentLocaleConfig['direction'] ?? 'ltr';
    $isRtl = $textDirection === 'rtl';
    $sidebarBorderClass = $isRtl ? 'border-l' : 'border-r';
    $sidebarToggleInset = $isRtl ? 'right' : 'left';
    $headerBrandSpacingClass = $isRtl ? 'mr-2 ml-5 lg:mr-0' : 'ml-2 mr-5 lg:ml-0';
    $navbarSpacingClass = $isRtl ? 'ml-1.5 space-x-reverse space-x-0.5' : 'mr-1.5 space-x-0.5';
    $dropdownAlign = $isRtl ? 'start' : 'end';
    $mobileLogoSpacingClass = $isRtl ? 'mr-1' : 'ml-1';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}" dir="{{ $textDirection }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="app-body">
        <div class="app-backdrop">
            <div class="app-backdrop__orb app-backdrop__orb--gold"></div>
            <div class="app-backdrop__orb app-backdrop__orb--emerald"></div>
            <div class="app-backdrop__orb app-backdrop__orb--plum"></div>
        </div>

        <flux:header container class="app-mobile-header border-b">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="{{ $sidebarToggleInset }}" />

            <a href="{{ route('dashboard') }}" class="{{ $headerBrandSpacingClass }} flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" />
            </a>

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="layout-grid" href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('ui.nav.dashboard') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <flux:navbar class="{{ $navbarSpacingClass }} py-0!">
                <flux:tooltip content="Search" position="bottom">
                    <flux:navbar.item class="!h-10 [&>div>svg]:size-5" icon="magnifying-glass" href="#" label="Search" />
                </flux:tooltip>
                <flux:tooltip content="Repository" position="bottom">
                    <flux:navbar.item
                        class="h-10 max-lg:hidden [&>div>svg]:size-5"
                        icon="folder-git-2"
                        href="https://github.com/laravel/livewire-starter-kit"
                        target="_blank"
                        label="Repository"
                    />
                </flux:tooltip>
                <flux:tooltip content="Documentation" position="bottom">
                    <flux:navbar.item
                        class="h-10 max-lg:hidden [&>div>svg]:size-5"
                        icon="book-open-text"
                        href="https://laravel.com/docs/starter-kits"
                        target="_blank"
                        label="Documentation"
                    />
                </flux:tooltip>
            </flux:navbar>

            <!-- Desktop User Menu -->
            <flux:dropdown position="top" align="{{ $dropdownAlign }}">
                <flux:profile
                    class="cursor-pointer"
                    :avatar="auth()->user()->profilePhotoUrl()"
                    :initials="auth()->user()->initials()"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <x-user-avatar :user="auth()->user()" size="sm" />

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <div class="px-2 py-2">
                        <x-locale-switcher compact />
                    </div>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>{{ __('ui.common.settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar stashable sticky class="app-sidebar-shell lg:hidden {{ $sidebarBorderClass }}">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="{{ $mobileLogoSpacingClass }} flex items-center space-x-2" wire:navigate>
                <x-app-logo class="size-8" />
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group :heading="__('ui.nav.platform')">
                    <flux:navlist.item icon="layout-grid" href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('ui.nav.dashboard') }}
                    </flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <flux:navlist variant="outline">
                <flux:navlist.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    Repository
                </flux:navlist.item>

                <flux:navlist.item icon="book-open-text" href="https://laravel.com/docs/starter-kits" target="_blank">
                    Documentation
                </flux:navlist.item>
            </flux:navlist>
        </flux:sidebar>

        <main class="app-main">
            <div class="app-main-inner">
                {{ $slot }}
            </div>
        </main>

        @fluxScripts
    </body>
</html>
