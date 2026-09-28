<x-layouts.app :title="__('onboarding.title')">
    <div class="mx-auto w-full max-w-5xl space-y-6" data-tenant-setup>
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('onboarding.eyebrow') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('onboarding.title') }}</h1>
            <p class="mt-4 max-w-3xl text-neutral-200">{{ __('onboarding.description') }}</p>
        </section>

        @if (session('status'))
            <div class="rounded-xl border border-emerald-300/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl border border-red-300/30 bg-red-400/10 px-4 py-3 text-sm text-red-100">{{ $errors->first() }}</div>
        @endif

        @php($foundation = $setup['modules']['foundation'] ?? null)
        <section class="surface-panel p-5 lg:p-6" data-setup-foundation>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-300">{{ __('onboarding.required') }}</div>
                    <h2 class="mt-2 text-2xl font-semibold text-white">{{ __('onboarding.foundation.title') }}</h2>
                    <p class="mt-2 text-sm text-neutral-300">{{ __('onboarding.foundation.description') }}</p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ ($foundation['status'] ?? '') === 'ready' ? 'bg-emerald-400/15 text-emerald-200' : 'bg-amber-400/15 text-amber-200' }}">
                    {{ ($foundation['status'] ?? '') === 'ready' ? __('onboarding.ready') : __('onboarding.needs_setup') }}
                </span>
            </div>

            <form method="POST" action="{{ route('tenant-setup.foundation') }}" class="mt-6 grid gap-5 md:grid-cols-2">
                @csrf
                @method('PATCH')
                <label class="block md:col-span-2">
                    <span class="mb-2 block text-sm font-medium text-neutral-200">{{ __('onboarding.foundation.name') }}</span>
                    <input name="school_name" value="{{ old('school_name', $settings->get('school_name') ?: $tenant->name) }}" required class="w-full rounded-xl border border-white/10 bg-black/20 px-4 py-3 text-white">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-neutral-200">{{ __('onboarding.foundation.language') }}</span>
                    <select name="default_locale" required class="w-full rounded-xl border border-white/10 bg-neutral-900 px-4 py-3 text-white">
                        @foreach (config('app.supported_locales', []) as $code => $locale)
                            <option value="{{ $code }}" @selected(old('default_locale', $settings->get('default_locale') ?: app()->getLocale()) === $code)>{{ $locale['name'] ?? strtoupper($code) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-neutral-200">{{ __('onboarding.foundation.timezone') }}</span>
                    <select name="school_timezone" required class="w-full rounded-xl border border-white/10 bg-neutral-900 px-4 py-3 text-white">
                        @foreach ($timezoneOptions as $option)
                            <option value="{{ $option['value'] }}" @selected(old('school_timezone', $settings->get('school_timezone') ?: config('app.timezone')) === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="md:col-span-2 flex justify-end">
                    <button class="pill-link" type="submit">{{ __('onboarding.foundation.save') }}</button>
                </div>
            </form>
        </section>

        @if (($foundation['status'] ?? '') === 'ready')
            <section class="surface-panel p-5 lg:p-6" data-setup-modules>
                <h2 class="text-2xl font-semibold text-white">{{ __('onboarding.modules.title') }}</h2>
                <p class="mt-2 text-sm text-neutral-300">{{ __('onboarding.modules.description') }}</p>
                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    @foreach ($setup['modules'] as $code => $module)
                        @continue($code === 'foundation')
                        <article class="rounded-2xl border border-white/10 bg-black/15 p-4" data-setup-module="{{ $code }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-white">{{ __('onboarding.modules.names.'.$code) }}</h3>
                                    @if ($module['is_new_version'])<p class="mt-1 text-xs text-amber-200">{{ __('onboarding.modules.updated') }}</p>@endif
                                </div>
                                <span class="rounded-full bg-white/10 px-2.5 py-1 text-xs text-neutral-200">{{ __('onboarding.status.'.$module['status']) }}</span>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @if ($module['settings_route'] && Route::has($module['settings_route']))
                                    <a href="{{ route($module['settings_route']) }}" class="pill-link pill-link--compact">{{ __('onboarding.modules.open_settings') }}</a>
                                @endif
                                @if (! in_array($module['status'], ['ready', 'skipped'], true))
                                    <form method="POST" action="{{ route('tenant-setup.module', $code) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="ready">
                                        <button class="pill-link pill-link--compact" type="submit">{{ __('onboarding.modules.mark_ready') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('tenant-setup.module', $code) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="skipped">
                                        <button class="pill-link pill-link--compact" type="submit">{{ __('onboarding.modules.skip') }}</button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <div class="flex flex-wrap justify-end gap-3">
                @if ($setup['status'] === 'ready')
                    <a href="{{ route('dashboard') }}" class="pill-link">{{ __('onboarding.open_dashboard') }}</a>
                @else
                    <form method="POST" action="{{ route('tenant-setup.finish') }}">
                        @csrf
                        <button class="pill-link" type="submit">{{ __('onboarding.skip_remaining') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</x-layouts.app>
