@php
    $themeFields = [
        'primary_color' => __('theme.fields.primary'),
        'action_color' => __('theme.fields.action'),
        'light_background_color' => __('theme.fields.light_background'),
        'light_surface_color' => __('theme.fields.light_surface'),
        'light_text_color' => __('theme.fields.light_text'),
        'dark_background_color' => __('theme.fields.dark_background'),
        'dark_surface_color' => __('theme.fields.dark_surface'),
        'dark_text_color' => __('theme.fields.dark_text'),
    ];
    $initialColors = collect($colors)->mapWithKeys(fn ($value, $key) => [$key => old($key, $value)])->all();
@endphp

<x-layouts.app :title="__('theme.navigation')">
    <div class="page-stack settings-admin-page theme-settings"
        x-data="{
            colors: @js($initialColors), presets: @js($presets),
            editOpen: @js($errors->isNotEmpty()), appearance: 'light',
            applyPreset(key) { this.colors = { ...this.presets[key] }; },
            matchesPreset(key) { return Object.keys(this.presets[key]).every(field => this.colors[field] === this.presets[key][field]); },
            foreground(hex) {
                const channels = [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255)
                    .map(value => value <= 0.04045 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4));
                return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2] > 0.179 ? '#000000' : '#ffffff';
            },
            normalise(key) {
                let value = this.colors[key].trim();
                if (value && !value.startsWith('#')) value = '#' + value;
                this.colors[key] = value.toLowerCase();
            }
        }" data-theme-settings data-tenant-theme>
        <section class="page-hero p-6 lg:p-8">
            <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('theme.navigation') }}</h1>
        </section>

        @if(session('status'))
            <div class="flash-success px-4 py-3 text-sm" role="status">{{ session('status') }}</div>
        @endif

        <div class="theme-workbench">
            <form id="tenant-theme-form" method="POST" action="{{ route('settings.theme.update') }}" class="surface-panel theme-catalog overflow-hidden">
                <div class="admin-grid-meta items-center" data-mobile-title-action-row>
                    <div class="admin-grid-meta__title">{{ __('theme.presets_title') }}</div>
                    <div class="admin-toolbar__actions">
                        <button type="submit" form="tenant-theme-reset-form" class="admin-icon-button" title="{{ __('theme.reset') }}" aria-label="{{ __('theme.reset') }}" data-theme-reset-action><x-admin-action-icon name="restore-defaults" /></button>
                        <button type="button" class="admin-icon-button" x-on:click="editOpen = true" title="{{ __('theme.edit_colors') }}" aria-label="{{ __('theme.edit_colors') }}" data-theme-edit-action><x-admin-action-icon name="edit" /></button>
                        <button type="submit" form="tenant-theme-form" class="admin-icon-button admin-icon-button--accent" title="{{ __('theme.save') }}" aria-label="{{ __('theme.save') }}" data-theme-save-action><x-admin-action-icon name="save" /></button>
                    </div>
                </div>
                @csrf
                @method('PUT')
                @foreach(array_keys($themeFields) as $key)
                    <input type="hidden" name="{{ $key }}" x-bind:value="colors.{{ $key }}">
                @endforeach
                <div class="theme-choices" data-theme-presets>
                    @foreach($presets as $presetKey => $presetColors)
                        <button type="button" class="theme-choice" x-on:click="applyPreset('{{ $presetKey }}')"
                            x-bind:aria-pressed="matchesPreset('{{ $presetKey }}').toString()" data-theme-preset="{{ $presetKey }}">
                            <span class="theme-choice__window" aria-hidden="true" style="--choice-brand: {{ $presetColors['primary_color'] }}; --choice-page: {{ $presetColors['light_background_color'] }}; --choice-surface: {{ $presetColors['light_surface_color'] }}; --choice-sidebar: {{ $presetColors['dark_surface_color'] }}">
                                <span class="theme-choice__bar"></span>
                                <span class="theme-choice__layout"><span class="theme-choice__rail"></span><span class="theme-choice__canvas"><span></span><span></span><span></span></span></span>
                            </span>
                            <span class="theme-choice__label">
                                <span>{{ __('theme.presets.'.$presetKey) }}</span>
                                <svg x-show="matchesPreset('{{ $presetKey }}')" x-cloak viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 10 4 4 8-8" /></svg>
                            </span>
                        </button>
                    @endforeach
                </div>
            </form>

            <aside class="surface-panel theme-preview-panel" data-theme-live-preview>
                <div class="theme-appearance-switch" role="group" aria-label="{{ __('theme.preview_title') }}">
                    @foreach(['light', 'dark'] as $mode)
                        <button type="button" x-on:click="appearance = '{{ $mode }}'" x-bind:aria-pressed="(appearance === '{{ $mode }}').toString()" data-theme-preview-mode="{{ $mode }}">{{ __('theme.'.$mode.'_mode') }}</button>
                    @endforeach
                </div>
                <div class="theme-preview-window" x-bind:style="`--preview-brand:${colors.primary_color};--preview-page:${colors[appearance + '_background_color']};--preview-surface:${colors[appearance + '_surface_color']};--preview-text:${colors[appearance + '_text_color']};--preview-action:${colors.action_color};--preview-on-action:${foreground(colors.action_color)}`">
                    <div class="theme-preview-window__header">{{ app(\App\Support\BrandIdentity::class)->currentName() }}</div>
                    <div class="theme-preview-window__layout">
                        <div class="theme-preview-window__nav"><span>{{ __('ui.nav.dashboard') }}</span><span>{{ __('ui.nav.students') }}</span><span>{{ __('ui.nav.enrollments') }}</span></div>
                        <div class="theme-preview-window__main">
                            <h2>{{ __('ui.nav.dashboard') }}</h2>
                            <div class="theme-preview-window__surface" aria-hidden="true"><span></span><span></span><span></span></div>
                            <span class="theme-preview-window__action">{{ __('crud.common.actions.save') }}</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <form id="tenant-theme-reset-form" method="POST" action="{{ route('settings.theme.reset') }}" onsubmit="return confirm(@js(__('theme.reset_confirm')))">
            @csrf
            @method('DELETE')
        </form>

        <dialog x-ref="themeEditor" class="theme-editor admin-modal__dialog admin-modal__dialog--compact"
            x-effect="if (editOpen && !$el.open) $el.showModal(); else if (!editOpen && $el.open) $el.close()"
            x-on:close="editOpen = false" x-on:cancel="editOpen = false"
            x-on:click="if ($event.target === $el) editOpen = false"
            aria-labelledby="theme-editor-title" data-theme-editor-modal>
            <div class="admin-modal__header">
                <h2 id="theme-editor-title" class="admin-modal__title">{{ __('theme.edit_colors') }}</h2>
                <div class="admin-modal__header-actions">
                    <button type="button" class="admin-modal__close" x-on:click="applyPreset('original')" title="{{ __('theme.reset') }}" aria-label="{{ __('theme.reset') }}" data-theme-editor-reset-action><x-admin-action-icon name="restore-defaults" class="size-5" /></button>
                    <button type="submit" form="tenant-theme-form" class="admin-modal__close" title="{{ __('theme.save') }}" aria-label="{{ __('theme.save') }}" data-theme-editor-save-action><x-admin-action-icon name="save" class="size-5" /></button>
                </div>
            </div>
            <div class="admin-modal__body">
                <div class="theme-editor-fields">
                    @foreach($themeFields as $key => $label)
                        <label class="theme-color-field" for="tenant-theme-{{ $key }}">
                            <span>{{ $label }}</span>
                            <span class="theme-color-field__inputs">
                                <input id="tenant-theme-{{ $key }}" type="color" x-model="colors.{{ $key }}" aria-label="{{ $label }}">
                                <input type="text" x-model="colors.{{ $key }}" x-on:blur="normalise('{{ $key }}')" maxlength="7" dir="ltr" autocomplete="off" aria-label="{{ $label }}">
                            </span>
                            @error($key)<span class="text-sm text-red-300" role="alert">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                </div>
            </div>
        </dialog>
    </div>
</x-layouts.app>
