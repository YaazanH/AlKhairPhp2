@props(['tenant', 'current'])
@php
    $user = auth('platform')->user();
    $items = [
        ['overview', __('platform.ui.tenant_workspace.overview'), 'platform.tenants.edit', true],
        ['organisation', __('platform.ui.tenant_workspace.organisation'), 'platform.tenants.organisation', true],
        ['subscription', __('platform.ui.tenant_workspace.package'), 'platform.tenants.subscription', $user->hasPlatformPermission('view.subscriptions') || $user->hasPlatformPermission('manage.subscriptions')],
        ['billing', __('platform.ui.tenant_workspace.payments'), 'platform.tenants.billing', $user->hasPlatformPermission('view.subscriptions') || $user->hasPlatformPermission('manage.subscriptions')],
        ['storage', __('platform.ui.tenant_workspace.storage'), 'platform.tenants.storage', $user->hasPlatformPermission('view.storage') || $user->hasPlatformPermission('manage.tenants')],
        ['modules', __('platform.ui.tenant_workspace.modules'), 'platform.tenants.modules', true],
        ['activity', __('platform.ui.tenant_workspace.activity'), 'platform.tenants.activity', true],
    ];
    $primaryHost = $tenant->domains->firstWhere('is_primary', true)?->host;
    $tenantPort = request()->getPort();
    $portSuffix = in_array($tenantPort, [80, 443], true) ? '' : ':'.$tenantPort;
    $websiteUrl = $tenant->isOperational() && filled($tenant->database_name) && filled($primaryHost)
        ? request()->getScheme().'://'.$primaryHost.$portSuffix
        : null;
    $logoUrl = filled($tenant->logo_path) && filled($primaryHost)
        ? request()->getScheme().'://'.$primaryHost.$portSuffix.'/storage/'.ltrim($tenant->logo_path, '/')
        : null;
    $supportLevels = collect(['read', 'edit', 'delete'])
        ->mapWithKeys(fn ($level) => [$level => __('platform.ui.dashboard.'.$level)])
        ->filter(fn ($label, $level) => $user->hasPlatformPermission('support-access.'.$level));
@endphp
<x-platform-layout :title="$tenant->name">
    <div class="platform-tenant-workspace space-y-6">
        <header class="platform-tenant-header rounded-3xl bg-zinc-950 p-6 text-white shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <a href="{{ route('platform.dashboard') }}" class="text-sm font-semibold text-emerald-300">{{ __('platform.ui.tenant_workspace.all_tenants') }}</a>
                    <div class="mt-3 flex items-center gap-3">
                        <div class="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-2xl bg-white/10 text-lg font-bold" data-tenant-logo>
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ __('platform.ui.tenant_workspace.logo_alt', ['tenant' => $tenant->name]) }}" class="h-full w-full object-cover">
                            @else
                                {{ str($tenant->name)->substr(0, 2)->upper() }}
                            @endif
                        </div>
                        <div><h1 class="text-2xl font-bold">{{ $tenant->name }}</h1><p class="text-sm text-zinc-400" dir="ltr">{{ $tenant->slug }}.{{ config('tenancy.base_domain') }}</p></div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="w-fit rounded-full px-3 py-1.5 text-xs font-bold {{ $tenant->isOperational() ? 'bg-emerald-400/20 text-emerald-200' : 'bg-amber-400/20 text-amber-200' }}">{{ __('platform.ui.common.'.$tenant->status) }}</span>
                    @if($websiteUrl)
                        <a href="{{ $websiteUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/15" aria-label="{{ __('platform.ui.tenant_workspace.open_website', ['tenant' => $tenant->name]) }}" data-tenant-website-link>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z"/></svg>
                            {{ __('platform.ui.tenant_workspace.website') }}
                        </a>
                        @if($supportLevels->isNotEmpty())
                            <form method="POST" action="{{ route('platform.tenants.support-access.store', $tenant) }}" target="_blank" rel="noopener" class="flex items-center gap-2" data-tenant-quick-access>
                                @csrf
                                <select name="access_level" class="rounded-xl border border-white/15 bg-white/10 px-2 py-2 text-xs text-white" aria-label="{{ __('platform.ui.tenant_workspace.access_level') }}">
                                    @foreach($supportLevels as $level => $label)<option value="{{ $level }}" class="text-zinc-950">{{ $label }}</option>@endforeach
                                </select>
                                <button class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-500">{{ __('platform.ui.tenant_workspace.quick_access') }}</button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>
        </header>
        @if(session('status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        <div class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
            <nav class="platform-tenant-nav h-fit rounded-2xl border border-zinc-200 bg-white p-2 shadow-sm" aria-label="{{ __('platform.ui.tenant_workspace.settings_label') }}">
                @foreach($items as [$key, $label, $route, $visible]) @if($visible)<a href="{{ route($route, $tenant) }}" class="mb-1 block rounded-xl px-4 py-3 text-sm font-semibold {{ $current === $key ? 'bg-emerald-50 text-emerald-800' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950' }}">{{ $label }}</a>@endif @endforeach
            </nav>
            <main class="min-w-0">{{ $slot }}</main>
        </div>
    </div>
</x-platform-layout>
