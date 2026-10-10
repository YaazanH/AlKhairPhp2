@php
    $editing = $plan->exists;
    $selected = collect(old('modules', $selectedModules))->all();
    $moduleNames = collect($catalog)->mapWithKeys(fn ($module) => [$module['code'] => $module['name']]);
@endphp
<x-platform-layout :title="$editing ? 'Edit package' : 'Create package'">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div><a href="{{ route('platform.plans.index') }}" class="text-sm font-medium text-emerald-700">← Back to packages</a><h1 class="mt-3 text-3xl font-bold">{{ $editing ? 'Edit '.$plan->name : 'Create package' }}</h1><p class="mt-1 text-zinc-500">Choose modules like permissions. Required dependencies are included automatically.</p></div>
        @if ($editing)<span class="rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600">{{ $plan->subscriptions->count() }} assigned tenants</span>@endif
    </header>

    <form method="POST" action="{{ $editing ? route('platform.plans.update', $plan) : route('platform.plans.store') }}" class="space-y-6">
        @csrf
        @if ($editing) @method('PUT') @endif
        <section class="rounded-3xl border bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Package details</h2>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <label class="grid gap-1 text-sm font-medium">Name<input name="name" value="{{ old('name', $submitted['name'] ?? $plan->name) }}" required class="rounded-xl border p-3 font-normal @error('name') border-red-400 @enderror">@error('name')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                @if ($editing)<label class="grid gap-1 text-sm font-medium">Code<input value="{{ $plan->code }}" disabled class="rounded-xl border bg-zinc-50 p-3 font-normal text-zinc-500"></label>@else<label class="grid gap-1 text-sm font-medium">Code<input name="code" value="{{ old('code') }}" required pattern="[A-Za-z0-9_-]+" class="rounded-xl border p-3 font-normal @error('code') border-red-400 @enderror" placeholder="standard">@error('code')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>@endif
                <label class="grid gap-1 text-sm font-medium md:col-span-2">Description<textarea name="description" rows="3" class="rounded-xl border p-3 font-normal @error('description') border-red-400 @enderror">{{ old('description', $submitted['description'] ?? $plan->description) }}</textarea>@error('description')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-medium">Price (SYP)<input type="number" min="0" name="price_syp" value="{{ old('price_syp', $submitted['price_syp'] ?? $plan->price_syp) }}" required class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">0 means a free package.</span></label><label class="grid gap-1 text-sm font-medium">Billing period (days)<input type="number" min="1" max="3650" name="billing_period_days" value="{{ old('billing_period_days', $submitted['billing_period_days'] ?? $plan->billing_period_days ?? 30) }}" required class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">Every assigned tenant inherits this period. Storage is configured on each tenant.</span></label>
                <label class="inline-flex items-center gap-2 text-sm font-medium"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $submitted['is_active'] ?? $plan->is_active))> Available for new tenant assignments</label>
            </div>
        </section>

        <section class="rounded-3xl border bg-white p-6 shadow-sm">
            <div><h2 class="text-lg font-semibold">Included modules</h2><p class="mt-1 text-sm text-zinc-500">Foundation is always present. Select the business capabilities this package owns.</p></div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($catalog as $module)
                    <label class="rounded-2xl border p-4 transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 {{ $module['is_available'] ? 'cursor-pointer' : 'cursor-not-allowed opacity-55' }}">
                        <span class="flex items-start gap-3"><input type="checkbox" name="modules[]" value="{{ $module['code'] }}" class="mt-1" @checked(in_array($module['code'], $selected, true)) @disabled(! $module['is_available'])><span><span class="block font-semibold">{{ $module['name'] }}</span><span class="mt-1 block text-xs text-zinc-500">@if ($module['requires']) Requires {{ collect($module['requires'])->map(fn ($code) => $moduleNames[$code] ?? $code)->implode(', ') }}@else Independent module @endif</span></span></span>
                    </label>
                @endforeach
            </div>
            @error('modules')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
        </section>

        @if ($editing)
            <section class="rounded-3xl border bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"><div><h2 class="text-lg font-semibold">Impact preview</h2><p class="mt-1 text-sm text-zinc-500">Preview compares this selection for every tenant currently assigned to the package.</p></div><button formaction="{{ route('platform.plans.preview', $plan) }}" class="rounded-xl border px-4 py-3 font-medium">Preview tenant impact</button></div>
                @if ($preview)
                    <div class="mt-5 rounded-2xl bg-zinc-50 p-4"><p class="text-sm font-semibold">Effective package modules: {{ collect($preview['effective'])->reject(fn ($code) => $code === 'foundation')->count() }}</p>
                        <div class="mt-3 grid gap-3">@forelse ($preview['tenants'] as $impact)<div class="rounded-xl border bg-white p-4"><div class="font-semibold">{{ $impact['tenant']->name }}</div><div class="mt-2 flex flex-wrap gap-2 text-xs">@foreach($impact['added'] as $code)<span class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-800">+ {{ $moduleNames[$code] ?? $code }}</span>@endforeach @foreach($impact['removed'] as $code)<span class="rounded-full bg-red-100 px-2 py-1 text-red-800">− {{ $moduleNames[$code] ?? $code }}</span>@endforeach @if(!$impact['added'] && !$impact['removed'])<span class="text-zinc-500">No effective change</span>@endif</div></div>@empty<p class="text-sm text-zinc-500">No tenants are assigned to this package.</p>@endforelse</div>
                    </div>
                    @if ($preview['tenants']->isNotEmpty())<label class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><input type="checkbox" name="confirm_impact" value="1" class="mt-1"><span>I reviewed the affected tenants and understand that package changes apply to all of them.</span></label>@endif
                @endif
            </section>
        @endif

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="{{ route('platform.plans.index') }}" class="rounded-xl border px-5 py-3 text-center">Cancel</a><button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ $editing ? 'Save package' : 'Create package' }}</button></div>
    </form>

    @if ($editing)
        <section class="grid gap-5 md:grid-cols-2">
            <form method="POST" action="{{ route('platform.plans.duplicate', $plan) }}" class="rounded-3xl border bg-white p-6 shadow-sm">@csrf<h2 class="font-semibold">Duplicate package</h2><p class="mt-1 text-sm text-zinc-500">Start a separate package with the same module selection.</p><div class="mt-4 flex gap-2"><input name="name" value="{{ $plan->name }} Copy" class="min-w-0 flex-1 rounded-xl border p-3"><button class="rounded-xl border px-4">Duplicate</button></div></form>
            <div class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="font-semibold">Package lifecycle</h2><p class="mt-1 text-sm text-zinc-500">Deactivation prevents new assignments but keeps existing tenant subscriptions.</p><form method="POST" action="{{ route('platform.plans.status', $plan) }}" class="mt-4">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $plan->is_active ? 0 : 1 }}"><button class="rounded-xl border px-4 py-3">{{ $plan->is_active ? 'Deactivate package' : 'Activate package' }}</button></form>@if($plan->subscriptions->isEmpty())<form method="POST" action="{{ route('platform.plans.destroy', $plan) }}" class="mt-3" onsubmit="return confirm('Delete this unused package?')">@csrf @method('DELETE')<button class="text-sm font-medium text-red-700">Delete unused package</button></form>@endif</div>
        </section>
    @endif
</x-platform-layout>
