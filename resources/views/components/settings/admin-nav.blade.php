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
    $showPointsSettings = app(\App\Services\Landlord\CurrentModuleAccess::class)->enabled('points_rewards');
    $moduleAccess = app(\App\Services\Landlord\CurrentModuleAccess::class);
    $showAttendanceSettings = $moduleAccess->enabled('student_attendance') || $moduleAccess->enabled('teacher_attendance');
    $showAssessmentSettings = $moduleAccess->enabled('assessments');
    $showLearningPathSettings = auth()->user()?->can('learning-progression.manage')
        || (auth()->user()?->can('settings.manage')
            && ($showAttendanceSettings || $showAssessmentSettings || $showPointsSettings));
    $showBackupSettings = auth()->user()?->can('backups.manage') || auth()->user()?->can('storage.view');
@endphp

<div class="surface-panel p-4">
    <div class="grid gap-4">
        @unless($showWebsiteSettings)
        <section aria-label="{{ __('ui.common.settings') }}">
            <div class="settings-tabs {{ $showBarcodeSettings ? 'settings-tabs--barcode-active' : '' }}" data-dashboard-settings-tabs>
                @can('settings.manage')
                    <a href="{{ route('settings.organization') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.organization' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('settings.navigation.organization.title') }}</span>
                    </a>
                @endcan
                @if(auth()->user()?->is_tenant_administrator || auth()->user()?->can('settings.manage'))
                    <a href="{{ route('settings.theme.edit') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.theme.edit' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('theme.navigation') }}</span>
                    </a>
                @endif
                @if ($showLearningPathSettings)
                    <a href="{{ route('settings.learning-progression') }}" wire:navigate class="settings-tab {{ in_array($resolvedCurrent, ['settings.learning-progression', 'settings.points', 'settings.attendance-statuses', 'settings.assessment-types'], true) ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('learning_progression.navigation') }}</span>
                    </a>
                @endif
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
                @if($showBackupSettings)
                    <a href="{{ route('settings.backups') }}" wire:navigate class="settings-tab {{ $resolvedCurrent === 'settings.backups' ? 'is-active' : '' }}">
                        <span class="settings-tab__title">{{ __('backups.navigation_title') }}</span>
                    </a>
                @endif
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
