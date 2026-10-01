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
                colour: @js(old('primary_color', $primaryColor)),
                rgb(hex) {
                    const value = hex.replace('#', '');
                    if (!/^[0-9a-fA-F]{6}$/.test(value)) return [11, 143, 67];
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
                normalise() {
                    let value = this.colour.trim();
                    if (value && !value.startsWith('#')) value = '#' + value;
                    this.colour = value.toLowerCase();
                }
            }"
        >
            <div class="border-b border-white/10 p-5 lg:p-7">
                <div class="eyebrow">{{ __('theme.eyebrow') }}</div>
                <h1 class="font-display mt-3 text-3xl text-white">{{ __('theme.title') }}</h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('theme.subtitle') }}</p>
            </div>

            <div class="grid gap-7 p-5 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:p-7">
                <form method="POST" action="{{ route('settings.theme.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="tenant-primary-colour" class="block text-sm font-semibold text-white">{{ __('theme.primary_label') }}</label>
                        <p class="mt-1 text-xs leading-6 text-neutral-400">{{ __('theme.primary_help') }}</p>
                        <div class="mt-3 flex items-center gap-3 rounded-2xl border border-white/10 bg-black/10 p-3">
                            <input id="tenant-primary-colour" type="color" x-model="colour" class="h-12 w-16 cursor-pointer rounded-xl border-0 bg-transparent p-0" aria-label="{{ __('theme.picker_label') }}">
                            <input name="primary_color" type="text" x-model="colour" x-on:blur="normalise" maxlength="7" dir="ltr" class="min-w-0 flex-1 rounded-xl border px-4 py-3 font-mono text-sm" placeholder="#0b8f43" autocomplete="off">
                        </div>
                        @error('primary_color')
                            <p class="mt-2 rounded-xl border border-red-400/30 bg-red-500/10 px-3 py-2 text-sm text-red-200" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="rounded-2xl border border-sky-400/20 bg-sky-500/10 p-4 text-sm leading-6 text-sky-100">
                        <strong class="block text-white">{{ __('theme.readability_title') }}</strong>
                        {{ __('theme.readability_message') }}
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white transition hover:bg-emerald-800">{{ __('theme.save') }}</button>
                    </div>
                </form>

                <div>
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">{{ __('theme.preview_title') }}</h2>
                            <p class="mt-1 text-xs text-neutral-400">{{ __('theme.preview_help') }}</p>
                        </div>
                        <span class="rounded-full border border-white/10 px-3 py-1 font-mono text-xs text-neutral-300" x-text="colour"></span>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <article class="overflow-hidden rounded-3xl border border-black/10 bg-[#fbfaf4] text-[#112b1c] shadow-xl">
                            <div class="h-2" x-bind:style="`background:${colour}`"></div>
                            <div class="p-5">
                                <p class="text-xs font-bold uppercase tracking-wider" x-bind:style="`color:${colour}`">{{ __('theme.light_mode') }}</p>
                                <h3 class="mt-3 text-xl font-bold">{{ __('theme.preview_heading') }}</h3>
                                <p class="mt-2 text-sm text-[#48614f]">{{ __('theme.preview_copy') }}</p>
                                <button type="button" class="mt-5 rounded-xl px-4 py-2 text-sm font-semibold shadow-sm" x-bind:style="`background:${colour};color:${foreground(colour)}`">{{ __('theme.preview_action') }}</button>
                            </div>
                        </article>

                        <article class="overflow-hidden rounded-3xl border border-white/10 bg-[#04160b] text-[#f3fff6] shadow-xl">
                            <div class="h-2" x-bind:style="`background:${colour}`"></div>
                            <div class="p-5">
                                <p class="text-xs font-bold uppercase tracking-wider" x-bind:style="`color:${colour}`">{{ __('theme.dark_mode') }}</p>
                                <h3 class="mt-3 text-xl font-bold">{{ __('theme.preview_heading') }}</h3>
                                <p class="mt-2 text-sm text-[#accdb5]">{{ __('theme.preview_copy') }}</p>
                                <button type="button" class="mt-5 rounded-xl px-4 py-2 text-sm font-semibold shadow-sm" x-bind:style="`background:${colour};color:${foreground(colour)}`">{{ __('theme.preview_action') }}</button>
                            </div>
                        </article>
                    </div>

                    <div class="mt-4 rounded-2xl border border-white/10 bg-black/10 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ __('theme.derived_palette') }}</p>
                        <div class="mt-3 grid grid-cols-6 overflow-hidden rounded-xl" aria-hidden="true">
                            @foreach([92, 76, 58, 38, 18, 0] as $whiteMix)
                                <span class="h-10" x-bind:style="`background:color-mix(in srgb, ${colour} ${100 - {{ $whiteMix }}}%, white {{ $whiteMix }}%)`"></span>
                            @endforeach
                        </div>
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
