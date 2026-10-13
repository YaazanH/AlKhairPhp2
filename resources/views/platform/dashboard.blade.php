<x-platform-layout :title="__('platform.ui.dashboard.title')">
    <div class="platform-overview" data-platform-overview>
    <header class="platform-overview__header flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-sm font-medium text-emerald-600">{{ __('platform.ui.common.platform_workspace') }}</p>
            <h1 class="text-3xl font-bold">{{ __('platform.ui.dashboard.title') }}</h1>
            <p class="mt-1 text-zinc-500">{{ __('platform.ui.dashboard.description') }}</p>
        </div>
        @if(auth('platform')->user()->hasPlatformPermission('manage.tenants'))
            <a href="{{ route('platform.tenants.create') }}" class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">+ {{ __('platform.ui.dashboard.create') }}</a>
        @endif
    </header>

    <section class="platform-overview__stats" aria-label="{{ __('platform-design.summary') }}">
        @php
            $summaryCards = [
                ['total', $tenantStatusCounts->sum(), null],
                ['active', $tenantStatusCounts->get('active', 0), 'active'],
                ['trial', $tenantStatusCounts->get('trial', 0), 'trial'],
                ['attention', collect(['suspended', 'expired', 'provisioning_failed'])->sum(fn ($status) => $tenantStatusCounts->get($status, 0)), null],
            ];
        @endphp
        @foreach($summaryCards as [$key, $count, $statusFilter])
            <article class="platform-overview__stat platform-overview__stat--{{ $key }}">
                <span>{{ __('platform-design.'.$key) }}</span>
                <strong>{{ number_format($count) }}</strong>
                <p>{{ __('platform-design.'.$key.'_help') }}</p>
                @if($statusFilter)<a href="{{ route('platform.dashboard', ['status' => $statusFilter]) }}" aria-label="{{ __('platform-design.view_status', ['status' => __('platform.ui.common.'.$statusFilter)]) }}">{{ __('platform-design.view') }} <span aria-hidden="true">↗</span></a>@endif
            </article>
        @endforeach
    </section>

    <div class="platform-overview__shortcuts">
        <a href="{{ route('platform.metrics') }}"><span class="platform-overview__shortcut-icon" aria-hidden="true">↗</span><div><strong>{{ __('platform.ui.navigation.metrics') }}</strong><p>{{ __('platform-design.metrics_help') }}</p></div><i aria-hidden="true">→</i></a>
        @if(auth('platform')->user()->hasPlatformPermission('manage.plans'))
            <a href="{{ route('platform.plans.index') }}"><span class="platform-overview__shortcut-icon" aria-hidden="true">▦</span><div><strong>{{ __('platform.ui.navigation.packages') }}</strong><p>{{ __('platform-design.packages_help') }}</p></div><i aria-hidden="true">→</i></a>
        @endif
    </div>

    <section class="platform-overview__tenants overflow-hidden rounded-2xl border bg-white shadow-sm">
        <div class="platform-overview__table-heading"><div><h2>{{ __('platform.ui.dashboard.title') }}</h2><p>{{ __('platform-design.filtered_count', ['count' => $tenants->count()]) }}</p></div><span>{{ __('platform-design.filter_hint') }}</span></div>
        <div class="border-b p-5">
            <form class="flex flex-col gap-3 md:flex-row">
                <input name="search" aria-label="{{ __('platform.ui.dashboard.search') }}" value="{{ request('search') }}" placeholder="{{ __('platform.ui.dashboard.search') }}" class="rounded-xl border px-3 py-2 md:w-80">
                <select name="status" aria-label="{{ __('platform.ui.common.status') }}" class="rounded-xl border px-3 py-2">
                    <option value="">{{ __('platform.ui.dashboard.all_statuses') }}</option>
                    @foreach(['draft', 'provisioning', 'trial', 'active', 'suspended', 'expired', 'provisioning_failed'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('platform.ui.common.'.$status) }}</option>
                    @endforeach
                </select>
                <button class="rounded-xl border px-4 py-2">{{ __('platform.ui.dashboard.filter') }}</button>
                @if(request()->filled('search') || request()->filled('status'))<a class="platform-overview__reset" href="{{ route('platform.dashboard') }}">{{ __('platform-design.reset') }}</a>@endif
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-start text-zinc-500">
                    <tr>
                        <th class="px-5 py-3">{{ __('platform.ui.common.tenant') }}</th>
                        <th class="px-5 py-3">{{ __('platform.ui.common.status') }}</th>
                        <th class="px-5 py-3">{{ __('platform.ui.dashboard.package') }}</th>
                        <th class="px-5 py-3">{{ __('platform.ui.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($tenants as $tenant)
                        @php
                            $primaryHost = $tenant->domains->firstWhere('is_primary', true)?->host;
                            $tenantPort = request()->getPort();
                            $portSuffix = in_array($tenantPort, [80, 443], true) ? '' : ':'.$tenantPort;
                            $websiteUrl = $tenant->isOperational() && filled($tenant->database_name) && filled($primaryHost)
                                ? request()->getScheme().'://'.$primaryHost.$portSuffix
                                : null;
                        @endphp
                        <tr>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-emerald-100 text-xs font-bold text-emerald-800">
                                        @if($tenant->logo_path && $primaryHost)
                                            <img src="{{ request()->getScheme().'://'.$primaryHost.$portSuffix.'/storage/'.ltrim($tenant->logo_path, '/') }}" alt="" class="h-full w-full object-cover">
                                        @else
                                            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($tenant->name, 0, 2)) }}
                                        @endif
                                    </span>
                                    <div>
                                        <div class="font-semibold">{{ $tenant->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $tenant->slug }}.{{ config('tenancy.base_domain') }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4"><span class="platform-overview__status" data-status="{{ $tenant->status }}">{{ __('platform.ui.common.'.$tenant->status) }}</span></td>
                            <td class="px-5 py-4">{{ $tenant->subscription?->plan?->name ?? __('platform.ui.common.not_assigned') }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    @if(auth('platform')->user()->hasPlatformPermission('view.tenants'))
                                        <a class="text-emerald-700 hover:underline" href="{{ route('platform.tenants.edit', $tenant) }}">{{ __('platform.ui.dashboard.manage') }}</a>
                                    @endif
                                    @if ($websiteUrl)
                                        @php($supportLevels = collect(['read', 'edit', 'delete'])->mapWithKeys(fn ($level) => [$level => __('platform.ui.dashboard.'.$level)])->filter(fn ($label, $level) => auth('platform')->user()->hasPlatformPermission('support-access.'.$level)))
                                        @if($supportLevels->isNotEmpty())
                                            <form method="POST" action="{{ route('platform.tenants.support-access.store', $tenant) }}" target="_blank" rel="noopener" class="flex items-center gap-2">
                                                @csrf
                                                <select name="access_level" class="rounded-lg border px-2 py-1.5 text-xs" aria-label="{{ __('platform.ui.dashboard.support_label', ['tenant' => $tenant->name]) }}">
                                                    @foreach($supportLevels as $level => $label)
                                                        <option value="{{ $level }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white" title="{{ __('platform.ui.dashboard.support_title') }}">{{ __('platform.ui.dashboard.open_support') }}</button>
                                            </form>
                                        @endif
                                        <a href="{{ $websiteUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:border-emerald-400 hover:bg-emerald-100" aria-label="{{ __('platform.ui.dashboard.open_site', ['tenant' => $tenant->name]) }}" title="{{ __('platform.ui.dashboard.site_title') }}">
                                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z"/></svg>
                                        </a>
                                    @else
                                        <span class="inline-flex h-9 w-9 cursor-not-allowed items-center justify-center rounded-full border bg-zinc-50 text-zinc-300" title="{{ __('platform.ui.dashboard.site_unavailable') }}" aria-label="{{ __('platform.ui.dashboard.site_unavailable') }}">
                                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z"/></svg>
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-12 text-center text-zinc-500">{{ __('platform.ui.dashboard.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    </div>
</x-platform-layout>
