@php
    $currentLocale = app()->getLocale();
    $currentLocaleConfig = config('app.supported_locales.'.$currentLocale, []);
    $textDirection = $currentLocaleConfig['direction'] ?? 'ltr';
    $isRtl = $textDirection === 'rtl';
    $sidebarBorderClass = $isRtl ? 'border-l' : 'border-r';
    $sidebarToggleInset = $isRtl ? 'right' : 'left';
    $mobileIdentitySpacingClass = $isRtl ? 'mr-3' : 'ml-3';
    $sidebarGroups = app(\App\Services\SidebarNavigationService::class)->sidebarFor(auth()->user());
    $monthLabel = \App\Support\ArabicMonthFormatter::monthYear(now());
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}" dir="{{ $textDirection }}">
    <head>
        @include('partials.head')
    </head>
    <body class="app-body" data-pdf-uploading-label="{{ __('curricula.fields.pdf_uploading') }}">
        @php
            $primaryRole = auth()->user()->primaryRoleName();
            $roleLabel = $primaryRole ? __('ui.roles.'.$primaryRole) : null;
            if ($primaryRole && $roleLabel === 'ui.roles.'.$primaryRole) {
                $roleLabel = \Illuminate\Support\Str::of($primaryRole)->replace(['_', '-'], ' ')->headline()->toString();
            }
        @endphp

        <div class="app-backdrop">
            <div class="app-backdrop__orb app-backdrop__orb--gold"></div>
            <div class="app-backdrop__orb app-backdrop__orb--emerald"></div>
            <div class="app-backdrop__orb app-backdrop__orb--plum"></div>
        </div>

        <div class="app-shell flex min-h-screen">
            <flux:sidebar sticky stashable class="app-sidebar-shell {{ $sidebarBorderClass }}">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

                <div class="app-sidebar-scroll-region">
                    <div class="px-1 pt-2">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-3" wire:navigate>
                            <x-app-logo :title="__('ui.app.quran_course')" :subtitle="$monthLabel" />
                        </a>
                    </div>

                    <flux:navlist variant="outline" class="mt-5">
                        @foreach ($sidebarGroups as $group)
                            <flux:navlist.group :heading="$group['title']" class="grid">
                                @foreach ($group['items'] as $item)
                                    <flux:navlist.item
                                        :icon="$item['icon']"
                                        :href="$item['href']"
                                        :current="$item['current']"
                                        class="{{ $item['key'] === 'print_templates' ? 'max-lg:hidden' : '' }}"
                                        wire:navigate
                                    >
                                        {{ $item['label'] }}
                                    </flux:navlist.item>
                                @endforeach
                            </flux:navlist.group>
                        @endforeach
                    </flux:navlist>
                </div>

                <div
                    class="app-sidebar-account"
                    x-data="{ open: false }"
                    x-on:click.outside="open = false"
                    x-on:keydown.escape.window="open = false"
                    x-on:livewire:navigating.window="open = false"
                >
                    <div class="relative">
                        <flux:profile
                            :name="auth()->user()->name"
                            :avatar="auth()->user()->profilePhotoUrl()"
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevrons-up-down"
                            class="w-full"
                            x-on:click="open = ! open"
                            x-bind:aria-expanded="open.toString()"
                        />

                        <div
                            x-cloak
                            x-show="open"
                            x-transition.opacity.duration.100ms
                            class="app-sidebar-account-menu w-full rounded-lg border border-zinc-200 bg-white p-[.3125rem] shadow-xl dark:border-zinc-600 dark:bg-zinc-700"
                            role="menu"
                        >
                            <div>
                                <div class="p-0 text-sm font-normal">
                                    <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                        <x-user-avatar :user="auth()->user()" size="sm" />

                                        <div class="grid flex-1 text-left text-sm leading-tight">
                                            <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                            <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <flux:menu.separator />

                            <x-account-menu-preferences />

                            <flux:menu.separator />

                            <div>
                                <flux:menu.item href="{{ route('settings.profile') }}" icon="user-circle" wire:navigate>{{ __('ui.common.my_account') }}</flux:menu.item>
                                <flux:menu.item href="{{ route('home') }}" icon="globe-alt">{{ __('ui.common.visit_site') }}</flux:menu.item>
                            </div>

                            <flux:menu.separator />

                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                                    {{ __('Log Out') }}
                                </flux:menu.item>
                            </form>
                        </div>
                    </div>
                </div>
            </flux:sidebar>

            <div class="flex min-h-screen min-w-0 flex-1 flex-col">
                <flux:header class="app-mobile-header lg:hidden">
                    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="{{ $sidebarToggleInset }}" />

                    <div class="{{ $mobileIdentitySpacingClass }} min-w-0">
                        <div class="text-[0.78rem] font-light tracking-normal text-neutral-400">{{ __('ui.app.name') }}</div>
                        <div class="truncate text-sm text-neutral-200" data-primary-role="{{ $primaryRole ?: '' }}">{{ $roleLabel ?: __('ui.common.workspace') }}</div>
                    </div>

                    <flux:spacer />
                    <x-mobile-header-mark class="mobile-header-mark" />
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
                        </div>
                    </div>

                    <div class="admin-modal__body">
                        <p id="admin-confirm-message" class="admin-confirm-message leading-7 text-neutral-300">{{ __('crud.common.confirm_delete.message') }}</p>
                        <div class="admin-confirm-actions">
                            <button id="admin-confirm-accept" type="button" class="admin-confirm-action admin-confirm-action--accept" data-modal-action-icon-ignore aria-label="{{ __('crud.common.confirm_delete.confirm') }}" title="{{ __('crud.common.confirm_delete.confirm') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.25 4.25L19 7" /></svg>
                            </button>
                            <button id="admin-confirm-deny" type="button" class="admin-confirm-action admin-confirm-action--deny" data-admin-confirm-close data-modal-action-icon-ignore aria-label="{{ __('crud.common.actions.cancel') }}" title="{{ __('crud.common.actions.cancel') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" aria-hidden="true"><path stroke-linecap="round" d="M6.5 6.5l11 11m0-11-11 11" /></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
