<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SaaS backups · Platform Administration</title>
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
                <flux:navlist.item icon="squares-2x2" href="{{ route('platform.dashboard') }}">Overview</flux:navlist.item>
                <flux:navlist.item icon="archive-box" href="{{ route('platform.backups.index') }}" :current="true">Backups</flux:navlist.item>
                <flux:navlist.item icon="building-office-2" href="{{ route('platform.plans.index') }}">Packages</flux:navlist.item>
            </flux:navlist>
        </div>
        <div class="p-3"><div class="rounded-xl bg-zinc-800 p-3 text-sm text-zinc-300">{{ auth('platform')->user()->name }}<form method="POST" action="{{ route('platform.logout') }}" class="mt-2">@csrf<button class="text-xs text-emerald-400">Sign out</button></form></div></div>
    </flux:sidebar>
    <main class="app-main flex-1"><div class="app-main-inner space-y-6">
        @if (session('status'))<div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</div>@endif
        <header><p class="text-sm font-medium text-emerald-600">Platform workspace</p><h1 class="text-3xl font-bold">Tenant backups</h1><p class="mt-1 text-zinc-500">Each archive contains one tenant’s database and its public and private files.</p></header>

        <section class="grid gap-6 xl:grid-cols-2">
            <form method="POST" action="{{ route('platform.backups.settings.update') }}" class="rounded-2xl border bg-white p-5 shadow-sm space-y-4">@csrf @method('PUT')
                <h2 class="text-lg font-semibold">Automatic backup settings</h2>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_enabled" value="1" @checked($settings->is_enabled)> Enable daily backups</label>
                <label class="block text-sm font-medium">Run at <input type="time" name="run_at" value="{{ substr($settings->run_at, 0, 5) }}" class="mt-1 block rounded-lg border px-3 py-2"></label>
                <label class="block text-sm font-medium">Scheduled archives kept per tenant <input type="number" name="retention_count" min="1" max="365" value="{{ $settings->retention_count }}" class="mt-1 block w-28 rounded-lg border px-3 py-2"></label>
                <p class="text-xs text-zinc-500">At least one verified scheduled archive is always retained. Safety archives created before a restore are never removed by this rule.</p>
                <button class="rounded-xl bg-emerald-700 px-4 py-2 font-medium text-white">Save settings</button>
            </form>
            <form method="POST" action="{{ route('platform.backups.create') }}" class="rounded-2xl border bg-white p-5 shadow-sm space-y-4">@csrf
                <h2 class="text-lg font-semibold">Create a backup now</h2>
                <label class="block text-sm font-medium">Tenant<select name="tenant_id" required class="mt-1 block w-full rounded-lg border px-3 py-2"><option value="">Select tenant</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }} ({{ $tenant->slug }})</option>@endforeach</select></label>
                <button class="rounded-xl border border-emerald-700 px-4 py-2 font-medium text-emerald-800">Create complete backup</button>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border bg-white shadow-sm"><div class="border-b p-5"><h2 class="font-semibold">Backup history</h2></div><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-zinc-50 text-left text-zinc-500"><tr><th class="px-5 py-3">Tenant</th><th class="px-5 py-3">Created</th><th class="px-5 py-3">Type</th><th class="px-5 py-3">Size</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Actions</th></tr></thead><tbody class="divide-y">
            @forelse($backups as $backup)<tr><td class="px-5 py-3 font-medium">{{ $backup->tenant->name }}</td><td class="px-5 py-3">{{ $backup->created_at->format('Y-m-d H:i') }}</td><td class="px-5 py-3">{{ str($backup->trigger)->replace('_', ' ')->title() }}</td><td class="px-5 py-3">{{ $backup->size_bytes ? number_format($backup->size_bytes / 1048576, 1).' MB' : '—' }}</td><td class="px-5 py-3">{{ str($backup->status)->title() }}</td><td class="px-5 py-3"><div class="flex gap-3">@if($backup->isUsable())<a class="text-emerald-700 hover:underline" href="{{ route('platform.backups.download', $backup) }}">Download</a><details><summary class="cursor-pointer text-red-700">Restore</summary><form method="POST" action="{{ route('platform.backups.restore', $backup) }}" class="mt-2 space-y-2 rounded border p-3">@csrf<p class="max-w-xs text-xs text-red-700">This replaces {{ $backup->tenant->name }}’s current database and files. A safety backup is created first. Type <strong>{{ $backup->tenant->slug }}</strong> to continue.</p><input name="confirmation" required class="w-full rounded border px-2 py-1" placeholder="{{ $backup->tenant->slug }}"><button class="rounded bg-red-700 px-3 py-1 text-white">Restore complete archive</button></form></details>@endif</div></td></tr>@empty<tr><td colspan="6" class="px-5 py-12 text-center text-zinc-500">No SaaS backups have been created yet.</td></tr>@endforelse
        </tbody></table></div><div class="p-5">{{ $backups->links() }}</div></section>
    </div></main>
</div>
@fluxScripts
</body>
</html>
