@props(['title' => 'Platform Administration'])
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
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
        @if ($errors->any())<div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if (session('status'))<div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
    </div></main>
</div>
@fluxScripts
</body>
</html>
