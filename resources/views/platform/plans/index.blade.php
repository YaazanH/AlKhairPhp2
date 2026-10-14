<x-platform-layout :title="__('platform-features.title')">
    <div class="platform-management platform-packages" data-platform-management>
    <header class="platform-management__header flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div><p class="text-sm font-medium text-emerald-600">{{ __('platform.ui.common.platform_workspace') }}</p><h1 class="text-3xl font-bold">{{ __('platform-features.title') }}</h1><p class="mt-1 text-zinc-500">{{ __('platform-design.packages_help') }}</p><a href="{{ route('platform.plans.features') }}" class="mt-3 inline-block text-sm font-semibold text-emerald-700">{{ __('platform-features.catalog') }} →</a></div>
        <a href="{{ route('platform.plans.create') }}" class="rounded-xl bg-emerald-700 px-4 py-3 text-center font-medium text-white">+ {{ __('platform-features.create') }}</a>
    </header>
    <form method="GET" action="{{ route('platform.plans.index') }}" class="platform-package-filters rounded-2xl border bg-white p-5">
        <label>{{ __('platform-features.search_packages') }}<input name="search" value="{{ request('search') }}" class="rounded-xl border p-3"></label>
        <label>{{ __('platform-features.status') }}<select name="status" class="rounded-xl border p-3">@foreach(['all','active','inactive'] as $value)<option value="{{ $value }}" @selected(request('status', 'all') === $value)>{{ __('platform-features.'.$value) }}</option>@endforeach</select></label>
        <label>{{ __('platform-features.visibility') }}<select name="visibility" class="rounded-xl border p-3">@foreach(['all','public','private'] as $value)<option value="{{ $value }}" @selected(request('visibility', 'all') === $value)>{{ __('platform-features.'.$value) }}</option>@endforeach</select></label>
        <button class="rounded-xl border px-4 py-3">{{ __('platform-features.filter') }}</button><a href="{{ route('platform.plans.index') }}" class="text-sm text-emerald-700">{{ __('platform-features.clear') }}</a>
    </form>

    <section class="platform-packages__grid grid gap-5 md:grid-cols-2 xl:grid-cols-3" aria-label="Packages">
        @forelse ($plans as $plan)
            @php
                $manager = app(\App\Services\Landlord\PlanModuleManager::class);
                $codes = $manager->selectedCodes($plan);
                $inclusionError = null;
                try { $codes = array_values(array_diff($manager->normalize($codes)['effective'], ['foundation'])); }
                catch (\DomainException $exception) { $inclusionError = $exception->getMessage(); }
            @endphp
            <article class="platform-package flex min-h-72 flex-col rounded-3xl border bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div><p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">{{ $plan->code }}</p><h2 class="mt-1 text-xl font-bold">{{ $plan->name }}</h2></div>
                    <div class="flex flex-wrap justify-end gap-2"><span class="rounded-full px-3 py-1 text-xs font-medium {{ $plan->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-600' }}">{{ __('platform-features.'.($plan->is_active ? 'active' : 'inactive')) }}</span><span class="rounded-full px-3 py-1 text-xs font-medium {{ $plan->is_public ? 'bg-sky-100 text-sky-800' : 'bg-amber-100 text-amber-800' }}">{{ __('platform-features.'.($plan->is_public ? 'public' : 'private')) }}</span></div>
                </div>
                <p class="mt-3 min-h-10 text-sm text-zinc-500">{{ $plan->description ?: __('platform-features.description_empty') }}</p>
                @if($inclusionError)<p class="mt-3 text-sm" role="status">{{ __('platform-features.access_errors') }}: {{ $inclusionError }}</p>@endif
                <div class="platform-package__price"><strong dir="ltr">{{ number_format($plan->price_syp) }} <small>SYP</small></strong><span>{{ __('platform-design.billing_period', ['days' => $plan->billing_period_days]) }}</span></div>
                <div class="platform-package__modules mt-5 flex flex-wrap gap-2">
                    @foreach (array_slice($codes, 0, 6) as $code)<span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-700">{{ app(\App\Support\PlatformFeaturePresentation::class)->name($code) }}</span>@endforeach
                    @if (count($codes) > 6)<details class="w-full"><summary class="cursor-pointer text-xs font-semibold">{{ __('platform-features.all_features') }} ({{ count($codes) }})</summary><div class="mt-3 flex flex-wrap gap-2">@foreach(array_slice($codes, 6) as $code)<span class="rounded-full border px-2 py-1 text-xs">{{ app(\App\Support\PlatformFeaturePresentation::class)->name($code) }}</span>@endforeach</div></details>@endif
                </div>
                <div class="platform-package__footer mt-auto flex items-center justify-between border-t pt-5 text-sm">
                    <span class="text-zinc-500">{{ __('platform-features.tenants', ['count' => $plan->subscriptions_count]) }}</span>
                    <a href="{{ route('platform.plans.edit', $plan) }}" class="font-semibold text-emerald-700">{{ __('platform-features.edit') }} →</a>
                </div>
            </article>
        @empty
            <div class="rounded-3xl border border-dashed bg-white p-10 text-center text-zinc-500 md:col-span-2 xl:col-span-3">{{ __('platform-features.empty') }}</div>
        @endforelse
    </section>
    @if($plans->hasPages())<div>{{ $plans->links() }}</div>@endif
    </div>
</x-platform-layout>
