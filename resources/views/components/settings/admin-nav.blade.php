@props([
    'section' => null,
    'current' => null,
])

@php
    $resolvedCurrent = $current ?? request()->route()?->getName();
    $resolvedSection = $section
        ?? (in_array($resolvedCurrent, ['settings.website', 'settings.website.pages', 'settings.website.navigation'], true) ? 'website' : 'dashboard');
    $showWebsiteSettings = $resolvedSection === 'website';
    $showBarcodeSettings = ! $showWebsiteSettings
        && auth()->user()?->can('barcode-actions.view')
        && (bool) (\App\Models\AppSetting::groupValues('dashboard')->get('barcode_scanner_enabled') ?? true);
@endphp

<div class="surface-panel p-4">
    <div class="grid gap-4">
        @unless($showWebsiteSettings)
        <section aria-label="{{ __('ui.common.settings') }}">
            <div class="settings-tabs {{ $showBarcodeSettings ? 'settings-tabs--barcode-active' : '' }}" data-dashboard-settings-tabs>
                <a href="{{ route('settings.organization') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.organization' ? 'is-active' : '' }}">
                    <span class="settings-tab__title">{{ __('settings.navigation.organization.title') }}</span>
                </a>
                <a href="{{ route('settings.points') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.points' ? 'is-active' : '' }}">
                    <span class="settings-tab__title">{{ __('settings.navigation.tracking.title') }}</span>
                </a>
                @can('course-completion-rules.manage')
                    <a href="{{ route('settings.course-completion') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.course-completion' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('settings.navigation.completion.title') }}</span>
                    </a>
                @endcan
                @can('curricula.manage')
                    <a href="{{ route('settings.curriculum-subjects') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.curriculum-subjects' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('curricula.settings.title') }}</span>
                    </a>
                @endcan
                @can('barcode-actions.view')
                @if ($showBarcodeSettings)
                    <a href="{{ route('barcode-actions.index') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'barcode-actions.index' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('settings.navigation.barcodes.title') }}</span>
                    </a>
                @endif
                @endcan
                @can('roles.manage')
                    <a href="{{ route('settings.access-control') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.access-control' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('settings.navigation.access.title') }}</span>
                    </a>
                @endcan
                @can('sidebar-navigation.manage')
                    <a href="{{ route('settings.sidebar-navigation') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.sidebar-navigation' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('settings.navigation.sidebar.title') }}</span>
                    </a>
                @endcan
                @can('backups.manage')
                    <a href="{{ route('settings.backups') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.backups' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('backups.navigation_title') }}</span>
                    </a>
                @endcan
            </div>
        </section>
        @endunless

        @if($showWebsiteSettings)
        @can('website.manage')
            <section aria-label="{{ __('ui.nav.public_website_settings') }}">
                <div class="settings-tabs">
                    <a href="{{ route('settings.website') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.website' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('site.admin.nav.website') }}</span>
                    </a>
                    <a href="{{ route('settings.website.pages') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.website.pages' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('site.admin.nav.pages') }}</span>
                    </a>
                    <a href="{{ route('settings.website.navigation') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.website.navigation' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('site.admin.nav.menus') }}</span>
                    </a>
                </div>
            </section>
        @endcan
        @endif
    </div>
</div>
