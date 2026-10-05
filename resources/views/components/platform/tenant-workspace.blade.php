@props(['tenant', 'current'])
@php
    $user = auth('platform')->user();
    $items = [
        ['overview', 'Overview', 'platform.tenants.edit', true],
        ['organisation', 'Organisation', 'platform.tenants.organisation', true],
        ['subscription', 'Package', 'platform.tenants.subscription', $user->hasPlatformPermission('view.subscriptions') || $user->hasPlatformPermission('manage.subscriptions')],
        ['billing', 'Payments', 'platform.tenants.billing', $user->hasPlatformPermission('view.subscriptions') || $user->hasPlatformPermission('manage.subscriptions')],
        ['storage', 'Storage', 'platform.tenants.storage', $user->hasPlatformPermission('view.storage') || $user->hasPlatformPermission('manage.tenants')],
        ['modules', 'Modules', 'platform.tenants.modules', true],
        ['activity', 'Activity', 'platform.tenants.activity', true],
    ];
@endphp
<x-platform-layout :title="$tenant->name">
    <div class="space-y-6">
        <header class="rounded-3xl bg-zinc-950 p-6 text-white shadow-sm">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div><a href="{{ route('platform.dashboard') }}" class="text-sm font-semibold text-emerald-300">← All tenants</a><div class="mt-3 flex items-center gap-3"><div class="grid h-12 w-12 place-items-center rounded-2xl bg-white/10 text-lg font-bold">{{ str($tenant->name)->substr(0, 2)->upper() }}</div><div><h1 class="text-2xl font-bold">{{ $tenant->name }}</h1><p class="text-sm text-zinc-400">{{ $tenant->slug }}.{{ config('tenancy.base_domain') }}</p></div></div></div>
                <span class="w-fit rounded-full px-3 py-1.5 text-xs font-bold {{ $tenant->isOperational() ? 'bg-emerald-400/20 text-emerald-200' : 'bg-amber-400/20 text-amber-200' }}">{{ str($tenant->status)->replace('_', ' ')->title() }}</span>
            </div>
        </header>
        @if(session('status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        <div class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
            <nav class="h-fit rounded-2xl border border-zinc-200 bg-white p-2 shadow-sm" aria-label="Tenant settings">
                @foreach($items as [$key, $label, $route, $visible]) @if($visible)<a href="{{ route($route, $tenant) }}" class="mb-1 block rounded-xl px-4 py-3 text-sm font-semibold {{ $current === $key ? 'bg-emerald-50 text-emerald-800' : 'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-950' }}">{{ $label }}</a>@endif @endforeach
            </nav>
            <main class="min-w-0">{{ $slot }}</main>
        </div>
    </div>
</x-platform-layout>
