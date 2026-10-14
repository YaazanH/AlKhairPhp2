<x-platform-layout :title="__('platform-workspace.destinations.settings')">
    <div class="platform-management" data-platform-settings>
        <header class="platform-management__header">
            <p class="text-sm font-semibold text-emerald-700">{{ __('platform-workspace.groups.administration') }}</p>
            <h1 class="mt-2 font-bold">{{ __('platform-workspace.destinations.settings') }}</h1>
            <p class="mt-3 text-zinc-500">{{ __('platform-workspace.settings_intro') }}</p>
        </header>
        <div class="platform-settings-layout">
            <nav class="platform-settings-tabs" aria-label="{{ __('platform-workspace.settings_sections') }}">
                <a href="#branding">{{ __('platform-workspace.branding') }}</a>
                <a href="#preferences">{{ __('platform-workspace.preferences') }}</a>
                @if($canViewSubscriptions)<a href="#subscriptions">{{ __('platform-workspace.subscription_policy') }}</a>@endif
                @if($canManageBackups)<a href="#backups">{{ __('platform-workspace.backup_policy') }}</a>@endif
                @if($canManageMetrics)<a href="#monitoring">{{ __('platform-workspace.monitoring') }}</a>@endif
                @if($canManageRequests)<a href="#requests">{{ __('platform-workspace.request_options') }}</a>@endif
            </nav>
            <div class="platform-settings-sections">
                <section id="branding" class="platform-settings-panel" data-platform-branding>
                    <h2>{{ __('platform-workspace.branding') }}</h2>
                    <p>{{ __('platform-workspace.branding_help') }}</p>
                    <div class="mt-5 grid gap-5 md:grid-cols-[minmax(0,1fr)_10rem] md:items-center">
                        <x-platform-brand class="w-full max-w-xl rounded-2xl border border-zinc-200 object-contain" />
                        <div>
                            <x-platform-brand variant="icon" class="mx-auto h-28 w-28 rounded-3xl object-cover shadow-sm" />
                            <p class="mt-3 text-center text-xs text-zinc-500">{{ __('platform-workspace.branding_icon_help') }}</p>
                        </div>
                    </div>
                </section>
                <section id="preferences" class="platform-settings-panel">
                    <h2>{{ __('platform-workspace.preferences') }}</h2>
                    <p>{{ __('platform-workspace.preferences_help') }}</p>
                    <x-account-menu-preferences />
                </section>
                @if($canViewSubscriptions)
                    <section id="subscriptions" class="platform-settings-panel">
                        <h2>{{ __('platform-workspace.subscription_policy') }}</h2>
                        <p>{{ __('platform.ui.subscription_settings.retention_help') }}</p>
                        @if($canManageSubscriptions)
                            <form method="POST" action="{{ route('platform.subscription-settings.update') }}">
                                @csrf @method('PUT')
                                <label>{{ __('platform.ui.subscription_settings.retention') }}<input name="suspended_data_retention_months" type="number" min="1" max="120" required value="{{ old('suspended_data_retention_months', $settings->suspended_data_retention_months) }}"></label>
                                <button>{{ __('platform.ui.subscription_settings.save') }}</button>
                            </form>
                        @else
                            <div class="platform-settings-readonly">{{ __('platform.ui.subscription_settings.readonly', ['months' => $settings->suspended_data_retention_months]) }}</div>
                        @endif
                    </section>
                @endif
                @if($canManageBackups)
                    <section id="backups" class="platform-settings-panel">
                        <h2>{{ __('platform-workspace.backup_policy') }}</h2>
                        <p>{{ __('platform-workspace.backup_help') }}</p>
                        <form method="POST" action="{{ route('platform.backups.settings.update') }}">
                            @csrf @method('PUT')
                            <label class="platform-settings-check"><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked((bool) old('is_enabled', $backupSettings->is_enabled))>{{ __('platform.ui.backups.enable') }}</label>
                            <div class="platform-settings-fields">
                                <label>{{ __('platform.ui.backups.run_at') }}<input type="time" name="run_at" required value="{{ old('run_at', substr($backupSettings->run_at, 0, 5)) }}"></label>
                                <label>{{ __('platform.ui.backups.retention') }}<input type="number" name="retention_count" min="1" max="365" required value="{{ old('retention_count', $backupSettings->retention_count) }}"><small>{{ __('platform.ui.backups.retention_help') }}</small></label>
                            </div>
                            <button>{{ __('platform.ui.backups.save') }}</button>
                        </form>
                    </section>
                @endif
                @if($canManageMetrics)
                    <section id="monitoring" class="platform-settings-panel">
                        <h2>{{ __('platform-workspace.monitoring') }}</h2>
                        <p>{{ __('platform-workspace.monitoring_help') }}</p>
                        <form method="POST" action="{{ route('platform.metrics.settings.update') }}">
                            @csrf @method('PUT')
                            <label>{{ __('platform-workspace.refresh_interval') }}<select name="metrics_refresh_minutes" required>@foreach([1, 5, 10, 15, 30] as $minutes)<option value="{{ $minutes }}" @selected((int) old('metrics_refresh_minutes', $settings->metrics_refresh_minutes) === $minutes)>{{ __('platform-workspace.minutes', ['count' => $minutes]) }}</option>@endforeach</select></label>
                            <button>{{ __('platform-workspace.save_monitoring') }}</button>
                        </form>
                    </section>
                @endif
                @if($canManageRequests)
                    <section id="requests" class="platform-settings-panel">
                        <h2>{{ __('platform-workspace.request_options') }}</h2>
                        <p>{{ __('platform-workspace.requests_help') }}</p>
                        <a class="platform-settings-link" href="{{ route('platform.support.settings.edit') }}">{{ __('platform-workspace.edit_request_options') }}</a>
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-platform-layout>
