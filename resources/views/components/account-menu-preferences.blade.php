@php
    $supportedLocales = config('app.supported_locales', []);
    $currentLocale = app()->getLocale();
@endphp

<div class="account-menu-preferences" x-data>
    <div class="account-menu-preferences__group">
        <div class="account-preference-switch account-preference-switch--language" role="radiogroup" aria-label="{{ __('ui.common.language') }}">
            @foreach ($supportedLocales as $localeCode => $localeConfig)
                <a
                    href="{{ route('locale.switch', $localeCode) }}"
                    @class(['account-preference-switch__option', 'is-active' => $currentLocale === $localeCode])
                    role="radio"
                    aria-checked="{{ $currentLocale === $localeCode ? 'true' : 'false' }}"
                    hreflang="{{ $localeCode }}"
                >
                    {{ $localeConfig['native'] }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="account-menu-preferences__group">
        <div class="account-preference-switch account-preference-switch--appearance" role="radiogroup" aria-label="{{ __('settings.account.appearance.form_title') }}">
            @foreach (['dark', 'light', 'system'] as $appearance)
                <button
                    type="button"
                    class="account-preference-switch__option"
                    x-on:click="$flux.appearance = '{{ $appearance }}'"
                    x-bind:class="{ 'is-active': $flux.appearance === '{{ $appearance }}' }"
                    x-bind:aria-checked="($flux.appearance === '{{ $appearance }}').toString()"
                    aria-label="{{ __('settings.account.appearance.options.'.$appearance) }}"
                    title="{{ __('settings.account.appearance.options.'.$appearance) }}"
                    role="radio"
                >
                    @if ($appearance === 'dark')
                        <flux:icon.moon class="size-4" aria-hidden="true" />
                    @elseif ($appearance === 'light')
                        <flux:icon.sun class="size-4" aria-hidden="true" />
                    @else
                        <flux:icon.computer-desktop class="size-4" aria-hidden="true" />
                    @endif
                </button>
            @endforeach
        </div>
    </div>
</div>
