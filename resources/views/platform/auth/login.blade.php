@php
    $currentLocale = app()->getLocale();
    $direction = config('app.supported_locales.'.$currentLocale.'.direction', 'ltr');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}" dir="{{ $direction }}" data-platform-auth>
    <head>
        @include('partials.head', [
            'title' => __('platform.login.title'),
            'metaDescription' => __('platform.login.description'),
            'themeColor' => '#07150d',
        ])
        <style>
            html.dark[data-platform-auth] body,
            html.dark[data-platform-auth] [data-platform-login] {
                background-color: #07100b !important;
                color: #f6fff8 !important;
            }

            html.dark[data-platform-auth] [data-platform-login] :is(h1, h2) {
                color: #ffffff !important;
            }

            html.dark[data-platform-auth] [data-platform-login] input:is([type='email'], [type='password']) {
                border-color: rgb(255 255 255 / 0.1) !important;
                background-color: rgb(0 0 0 / 0.2) !important;
                color: #ffffff !important;
            }

            html:not(.dark)[data-platform-auth] body,
            html:not(.dark)[data-platform-auth] [data-platform-login] {
                background-color: #edf0e4 !important;
                color: #123326 !important;
            }

            html:not(.dark)[data-platform-auth] [data-platform-login] input:is([type='email'], [type='password']) {
                border-color: rgb(18 109 59 / 0.22) !important;
                background-color: rgb(255 255 255 / 0.82) !important;
                color: #123326 !important;
            }
        </style>
    </head>
    <body class="min-h-screen bg-[#07100b] text-white antialiased">
        <div class="fixed end-5 top-5 z-20 w-44 rounded-2xl border border-emerald-900/10 bg-white/75 shadow-sm backdrop-blur dark:border-white/15 dark:bg-black/20">
            <x-account-menu-preferences />
        </div>
        <main class="relative isolate min-h-svh overflow-hidden" data-platform-login>
            <div class="pointer-events-none absolute inset-0 -z-10">
                <div class="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,.025)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.025)_1px,transparent_1px)] bg-[size:48px_48px]"></div>
                <div class="absolute -start-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-emerald-500/15 blur-3xl"></div>
                <div class="absolute -bottom-64 -end-48 h-[42rem] w-[42rem] rounded-full bg-amber-300/10 blur-3xl"></div>
            </div>

            <div class="mx-auto grid min-h-svh w-full max-w-7xl lg:grid-cols-[1.1fr_.9fr]">
                <section class="hidden flex-col justify-between border-e border-white/10 p-10 lg:flex xl:p-14">
                    <div class="flex items-center justify-between gap-5">
                        <a href="{{ route('home') }}" class="inline-flex items-center gap-3">
                            <span class="flex h-11 w-11 items-center justify-center rounded-2xl border border-emerald-300/20 bg-emerald-300/10 text-lg font-bold text-emerald-200">A</span>
                            <span>
                                <span class="block text-sm font-semibold text-white">{{ __('platform.brand.name') }}</span>
                                <span class="block text-xs text-white/45">{{ __('platform.brand.administration') }}</span>
                            </span>
                        </a>
                    </div>

                    <div class="max-w-2xl py-14">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-300">{{ __('platform.login.eyebrow') }}</p>
                        <h1 class="font-display mt-6 text-5xl leading-[1.05] text-white xl:text-6xl">{{ __('platform.login.title') }}</h1>
                        <p class="mt-6 max-w-xl text-base leading-8 text-white/60">{{ __('platform.login.description') }}</p>

                        <div class="mt-10 grid gap-3">
                            @foreach (['tenants', 'subscriptions', 'support'] as $feature)
                                <div class="flex items-center gap-3 rounded-2xl border border-white/8 bg-white/[0.025] px-4 py-3 text-sm text-white/75">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-400/10 text-emerald-300">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </span>
                                    {{ __('platform.login.features.'.$feature) }}
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <p class="flex items-center gap-2 text-xs text-white/40">
                        <svg class="h-4 w-4 text-emerald-300/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3m-11 0h12v9H6v-9Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        {{ __('platform.login.restricted') }}
                    </p>
                </section>

                <section class="flex min-h-svh items-center justify-center p-5 sm:p-8 lg:p-12">
                    <div class="w-full max-w-md">
                        <div class="mb-8 flex items-center justify-between gap-4 lg:hidden">
                            <a href="{{ route('home') }}" class="inline-flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-emerald-300/20 bg-emerald-300/10 font-bold text-emerald-200">A</span>
                                <span class="text-sm font-semibold text-white">{{ __('platform.brand.name') }}</span>
                            </a>
                            <x-locale-switcher compact />
                        </div>

                        <div class="rounded-[2rem] border border-emerald-900/10 bg-white/85 p-6 shadow-2xl shadow-black/10 backdrop-blur-xl dark:border-white/10 dark:bg-[#111914]/90 dark:shadow-black/40 sm:p-8">
                            <div class="mb-7">
                                <span class="inline-flex items-center gap-2 rounded-full border border-emerald-300/15 bg-emerald-300/8 px-3 py-1.5 text-xs font-medium text-emerald-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                                    {{ __('platform.login.restricted') }}
                                </span>
                                <h2 class="font-display mt-5 text-3xl text-white">{{ __('platform.login.title') }}</h2>
                                <p class="mt-2 text-sm leading-6 text-white/50 lg:hidden">{{ __('platform.login.description') }}</p>
                            </div>

                            <form method="POST" action="{{ route('platform.login.store') }}" class="space-y-5" data-platform-login-form>
                                @csrf

                                <label class="block">
                                    <span class="mb-2 block text-sm font-medium text-white/80">{{ __('platform.login.email') }}</span>
                                    <input
                                        type="email"
                                        name="email"
                                        value="{{ old('email') }}"
                                        required
                                        autofocus
                                        autocomplete="email"
                                        class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3.5 text-white outline-none placeholder:text-white/25 focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/10"
                                    >
                                    @error('email')
                                        <span class="mt-2 block text-sm font-medium text-red-300">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label class="block">
                                    <span class="mb-2 block text-sm font-medium text-white/80">{{ __('platform.login.password') }}</span>
                                    <input
                                        type="password"
                                        name="password"
                                        required
                                        autocomplete="current-password"
                                        class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3.5 text-white outline-none placeholder:text-white/25 focus:border-emerald-300/50 focus:ring-2 focus:ring-emerald-300/10"
                                    >
                                    @error('password')
                                        <span class="mt-2 block text-sm font-medium text-red-300">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label class="flex cursor-pointer items-center gap-3 text-sm text-white/60">
                                    <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="h-4 w-4 rounded border-white/20 bg-black/20 text-emerald-600 focus:ring-emerald-500">
                                    <span>{{ __('platform.login.remember') }}</span>
                                </label>

                                <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3.5 font-semibold text-white shadow-lg shadow-emerald-950/40 transition hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-300/50">
                                    {{ __('platform.login.submit') }}
                                    <span aria-hidden="true">→</span>
                                </button>
                            </form>
                        </div>

                        <div class="mt-5 rounded-2xl border border-white/8 bg-white/[0.025] p-4 text-center">
                            <p class="text-sm font-medium text-white/75">{{ __('platform.login.tenant_title') }}</p>
                            <p class="mt-1 text-xs leading-5 text-white/40">{{ __('platform.login.tenant_description') }}</p>
                            <a href="{{ route('login') }}" class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-emerald-300 hover:text-emerald-200">
                                {{ __('platform.login.tenant_action') }}
                                <span aria-hidden="true">→</span>
                            </a>
                        </div>
                    </div>
                </section>
            </div>
        </main>
        @fluxScripts
    </body>
</html>
