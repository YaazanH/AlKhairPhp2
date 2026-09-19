<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Platform Administration</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="app-body">
<div class="app-backdrop"><div class="app-backdrop__orb app-backdrop__orb--gold"></div><div class="app-backdrop__orb app-backdrop__orb--emerald"></div></div>
<div class="app-shell flex min-h-screen">
    <flux:sidebar sticky stashable class="app-sidebar-shell border-r">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark"/>
        <div class="app-sidebar-scroll-region">
            <div class="px-3 pt-4"><a href="{{ route('platform.dashboard') }}" class="text-lg font-bold text-white">AlKhair <span class="text-emerald-400">Platform</span></a><p class="mt-1 text-xs text-zinc-400">SaaS administration</p></div>
            <flux:navlist variant="outline" class="mt-6">
                <flux:navlist.item icon="squares-2x2" href="{{ route('platform.dashboard') }}" :current="request()->routeIs('platform.dashboard')">Overview</flux:navlist.item>
                <flux:navlist.item icon="building-office-2" href="{{ route('platform.plans.index') }}" :current="request()->routeIs('platform.plans.*')">Packages</flux:navlist.item>
                <flux:navlist.item icon="plus-circle" href="{{ route('platform.tenants.create') }}" :current="request()->routeIs('platform.tenants.create')">New tenant</flux:navlist.item>
            </flux:navlist>
        </div>
        <div class="p-3"><div class="rounded-xl bg-zinc-800 p-3 text-sm text-zinc-300">{{ auth('platform')->user()->name }}<form method="POST" action="{{ route('platform.logout') }}" class="mt-2">@csrf<button class="text-xs text-emerald-400">Sign out</button></form></div></div>
    </flux:sidebar>
    <main class="app-main flex-1"><div class="app-main-inner space-y-6">
        @if (session('status'))<div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</div>@endif
        <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between"><div><p class="text-sm font-medium text-emerald-600">Platform workspace</p><h1 class="text-3xl font-bold">Tenant overview</h1><p class="mt-1 text-zinc-500">Manage organisations, packages, and lifecycle status.</p></div><a href="{{ route('platform.tenants.create') }}" class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">+ Create tenant</a></header>
        <section class="grid gap-4 md:grid-cols-3"><div class="rounded-2xl border bg-white p-5 shadow-sm"><p class="text-sm text-zinc-500">All tenants</p><p class="mt-2 text-3xl font-bold">{{ $tenantCounts['total'] }}</p></div><div class="rounded-2xl border bg-white p-5 shadow-sm"><p class="text-sm text-zinc-500">Active</p><p class="mt-2 text-3xl font-bold text-emerald-700">{{ $tenantCounts['active'] }}</p></div><div class="rounded-2xl border bg-white p-5 shadow-sm"><p class="text-sm text-zinc-500">Suspended</p><p class="mt-2 text-3xl font-bold text-amber-600">{{ $tenantCounts['suspended'] }}</p></div></section>
        <section class="overflow-hidden rounded-2xl border bg-white shadow-sm"><div class="border-b p-5"><form class="flex flex-col gap-3 md:flex-row"><input name="search" value="{{ request('search') }}" placeholder="Search tenant or subdomain" class="rounded-xl border px-3 py-2 md:w-80"><select name="status" class="rounded-xl border px-3 py-2"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="provisioning_failed">Provisioning failed</option></select><button class="rounded-xl border px-4 py-2">Filter</button></form></div><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-zinc-50 text-left text-zinc-500"><tr><th class="px-5 py-3">Tenant</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Package</th><th class="px-5 py-3">Actions</th></tr></thead><tbody class="divide-y">@forelse ($tenants as $tenant)<tr><td class="px-5 py-4"><div class="font-semibold">{{ $tenant->name }}</div><div class="text-xs text-zinc-500">{{ $tenant->slug }}.{{ config('tenancy.base_domain') }}</div></td><td class="px-5 py-4"><span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs">{{ str($tenant->status)->replace('_', ' ')->title() }}</span></td><td class="px-5 py-4">{{ $tenant->subscription?->plan?->name ?? 'Not assigned' }}</td><td class="px-5 py-4"><a class="text-emerald-700 hover:underline" href="{{ route('platform.tenants.edit', $tenant) }}">Manage</a></td></tr>@empty<tr><td colspan="4" class="px-5 py-12 text-center text-zinc-500">No tenants match this filter.</td></tr>@endforelse</tbody></table></div></section>
    </div></main>
</div>
@fluxScripts
</body>
</html>
