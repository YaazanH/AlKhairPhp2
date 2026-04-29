@php
    $currentLocale = app()->getLocale();
    $currentLocaleConfig = config('app.supported_locales.'.$currentLocale, []);
    $textDirection = $currentLocaleConfig['direction'] ?? 'ltr';
    $isRtl = $textDirection === 'rtl';
    $sidebarBorderClass = $isRtl ? 'border-l' : 'border-r';
    $sidebarToggleInset = $isRtl ? 'right' : 'left';
    $desktopDropdownAlign = $isRtl ? 'end' : 'start';
    $mobileIdentitySpacingClass = $isRtl ? 'mr-3' : 'ml-3';
    $sidebarGroups = app(\App\Services\SidebarNavigationService::class)->sidebarFor(auth()->user());
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}" dir="{{ $textDirection }}">
    <head>
        @include('partials.head')
    </head>
    <body class="app-body">
        @php
            $roleLabel = auth()->user()->getRoleNames()
                ->map(fn (string $role) => __('ui.roles.'.$role))
                ->implode(' · ');
        @endphp

        <div class="app-backdrop">
            <div class="app-backdrop__orb app-backdrop__orb--gold"></div>
            <div class="app-backdrop__orb app-backdrop__orb--emerald"></div>
            <div class="app-backdrop__orb app-backdrop__orb--plum"></div>
        </div>

        <div class="app-shell flex min-h-screen">
            <flux:sidebar sticky stashable class="app-sidebar-shell {{ $sidebarBorderClass }}">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

                <div class="app-brand-card">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3" wire:navigate>
                        <x-app-logo />
                    </a>

                    <div class="mt-5 flex items-center justify-between gap-3">
                        <span class="badge-soft">{{ $roleLabel ?: __('ui.common.workspace') }}</span>
                        <span class="text-xs uppercase tracking-[0.24em] text-neutral-400">{{ now()->locale($currentLocale)->translatedFormat('M Y') }}</span>
                    </div>

                    <p class="mt-4 text-sm leading-6 text-neutral-300">
                        {{ __('ui.app.workspace_tagline') }}
                    </p>
                </div>

                <flux:navlist variant="outline" class="mt-6">
                    @foreach ($sidebarGroups as $group)
                        <flux:navlist.group :heading="$group['title']" class="grid">
                            @foreach ($group['items'] as $item)
                                <flux:navlist.item :icon="$item['icon']" :href="$item['href']" :current="$item['current']" wire:navigate>{{ $item['label'] }}</flux:navlist.item>
                            @endforeach
                        </flux:navlist.group>
                    @endforeach
                </flux:navlist>

                <flux:spacer />

                <div class="app-sidebar-footer">
                    <div class="eyebrow">{{ __('ui.common.current_account') }}</div>
                    <div class="mt-3 flex items-center gap-3">
                        <x-user-avatar :user="auth()->user()" size="sm" />
                        <div class="min-w-0">
                            <div class="truncate text-base font-semibold text-white">{{ auth()->user()->name }}</div>
                            <p class="truncate text-sm leading-6 text-neutral-300">
                                {{ auth()->user()->email ?: (auth()->user()->username ?: 'No account identifier recorded.') }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-5">
                        <x-locale-switcher />
                    </div>

                    <div class="mt-4">
                        <a href="{{ route('home') }}" target="_blank" class="pill-link pill-link--compact w-full justify-center">
                            {{ __('ui.common.visit_site') }}
                        </a>
                    </div>
                </div>

                <div class="mt-4">
                    <flux:dropdown position="bottom" align="{{ $desktopDropdownAlign }}">
                        <flux:profile
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevrons-up-down"
                        />

                        <flux:menu class="w-[220px]">
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
                </div>
            </flux:sidebar>

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <flux:header class="app-mobile-header lg:hidden">
                    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="{{ $sidebarToggleInset }}" />

                    <div class="{{ $mobileIdentitySpacingClass }} min-w-0">
                        <div class="text-[0.68rem] font-semibold uppercase tracking-[0.28em] text-neutral-500">{{ __('ui.app.name') }}</div>
                        <div class="truncate text-sm text-neutral-200">{{ $roleLabel ?: __('ui.common.workspace') }}</div>
                    </div>

                    <flux:spacer />

                    <flux:dropdown position="top" align="end">
                        <flux:profile
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevron-down"
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

                <main class="app-main">
                    <div class="app-main-inner">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        <div
            id="admin-confirm-modal"
            class="admin-modal"
            data-default-confirm-label="{{ __('crud.common.confirm_delete.confirm') }}"
            data-default-message="{{ __('crud.common.confirm_delete.message') }}"
            data-default-title="{{ __('crud.common.confirm_delete.title') }}"
            hidden
            aria-hidden="true"
        >
            <div class="admin-modal__backdrop" data-admin-confirm-close></div>
            <div class="admin-modal__viewport">
                <div class="admin-modal__dialog admin-modal__dialog--2xl" role="dialog" aria-modal="true" aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-message">
                    <div class="admin-modal__header">
                        <div>
                            <h2 id="admin-confirm-title" class="admin-modal__title">{{ __('crud.common.confirm_delete.title') }}</h2>
                            <p id="admin-confirm-message" class="admin-modal__description">{{ __('crud.common.confirm_delete.message') }}</p>
                        </div>

                        <button type="button" data-admin-confirm-close class="admin-modal__close" aria-label="{{ __('crud.common.actions.close') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="admin-modal__body">
                        <div class="admin-action-cluster admin-action-cluster--end">
                            <button id="admin-confirm-cancel" type="button" class="pill-link">
                                {{ __('crud.common.actions.cancel') }}
                            </button>
                            <button id="admin-confirm-accept" type="button" class="pill-link pill-link--danger">
                                {{ __('crud.common.confirm_delete.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
