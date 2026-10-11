<x-platform-layout :title="__('platform.ui.metrics.title')">
    @php
        $formatBytes = static function (?int $bytes): string {
            if ($bytes === null) {
                return __('platform.ui.dashboard.metric_unavailable');
            }

            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $unit = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
            $value = $bytes / (1024 ** $unit);

            return number_format($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
        };

        $tenantCounts = $managementMetrics['tenants'];
        $storage = $managementMetrics['storage'];
        $backups = $managementMetrics['backups'];
        $infrastructure = $managementMetrics['infrastructure'];
        $alerts = $managementMetrics['alerts'];
        $hasInfrastructure = in_array($infrastructure['status'], ['available', 'stale'], true);
        $latestBackup = $backups['latest_success_at']
            ? \Illuminate\Support\Carbon::parse($backups['latest_success_at'])
            : null;
        $formatMetric = static function (array $metric, string $value = 'average') use ($formatBytes): string {
            $amount = $metric[$value] ?? null;
            $unit = trim((string) ($metric['unit'] ?? ''));

            if ($amount === null) {
                return __('platform.ui.dashboard.metric_unavailable');
            }

            if (in_array(strtolower($unit), ['b', 'byte', 'bytes'], true)) {
                return $formatBytes((int) $amount);
            }

            return number_format((float) $amount, 1).($unit !== '' ? ' '.$unit : '');
        };
    @endphp

    <header>
        <p class="text-sm font-medium text-emerald-600">{{ __('platform.ui.common.platform_workspace') }}</p>
        <h1 class="text-3xl font-bold">{{ __('platform.ui.metrics.title') }}</h1>
        <p class="mt-1 text-zinc-500">{{ __('platform.ui.metrics.description') }}</p>
    </header>

    @if($alerts !== [])
        <section class="space-y-3" aria-labelledby="platform-alerts-title" data-platform-alerts>
            <h2 id="platform-alerts-title" class="text-xl font-bold">{{ __('platform.ui.dashboard.alerts_title') }}</h2>
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach($alerts as $alert)
                    <div class="rounded-xl border px-4 py-3 {{ $alert['severity'] === 'critical' ? 'border-red-300 bg-red-50 text-red-800' : 'border-amber-300 bg-amber-50 text-amber-800' }}" data-platform-alert="{{ $alert['code'] }}">
                        <p class="text-sm font-semibold">{{ __('platform.ui.dashboard.alerts.'.$alert['code']) }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="space-y-4" data-platform-management-metrics>
        <div>
            <h2 class="text-xl font-bold">{{ __('platform.ui.dashboard.operations_title') }}</h2>
            <p class="mt-1 text-sm text-zinc-500">{{ __('platform.ui.dashboard.operations_description') }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @foreach([
                ['label' => __('platform.ui.dashboard.all'), 'value' => $tenantCounts['total'], 'colour' => 'text-zinc-900'],
                ['label' => __('platform.ui.dashboard.active_tenants'), 'value' => $tenantCounts['active'], 'colour' => 'text-emerald-700'],
                ['label' => __('platform.ui.dashboard.trial_tenants'), 'value' => $tenantCounts['trial'], 'colour' => 'text-sky-700'],
                ['label' => __('platform.ui.dashboard.suspended_tenants'), 'value' => $tenantCounts['suspended'], 'colour' => 'text-amber-700'],
                ['label' => __('platform.ui.dashboard.failed_tenants'), 'value' => $tenantCounts['provisioning_failed'], 'colour' => 'text-red-700'],
            ] as $metric)
                <article class="rounded-2xl border bg-white p-5 shadow-sm">
                    <p class="text-sm text-zinc-500">{{ $metric['label'] }}</p>
                    <p class="mt-2 text-3xl font-bold {{ $metric['colour'] }}">{{ $metric['value'] }}</p>
                </article>
            @endforeach
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <article class="rounded-2xl border bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-zinc-700">{{ __('platform.ui.dashboard.tenant_storage') }}</p>
                <p class="mt-2 text-2xl font-bold" dir="ltr">{{ $formatBytes($storage['tenant_bytes']) }}</p>
                <p class="mt-2 text-sm text-zinc-500">{{ __('platform.ui.dashboard.tenant_storage_help', ['count' => $storage['scanned_tenants']]) }}</p>
                @if($storage['unavailable_tenants'] > 0)
                    <p class="mt-2 text-sm font-medium text-amber-700">{{ __('platform.ui.dashboard.storage_unavailable', ['count' => $storage['unavailable_tenants']]) }}</p>
                @endif
            </article>

            <article class="rounded-2xl border bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm font-semibold text-zinc-700">{{ __('platform.ui.dashboard.server_storage') }}</p>
                    @if($storage['server_used_percent'] !== null)
                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700" dir="ltr">{{ number_format($storage['server_used_percent'], 1) }}%</span>
                    @endif
                </div>
                @if($storage['server_total_bytes'] !== null)
                    <p class="mt-2 text-lg font-bold" dir="ltr">{{ __('platform.ui.dashboard.server_storage_usage', ['used' => $formatBytes($storage['server_used_bytes']), 'total' => $formatBytes($storage['server_total_bytes'])]) }}</p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-100" role="progressbar" aria-valuenow="{{ $storage['server_used_percent'] }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-emerald-600" style="width: {{ min(100, $storage['server_used_percent']) }}%"></div>
                    </div>
                    <p class="mt-2 text-sm text-zinc-500" dir="ltr">{{ __('platform.ui.dashboard.server_storage_free', ['free' => $formatBytes($storage['server_free_bytes'])]) }}</p>
                @else
                    <p class="mt-2 text-lg font-bold">{{ __('platform.ui.dashboard.metric_unavailable') }}</p>
                @endif
            </article>

            <article class="rounded-2xl border bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-zinc-700">{{ __('platform.ui.dashboard.backup_health') }}</p>
                <p class="mt-2 text-lg font-bold">
                    {{ $latestBackup ? $latestBackup->translatedFormat('Y-m-d H:i') : __('platform.ui.dashboard.no_successful_backup') }}
                </p>
                <p class="mt-2 text-sm {{ $backups['failed_last_24_hours'] > 0 ? 'font-medium text-red-700' : 'text-zinc-500' }}">
                    {{ __('platform.ui.dashboard.failed_backups_day', ['count' => $backups['failed_last_24_hours']]) }}
                </p>
            </article>
        </div>

        <article class="rounded-2xl border bg-white p-5 shadow-sm" data-hostinger-infrastructure="{{ $infrastructure['status'] }}">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="font-semibold">{{ __('platform.ui.dashboard.infrastructure_title') }}</p>
                    @if($hasInfrastructure)
                        <p class="mt-1 text-sm text-zinc-500">
                            {{ __('platform.ui.dashboard.infrastructure_server', ['hostname' => $infrastructure['hostname'] ?? '—', 'plan' => $infrastructure['plan'] ?? '—']) }}
                        </p>
                    @else
                        <p class="mt-1 text-sm text-zinc-500">
                            {{ __('platform.ui.dashboard.'.($infrastructure['status'] === 'unconfigured' ? 'infrastructure_pending' : 'infrastructure_unavailable')) }}
                        </p>
                    @endif
                </div>
                @if($hasInfrastructure)
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ $infrastructure['state'] ?? __('platform.ui.dashboard.metric_unavailable') }}</span>
                @endif
            </div>

            @if($hasInfrastructure)
                @if($infrastructure['status'] === 'stale')
                    <p class="mt-3 text-sm font-medium text-amber-700">{{ __('platform.ui.dashboard.infrastructure_stale') }}</p>
                @endif
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl bg-zinc-50 p-4">
                        <p class="text-xs font-semibold text-zinc-500">{{ __('platform.ui.dashboard.cpu_day') }}</p>
                        <p class="mt-1 text-xl font-bold" dir="ltr">{{ $formatMetric($infrastructure['cpu']) }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ __('platform.ui.dashboard.cpu_capacity', ['count' => $infrastructure['capacity']['cpus'] ?? '—']) }}</p>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-4">
                        <p class="text-xs font-semibold text-zinc-500">{{ __('platform.ui.dashboard.ram_day') }}</p>
                        <p class="mt-1 text-xl font-bold" dir="ltr">{{ $formatMetric($infrastructure['ram']) }}</p>
                        <p class="mt-1 text-xs text-zinc-500" dir="ltr">{{ __('platform.ui.dashboard.ram_capacity', ['size' => $formatBytes($infrastructure['capacity']['memory_bytes'])]) }}</p>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-4">
                        <p class="text-xs font-semibold text-zinc-500">{{ __('platform.ui.dashboard.incoming_month') }}</p>
                        <p class="mt-1 text-xl font-bold" dir="ltr">{{ $formatMetric($infrastructure['incoming'], 'total') }}</p>
                    </div>
                    <div class="rounded-xl bg-zinc-50 p-4">
                        <p class="text-xs font-semibold text-zinc-500">{{ __('platform.ui.dashboard.outgoing_month') }}</p>
                        <p class="mt-1 text-xl font-bold" dir="ltr">{{ $formatMetric($infrastructure['outgoing'], 'total') }}</p>
                        <p class="mt-1 text-xs text-zinc-500" dir="ltr">{{ __('platform.ui.dashboard.bandwidth_capacity', ['size' => $formatBytes($infrastructure['capacity']['bandwidth_bytes'])]) }}</p>
                    </div>
                </div>
            @endif
        </article>
    </section>
</x-platform-layout>
