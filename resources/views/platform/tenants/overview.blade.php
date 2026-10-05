@php
    $platformUser = auth('platform')->user();
    $canSubscriptions = $platformUser->hasPlatformPermission('view.subscriptions') || $platformUser->hasPlatformPermission('manage.subscriptions');
    $canStorage = $platformUser->hasPlatformPermission('view.storage') || $platformUser->hasPlatformPermission('manage.tenants');
@endphp
<x-platform.tenant-workspace :tenant="$tenant" current="overview">
    <div class="space-y-6">
        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm font-bold uppercase tracking-wider text-emerald-700">Tenant overview</p><h2 class="mt-1 text-2xl font-bold">{{ $tenant->subscription ? 'Subscription at a glance' : 'Finish tenant setup' }}</h2><p class="mt-2 text-zinc-600">{{ $tenant->subscription ? 'Review the tenant package, balance, and operating status.' : 'The isolated workspace is ready. Add payment credit, then select and activate a package.' }}</p></div><div class="rounded-2xl bg-zinc-50 px-5 py-3 text-right"><div class="text-xs text-zinc-500">Balance</div><div class="text-xl font-bold {{ $billingBalance < 0 ? 'text-red-700' : 'text-zinc-950' }}">{{ number_format($billingBalance) }} SYP</div></div></div>
        </section>
        <div class="grid gap-4 md:grid-cols-3">
            <a href="{{ route('platform.tenants.organisation', $tenant) }}" class="rounded-2xl border bg-white p-5 shadow-sm transition hover:-translate-y-0.5"><span class="text-xs font-bold text-zinc-400">01</span><h3 class="mt-3 font-bold">Organisation</h3><p class="mt-1 text-sm text-zinc-500">Name, language, timezone, and administrator access.</p></a>
            @if($canSubscriptions)<a href="{{ route('platform.tenants.billing', $tenant) }}" class="rounded-2xl border bg-white p-5 shadow-sm transition hover:-translate-y-0.5"><span class="text-xs font-bold text-zinc-400">02</span><h3 class="mt-3 font-bold">Record payment</h3><p class="mt-1 text-sm text-zinc-500">Add offline credit before paid activation.</p></a>
            <a href="{{ route('platform.tenants.subscription', $tenant) }}" class="rounded-2xl border bg-white p-5 shadow-sm transition hover:-translate-y-0.5"><span class="text-xs font-bold text-zinc-400">03</span><h3 class="mt-3 font-bold">Package</h3><p class="mt-1 text-sm text-zinc-500">Choose a package and voucher, review the price, then activate.</p></a>@endif
        </div>
        <section class="grid gap-4 md:grid-cols-2">
            <div class="rounded-2xl border bg-white p-5"><div class="text-sm text-zinc-500">Current package</div><div class="mt-1 text-lg font-bold">{{ $tenant->subscription?->plan?->name ?? 'Not assigned' }}</div>@if($tenant->subscription)<div class="mt-2 text-sm text-zinc-500">{{ $tenant->subscription->starts_at?->format('Y-m-d') }} → {{ $tenant->subscription->ends_at?->format('Y-m-d') }}</div>@endif</div>
            @if($canStorage)<div class="rounded-2xl border bg-white p-5"><div class="text-sm text-zinc-500">Storage limit</div><div class="mt-1 text-lg font-bold">{{ $tenant->storage_limit_bytes ? number_format($tenant->storage_limit_bytes / 1073741824, 1).' GB' : 'Unlimited' }}</div><a href="{{ route('platform.tenants.storage', $tenant) }}" class="mt-2 inline-block text-sm font-semibold text-emerald-700">View storage breakdown →</a></div>@endif
        </section>
    </div>
</x-platform.tenant-workspace>
