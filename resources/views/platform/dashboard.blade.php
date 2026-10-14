<x-platform-layout :title="__('platform-workspace.destinations.overview')">
    @php($user = auth('platform')->user())
    @php($canTenants = $user->hasPlatformPermission('view.tenants') || $user->hasPlatformPermission('manage.tenants'))
    <div class="platform-overview" data-platform-overview>
        <header class="platform-overview__header flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div><p class="text-sm font-medium text-emerald-600">{{ __('platform.ui.common.platform_workspace') }}</p><h1 class="font-bold">{{ __('platform-workspace.destinations.overview') }}</h1><p class="mt-3 text-zinc-500">{{ __('platform-overview.intro') }}</p></div>
            @if($canTenants)<a href="{{ route('platform.tenants.index') }}" class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">{{ __('platform-overview.manage_tenants') }}</a>@endif
        </header>
        <section class="platform-overview__stats" aria-label="{{ __('platform-design.summary') }}">
            @php($cards = [['total', $tenantStatusCounts->sum(), null], ['active', $tenantStatusCounts->get('active', 0), 'active'], ['trial', $tenantStatusCounts->get('trial', 0), 'trial'], ['attention', collect(['suspended','expired','provisioning_failed'])->sum(fn($status) => $tenantStatusCounts->get($status, 0)), 'attention']])
            @foreach($cards as [$key, $count, $status])
                <article class="platform-overview__stat platform-overview__stat--{{ $key }}"><span>{{ __('platform-design.'.$key) }}</span><strong>{{ number_format($count) }}</strong><p>{{ __('platform-design.'.$key.'_help') }}</p>@if($canTenants)<a href="{{ route('platform.tenants.index', $status ? ['status' => $status] : []) }}">{{ __('platform-design.view') }} ↗</a>@endif</article>
            @endforeach
        </section>
        @if($finance !== null)
            <section class="platform-overview-panel" data-overview-finance>
                <div class="platform-overview-panel__heading"><div><h2>{{ __('platform-overview.finance') }}</h2><p>{{ __('platform-overview.finance_help') }}</p></div>
                    <form method="GET" action="{{ route('platform.dashboard') }}" class="flex items-center gap-2"><label class="sr-only" for="overview-range">{{ __('platform-overview.period') }}</label><select id="overview-range" name="range" class="rounded-xl border px-3 py-2">@foreach(['7d'=>7,'30d'=>30,'90d'=>90] as $value=>$days)<option value="{{ $value }}" @selected($range === $value)>{{ __('platform-overview.days', ['count'=>$days]) }}</option>@endforeach</select><button class="rounded-xl border px-3 py-2">{{ __('platform.ui.dashboard.filter') }}</button></form>
                </div>
                <div class="platform-overview-finance">@foreach(['payments', 'charges', 'outstanding'] as $key)<div><span>{{ __('platform-overview.'.$key) }}</span><strong dir="ltr">{{ number_format($finance[$key]) }} <small>SYP</small></strong><p>{{ __('platform-overview.'.$key.'_help') }}</p></div>@endforeach</div>
                <p>{{ __('platform-overview.ending', ['count' => $finance['ending']]) }}</p>
            </section>
        @endif
        <div class="platform-overview-health">
            @if($requests !== null)<section class="platform-overview-panel" data-overview-requests><h2>{{ __('platform-overview.requests') }}</h2><strong class="platform-overview-health__value">{{ number_format($requests) }}</strong><p>{{ __('platform-overview.requests_help') }}</p><a href="{{ route('platform.support.index') }}">{{ __('platform-overview.open_requests') }} →</a></section>@endif
            @if($backups !== null)<section class="platform-overview-panel" data-overview-backups><h2>{{ __('platform-overview.backups') }}</h2><strong class="platform-overview-health__value">{{ number_format($backups['failed']) }}</strong><p>{{ __('platform-overview.backups_help') }}</p><p>{{ __('platform-overview.latest_backup') }}: <span dir="ltr">{{ $backups['latest'] ? \Illuminate\Support\Carbon::parse($backups['latest'])->format('Y-m-d H:i') : __('platform-overview.unavailable') }}</span></p><a href="{{ route('platform.backups.index') }}">{{ __('platform-workspace.destinations.backups') }} →</a></section>@endif
            <section class="platform-overview-panel" data-overview-resources><h2>{{ __('platform-overview.resources') }}</h2>
                @if($snapshot)<strong class="platform-overview-health__value">{{ $snapshot->server_used_percent !== null ? number_format($snapshot->server_used_percent, 1).'%' : '—' }}</strong><p>{{ __('platform-overview.disk_help') }}</p><p>{{ __('platform-overview.captured') }}: <time dir="ltr" datetime="{{ $snapshot->captured_at->toIso8601String() }}">{{ $snapshot->captured_at->format('Y-m-d H:i') }}</time></p>@if($snapshotStale)<p class="platform-overview-panel__warning">{{ __('platform-overview.stale') }}</p>@endif
                @else<p>{{ __('platform-overview.no_snapshot') }}</p>@endif
                <a href="{{ route('platform.metrics') }}">{{ __('platform-workspace.destinations.metrics') }} →</a>
            </section>
        </div>
        @if($user->isPlatformOwner())<section class="platform-overview-panel" data-overview-activity><h2>{{ __('platform-overview.activity') }}</h2><p>{{ __('platform-overview.activity_help') }}</p><div class="platform-overview-activity">@forelse($activity as $event)<article><div><strong>{{ trans()->has('platform.ui.events.'.$event->event) ? __('platform.ui.events.'.$event->event) : str($event->event)->replace('_', ' ')->title() }}</strong><p>{{ $event->platformAdministrator?->name ?? __('platform.ui.common.system') }}</p></div><time dir="ltr" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('Y-m-d H:i') }}</time></article>@empty<p>{{ __('platform-overview.no_activity') }}</p>@endforelse</div></section>@endif
    </div>
</x-platform-layout>
