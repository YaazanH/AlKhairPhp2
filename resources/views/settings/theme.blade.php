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
    <div
        class="page-stack settings-admin-page"
        x-data="{
            colors: @js($initialColors),
            presets: @js($presets),
            editOpen: @js($errors->isNotEmpty()),
            applyPreset(key) { this.colors = { ...this.presets[key] }; },
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
        x-on:keydown.escape.window="editOpen = false"
        data-theme-settings
        data-tenant-theme
    >
        <section class="page-hero p-6 lg:p-8">
            <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('theme.title') }}</h1>
            <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('theme.subtitle') }}</p>
        </section>

        <x-settings.admin-nav current="settings.theme.edit" />

        @if(session('status'))
            <div class="flash-success px-4 py-3 text-sm" role="status">{{ session('status') }}</div>
        @endif

        <section class="surface-panel settings-dark-surface overflow-hidden">
            <div class="admin-toolbar border-b border-white/10 p-5 lg:p-6">
                <div>
                    <div class="admin-toolbar__title">{{ __('theme.presets_title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('theme.presets_help') }}</p>
                </div>
                <div class="admin-toolbar__actions">
                    <button type="button" class="admin-icon-button" x-on:click="editOpen = true" title="{{ __('theme.edit_colors') }}" aria-label="{{ __('theme.edit_colors') }}" data-theme-edit-action>
                        <x-admin-action-icon name="edit" />
                    </button>
                    <button type="submit" form="tenant-theme-form" class="admin-icon-button admin-icon-button--accent" title="{{ __('theme.save') }}" aria-label="{{ __('theme.save') }}" data-theme-save-action>
                        <x-admin-action-icon name="save" />
                    </button>
                </div>
            </div>

            <form id="tenant-theme-form" method="POST" action="{{ route('settings.theme.update') }}" class="grid gap-7 p-5 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.72fr)] lg:p-7">
                @csrf
                @method('PUT')

                @foreach(array_keys($themeFields) as $key)
                    <input type="hidden" name="{{ $key }}" x-bind:value="colors.{{ $key }}">
                @endforeach

                <div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-theme-presets>
                        @foreach($presets as $presetKey => $presetColors)
                            <button
                                type="button"
                                class="group rounded-2xl border p-3 text-start transition hover:-translate-y-0.5 hover:border-white/30"
                                x-on:click="applyPreset('{{ $presetKey }}')"
                                x-bind:class="colors.primary_color === presets.{{ $presetKey }}.primary_color && colors.action_color === presets.{{ $presetKey }}.action_color ? 'border-white/50 bg-white/10 ring-2 ring-white/10' : 'border-white/10 bg-white/5'"
                                x-bind:aria-pressed="(colors.primary_color === presets.{{ $presetKey }}.primary_color && colors.action_color === presets.{{ $presetKey }}.action_color).toString()"
                                data-theme-preset="{{ $presetKey }}"
                            >
                                <span class="block h-12 rounded-xl border border-white/10" style="background: linear-gradient(135deg, {{ $presetColors['primary_color'] }} 0 38%, {{ $presetColors['light_surface_color'] }} 38% 68%, {{ $presetColors['dark_surface_color'] }} 68%)"></span>
                                <span class="mt-3 block text-sm font-semibold text-white">{{ __('theme.presets.'.$presetKey) }}</span>
                                @if($presetKey === 'original')
                                    <span class="mt-1 block text-xs text-neutral-400">{{ __('theme.original_v1') }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    <div class="theme-readability-note mt-5 rounded-2xl border p-4 text-sm leading-6">
                        <strong class="block">{{ __('theme.readability_title') }}</strong>
                        {{ __('theme.readability_message') }}
                    </div>
                </div>

                <div>
                    <h2 class="text-lg font-semibold text-white">{{ __('theme.preview_title') }}</h2>
                    <p class="mt-1 text-xs text-neutral-400">{{ __('theme.preview_help') }}</p>

                    <div class="mt-4 grid gap-4">
                        @foreach(['light', 'dark'] as $appearance)
                            <article class="overflow-hidden rounded-3xl border shadow-xl" x-bind:style="`background:${colors.{{ $appearance }}_background_color};color:${colors.{{ $appearance }}_text_color};border-color:${colors.primary_color}`">
                                <div class="h-2" x-bind:style="`background:${colors.primary_color}`"></div>
                                <div class="p-5">
                                    <p class="text-xs font-bold uppercase tracking-wider">{{ __('theme.'.$appearance.'_mode') }}</p>
                                    <div class="mt-3 rounded-2xl border p-4" x-bind:style="`background:${colors.{{ $appearance }}_surface_color};border-color:${colors.primary_color}`">
                                        <h3 class="text-xl font-bold">{{ __('theme.preview_heading') }}</h3>
                                        <p class="mt-2 text-sm">{{ __('theme.preview_copy') }}</p>
                                        <span class="mt-5 inline-flex rounded-xl px-4 py-2 text-sm font-semibold shadow-sm" x-bind:style="`background:${colors.action_color};color:${foreground(colors.action_color)}`">{{ __('theme.preview_action') }}</span>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            </form>

            <div class="border-t border-white/10 p-5 lg:px-7">
                <form method="POST" action="{{ route('settings.theme.reset') }}" onsubmit="return confirm(@js(__('theme.reset_confirm')))" class="flex items-center justify-between gap-4">
                    @csrf
                    @method('DELETE')
                    <p class="text-sm text-neutral-400">{{ __('theme.reset_help') }}</p>
                    <button type="submit" class="admin-icon-button" title="{{ __('theme.reset') }}" aria-label="{{ __('theme.reset') }}" data-theme-reset-action>
                        <x-admin-action-icon name="restore-defaults" />
                    </button>
                </form>
            </div>
        </section>

        <div x-show="editOpen" x-cloak class="admin-modal" data-theme-editor-modal>
            <div class="admin-modal__backdrop" x-on:click="editOpen = false"></div>
            <div class="admin-modal__viewport" x-on:click.self="editOpen = false">
                <div class="admin-modal__dialog admin-modal__dialog--3xl admin-modal__dialog--compact" role="dialog" aria-modal="true" aria-labelledby="theme-editor-title">
                    <div class="admin-modal__header">
                        <h2 id="theme-editor-title" class="admin-modal__title">{{ __('theme.edit_colors') }}</h2>
                        <div class="admin-modal__header-actions">
                            <button type="submit" form="tenant-theme-form" class="admin-modal__close" title="{{ __('theme.save') }}" aria-label="{{ __('theme.save') }}">
                                <x-admin-action-icon name="save" class="size-5" />
                            </button>
                            <button type="button" class="admin-modal__close" x-on:click="editOpen = false" aria-label="{{ __('crud.common.actions.close') }}">&times;</button>
                        </div>
                    </div>
                    <div class="admin-modal__body">
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach($themeFields as $key => $field)
                                <label class="rounded-2xl border border-white/10 bg-white/5 p-4" for="tenant-theme-{{ $key }}">
                                    <span class="block text-sm font-semibold text-white">{{ $field['label'] }}</span>
                                    <span class="mt-1 block text-xs leading-5 text-neutral-400">{{ $field['help'] }}</span>
                                    <span class="mt-3 flex items-center gap-3">
                                        <input id="tenant-theme-{{ $key }}" type="color" x-model="colors.{{ $key }}" class="h-11 w-14 shrink-0 cursor-pointer rounded-lg border-0 bg-transparent p-0" aria-label="{{ $field['label'] }}">
                                        <input type="text" x-model="colors.{{ $key }}" x-on:blur="normalise('{{ $key }}')" maxlength="7" dir="ltr" class="min-w-0 flex-1 rounded-xl border px-3 py-2.5 font-mono text-sm" autocomplete="off">
                                    </span>
                                    @error($key)<span class="mt-2 block text-sm text-red-300" role="alert">{{ $message }}</span>@enderror
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
