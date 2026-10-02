<x-platform-layout title="Platform Administration">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between"><div><p class="text-sm font-medium text-emerald-600">Platform workspace</p><h1 class="text-3xl font-bold">Tenant overview</h1><p class="mt-1 text-zinc-500">Manage organisations, packages, and lifecycle status.</p></div>@if(auth('platform')->user()->hasPlatformPermission('manage.tenants'))<a href="{{ route('platform.tenants.create') }}" class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">+ Create tenant</a>@endif</header>
        <section class="grid gap-4 md:grid-cols-3"><div class="rounded-2xl border bg-white p-5 shadow-sm"><p class="text-sm text-zinc-500">All tenants</p><p class="mt-2 text-3xl font-bold">{{ $tenantCounts['total'] }}</p></div><div class="rounded-2xl border bg-white p-5 shadow-sm"><p class="text-sm text-zinc-500">Active</p><p class="mt-2 text-3xl font-bold text-emerald-700">{{ $tenantCounts['active'] }}</p></div><div class="rounded-2xl border bg-white p-5 shadow-sm"><p class="text-sm text-zinc-500">Suspended</p><p class="mt-2 text-3xl font-bold text-amber-600">{{ $tenantCounts['suspended'] }}</p></div></section>
        <section class="overflow-hidden rounded-2xl border bg-white shadow-sm">
            <div class="border-b p-5"><form class="flex flex-col gap-3 md:flex-row"><input name="search" value="{{ request('search') }}" placeholder="Search tenant or subdomain" class="rounded-xl border px-3 py-2 md:w-80"><select name="status" class="rounded-xl border px-3 py-2"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="provisioning_failed">Provisioning failed</option></select><button class="rounded-xl border px-4 py-2">Filter</button></form></div>
            <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-zinc-50 text-left text-zinc-500"><tr><th class="px-5 py-3">Tenant</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Package</th><th class="px-5 py-3">Actions</th></tr></thead><tbody class="divide-y">
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
                        <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-emerald-100 text-xs font-bold text-emerald-800">@if($tenant->logo_path && $primaryHost)<img src="{{ request()->getScheme().'://'.$primaryHost.$portSuffix.'/storage/'.ltrim($tenant->logo_path, '/') }}" alt="" class="h-full w-full object-cover">@else{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($tenant->name, 0, 2)) }}@endif</span><div><div class="font-semibold">{{ $tenant->name }}</div><div class="text-xs text-zinc-500">{{ $tenant->slug }}.{{ config('tenancy.base_domain') }}</div></div></div></td>
                        <td class="px-5 py-4"><span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs">{{ str($tenant->status)->replace('_', ' ')->title() }}</span></td>
                        <td class="px-5 py-4">{{ $tenant->subscription?->plan?->name ?? 'Not assigned' }}</td>
                        <td class="px-5 py-4"><div class="flex items-center gap-3">@if(auth('platform')->user()->hasPlatformPermission('view.tenants'))<a class="text-emerald-700 hover:underline" href="{{ route('platform.tenants.edit', $tenant) }}">Manage</a>@endif
                            @if ($websiteUrl)
                                @php($supportLevels = collect(['read' => 'Read only', 'edit' => 'Read and edit', 'delete' => 'Full support'])->filter(fn ($label, $level) => auth('platform')->user()->hasPlatformPermission('support-access.'.$level)))
                                @if($supportLevels->isNotEmpty())
                                    <form method="POST" action="{{ route('platform.tenants.support-access.store', $tenant) }}" target="_blank" rel="noopener" class="flex items-center gap-2">
                                        @csrf
                                        <select name="access_level" class="rounded-lg border px-2 py-1.5 text-xs" aria-label="Support access level for {{ $tenant->name }}">
                                            @foreach($supportLevels as $level => $label)<option value="{{ $level }}">{{ $label }}</option>@endforeach
                                        </select>
                                        <button class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white" title="Open a secure 60-minute support session">Open tenant</button>
                                    </form>
                                @endif
                                <a href="{{ $websiteUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:border-emerald-400 hover:bg-emerald-100" aria-label="Open {{ $tenant->name }} website" title="Open tenant website">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z"/></svg>
                                </a>
                            @else
                                <span class="inline-flex h-9 w-9 cursor-not-allowed items-center justify-center rounded-full border bg-zinc-50 text-zinc-300" title="Website unavailable until tenant provisioning is complete" aria-label="{{ $tenant->name }} website unavailable">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z"/></svg>
                                </span>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-12 text-center text-zinc-500">No tenants match this filter.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
</x-platform-layout>
