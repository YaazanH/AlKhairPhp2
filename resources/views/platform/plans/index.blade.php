<x-platform-layout title="Packages">
    <div class="platform-management platform-packages" data-platform-management>
    <header class="platform-management__header flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div><p class="text-sm font-medium text-emerald-600">Platform workspace</p><h1 class="text-3xl font-bold">Packages</h1><p class="mt-1 text-zinc-500">Build reusable module bundles. Tenant-specific additions are managed from each tenant.</p></div>
        <a href="{{ route('platform.plans.create') }}" class="rounded-xl bg-emerald-700 px-4 py-3 text-center font-medium text-white">+ Create package</a>
    </header>

    <section class="platform-packages__grid grid gap-5 md:grid-cols-2 xl:grid-cols-3" aria-label="Packages">
        @forelse ($plans as $plan)
            @php($codes = app(\App\Services\Landlord\PlanModuleManager::class)->selectedCodes($plan))
            <article class="platform-package flex min-h-72 flex-col rounded-3xl border bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">{{ $plan->code }}</p><h2 class="mt-1 text-xl font-bold">{{ $plan->name }}</h2></div>
                    <div class="flex flex-wrap justify-end gap-2"><span class="rounded-full px-3 py-1 text-xs font-medium {{ $plan->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-600' }}">{{ $plan->is_active ? 'Active' : 'Inactive' }}</span><span class="rounded-full px-3 py-1 text-xs font-medium {{ $plan->is_public ? 'bg-sky-100 text-sky-800' : 'bg-amber-100 text-amber-800' }}">{{ $plan->is_public ? 'Public' : 'Private' }}</span></div>
                </div>
                <p class="mt-3 min-h-10 text-sm text-zinc-500">{{ $plan->description ?: 'No package description yet.' }}</p>
                <div class="platform-package__price"><strong dir="ltr">{{ number_format($plan->price_syp) }} <small>SYP</small></strong><span>{{ __('platform-design.billing_period', ['days' => $plan->billing_period_days]) }}</span></div>
                <div class="platform-package__modules mt-5 flex flex-wrap gap-2">
                    @foreach (array_slice($codes, 0, 6) as $code)<span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-700">{{ config('modules.definitions.'.$code.'.name', $code) }}</span>@endforeach
                    @if (count($codes) > 6)<span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700">+{{ count($codes) - 6 }} more</span>@endif
                </div>
                <div class="platform-package__footer mt-auto flex items-center justify-between border-t pt-5 text-sm">
                    <span class="text-zinc-500">{{ $plan->subscriptions_count }} {{ \Illuminate\Support\Str::plural('tenant', $plan->subscriptions_count) }}</span>
                    <a href="{{ route('platform.plans.edit', $plan) }}" class="font-semibold text-emerald-700">Edit package →</a>
                </div>
            </article>
        @empty
            <div class="rounded-3xl border border-dashed bg-white p-10 text-center text-zinc-500 md:col-span-2 xl:col-span-3">No packages exist yet.</div>
        @endforelse
    </section>
    </div>
</x-platform-layout>
