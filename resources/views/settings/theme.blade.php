@php
    $themeFields = [
        'primary_color' => ['label' => __('theme.fields.primary'), 'help' => __('theme.fields.primary_help')],
        'action_color' => ['label' => __('theme.fields.action'), 'help' => __('theme.fields.action_help')],
        'light_background_color' => ['label' => __('theme.fields.light_background'), 'help' => __('theme.fields.background_help')],
        'light_surface_color' => ['label' => __('theme.fields.light_surface'), 'help' => __('theme.fields.surface_help')],
        'light_text_color' => ['label' => __('theme.fields.light_text'), 'help' => __('theme.fields.text_help')],
        'dark_background_color' => ['label' => __('theme.fields.dark_background'), 'help' => __('theme.fields.background_help')],
        'dark_surface_color' => ['label' => __('theme.fields.dark_surface'), 'help' => __('theme.fields.surface_help')],
        'dark_text_color' => ['label' => __('theme.fields.dark_text'), 'help' => __('theme.fields.text_help')],
    ];
    $initialColors = collect($colors)->mapWithKeys(fn ($value, $key) => [$key => old($key, $value)])->all();
@endphp

<x-layouts.app :title="__('theme.title')">
    <div class="page-stack">
        <x-settings.admin-nav current="settings.theme.edit" />

        @if(session('status'))
            <div class="rounded-2xl border border-emerald-300 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100" role="status">
                {{ session('status') }}
            </div>
        @endif

        <section
            class="surface-panel overflow-hidden"
            x-data="{
                colors: @js($initialColors),
                rgb(hex) {
                    const value = hex.replace('#', '');
                    if (!/^[0-9a-fA-F]{6}$/.test(value)) return [0, 0, 0];
                    return [0, 2, 4].map(index => parseInt(value.slice(index, index + 2), 16));
                },
                luminance(hex) {
                    const channels = this.rgb(hex).map(channel => {
                        const value = channel / 255;
                        return value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
                    });
                    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
                },
                foreground(hex) { return this.luminance(hex) > 0.179 ? '#000000' : '#ffffff'; },
                normalise(key) {
                    let value = this.colors[key].trim();
                    if (value && !value.startsWith('#')) value = '#' + value;
                    this.colors[key] = value.toLowerCase();
                }
            }"
        >
            <div class="border-b border-white/10 p-5 lg:p-7">
                <div class="eyebrow">{{ __('theme.eyebrow') }}</div>
                <h1 class="font-display mt-3 text-3xl text-white">{{ __('theme.title') }}</h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('theme.subtitle') }}</p>
            </div>

            <div class="grid gap-7 p-5 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.72fr)] lg:p-7">
                <form method="POST" action="{{ route('settings.theme.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach($themeFields as $key => $field)
                            <label class="rounded-2xl border border-white/10 bg-black/10 p-4" for="tenant-theme-{{ $key }}">
                                <span class="block text-sm font-semibold text-white">{{ $field['label'] }}</span>
                                <span class="mt-1 block text-xs leading-5 text-neutral-400">{{ $field['help'] }}</span>
                                <span class="mt-3 flex items-center gap-3">
                                    <input id="tenant-theme-{{ $key }}" type="color" x-model="colors.{{ $key }}" class="h-11 w-14 shrink-0 cursor-pointer rounded-lg border-0 bg-transparent p-0" aria-label="{{ $field['label'] }}">
                                    <input name="{{ $key }}" type="text" x-model="colors.{{ $key }}" x-on:blur="normalise('{{ $key }}')" maxlength="7" dir="ltr" class="min-w-0 flex-1 rounded-xl border px-3 py-2.5 font-mono text-sm" autocomplete="off">
                                </span>
                                @error($key)
                                    <span class="mt-2 block rounded-xl border border-red-400/30 bg-red-500/10 px-3 py-2 text-sm text-red-200" role="alert">{{ $message }}</span>
                                @enderror
                            </label>
                        @endforeach
                    </div>

                    <div class="theme-readability-note rounded-2xl border p-4 text-sm leading-6">
                        <strong class="block">{{ __('theme.readability_title') }}</strong>
                        {{ __('theme.readability_message') }}
                    </div>

                    <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white transition hover:bg-emerald-800">{{ __('theme.save') }}</button>
                </form>

                <div>
                    <h2 class="text-lg font-semibold text-white">{{ __('theme.preview_title') }}</h2>
                    <p class="mt-1 text-xs text-neutral-400">{{ __('theme.preview_help') }}</p>

                    <div class="mt-4 grid gap-4">
                        @foreach(['light', 'dark'] as $appearance)
                            <article
                                class="overflow-hidden rounded-3xl border shadow-xl"
                                x-bind:style="`background:${colors.{{ $appearance }}_background_color};color:${colors.{{ $appearance }}_text_color};border-color:${colors.primary_color}`"
                            >
                                <div class="h-2" x-bind:style="`background:${colors.primary_color}`"></div>
                                <div class="p-5">
                                    <p class="text-xs font-bold uppercase tracking-wider">{{ __('theme.'.$appearance.'_mode') }}</p>
                                    <div class="mt-3 rounded-2xl border p-4" x-bind:style="`background:${colors.{{ $appearance }}_surface_color};border-color:${colors.primary_color}`">
                                        <h3 class="text-xl font-bold">{{ __('theme.preview_heading') }}</h3>
                                        <p class="mt-2 text-sm">{{ __('theme.preview_copy') }}</p>
                                        <button type="button" class="mt-5 rounded-xl px-4 py-2 text-sm font-semibold shadow-sm" x-bind:style="`background:${colors.action_color};color:${foreground(colors.action_color)}`">{{ __('theme.preview_action') }}</button>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="border-t border-white/10 p-5 lg:px-7">
                <form method="POST" action="{{ route('settings.theme.reset') }}" onsubmit="return confirm(@js(__('theme.reset_confirm')))" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    @csrf
                    @method('DELETE')
                    <p class="text-sm text-neutral-400">{{ __('theme.reset_help') }}</p>
                    <button type="submit" class="rounded-xl border border-white/15 px-4 py-2.5 text-sm font-semibold text-white transition hover:border-emerald-400/60 hover:bg-white/5">{{ __('theme.reset') }}</button>
                </form>
            </div>
        </section>
    </div>
</x-layouts.app>
