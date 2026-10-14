@php
    $editing = $plan->exists;
    $selected = collect(old('modules', $selectedModules))->all();
    $moduleNames = collect($catalog)->mapWithKeys(fn ($module) => [$module['code'] => app(\App\Support\PlatformFeaturePresentation::class)->name($module['code'])]);
@endphp
<x-platform-layout :title="$editing ? __('platform-features.edit') : __('platform-features.create')">
    <div class="platform-management platform-package-editor" data-platform-management>
    <header class="platform-management__header flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div><a href="{{ route('platform.plans.index') }}" class="text-sm font-medium text-emerald-700">{{ __('platform-features.back') }}</a><h1 class="mt-3 text-3xl font-bold">{{ $editing ? __('platform-features.edit').' — '.$plan->name : __('platform-features.create') }}</h1><p class="mt-1 text-zinc-500">{{ __('platform-features.form_help') }}</p></div>
        @if ($editing)<span class="rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600">{{ __('platform-features.tenants', ['count' => $plan->subscriptions->count()]) }}</span>@endif
    </header>

    <nav class="platform-package-tabs" aria-label="{{ __('platform-features.title') }}"><a href="#package-details">{{ __('platform-features.details') }}</a><a href="#package-features">{{ __('platform-features.included') }}</a>@if($editing)<a href="#package-impact">{{ __('platform-features.impact') }}</a>@endif</nav>
    <form method="POST" action="{{ $editing ? route('platform.plans.update', $plan) : route('platform.plans.store') }}" class="space-y-6">
        @csrf
        @if ($editing) @method('PUT') @endif
        <section id="package-details" class="rounded-3xl border bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">{{ __('platform-features.details') }}</h2>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <label class="grid gap-1 text-sm font-medium">{{ __('platform-features.name') }}<input name="name" value="{{ old('name', $submitted['name'] ?? $plan->name) }}" required class="rounded-xl border p-3 font-normal @error('name') border-red-400 @enderror">@error('name')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                @if ($editing)<label class="grid gap-1 text-sm font-medium">{{ __('platform-features.code') }}<input value="{{ $plan->code }}" disabled class="rounded-xl border bg-zinc-50 p-3 font-normal text-zinc-500"></label>@else<label class="grid gap-1 text-sm font-medium">{{ __('platform-features.code') }}<input name="code" value="{{ old('code') }}" required pattern="[A-Za-z0-9_\-]+" class="rounded-xl border p-3 font-normal @error('code') border-red-400 @enderror" placeholder="standard">@error('code')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>@endif
                <label class="grid gap-1 text-sm font-medium md:col-span-2">{{ __('platform-features.description') }}<textarea name="description" rows="3" class="rounded-xl border p-3 font-normal @error('description') border-red-400 @enderror">{{ old('description', $submitted['description'] ?? $plan->description) }}</textarea>@error('description')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-medium">{{ __('platform-features.price') }}<input type="number" min="0" name="price_syp" value="{{ old('price_syp', $submitted['price_syp'] ?? $plan->price_syp) }}" required class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">{{ __('platform-features.free_help') }}</span></label><label class="grid gap-1 text-sm font-medium">{{ __('platform-features.period') }}<input type="number" min="1" max="3650" name="billing_period_days" value="{{ old('billing_period_days', $submitted['billing_period_days'] ?? $plan->billing_period_days ?? 30) }}" required class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">{{ __('platform-features.period_help') }}</span></label>
                <div class="grid gap-3 md:col-span-2 md:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-2xl border p-4 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="mt-1" @checked((bool) old('is_active', $submitted['is_active'] ?? $plan->is_active))><span><strong class="block">{{ __('platform-features.assignable') }}</strong><small class="mt-1 block font-normal text-zinc-500">{{ __('platform-features.assignable_help') }}</small></span></label>
                    <label class="flex items-start gap-3 rounded-2xl border p-4 text-sm"><input type="hidden" name="is_public" value="0"><input type="checkbox" name="is_public" value="1" class="mt-1" @checked((bool) old('is_public', $submitted['is_public'] ?? $plan->is_public))><span><strong class="block">{{ __('platform-features.show_public') }}</strong><small class="mt-1 block font-normal text-zinc-500">{{ __('platform-features.public_help') }}</small></span></label>
                </div>
                @error('is_public')<span class="text-xs font-normal text-red-700 md:col-span-2">{{ $message }}</span>@enderror
            </div>
        </section>

        <section id="package-features" class="rounded-3xl border bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">{{ __('platform-features.included') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-features.selection_help') }}</p>
            <x-platform.feature-picker :catalog="$catalog" :selected="$selected" />
            @error('modules')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
        </section>
        @if ($editing)
            <section id="package-impact" class="rounded-3xl border bg-white p-6 shadow-sm" data-feature-impact>
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"><div><h2 class="text-lg font-semibold">{{ __('platform-features.impact') }}</h2><p class="mt-1 text-sm text-zinc-500">{{ __('platform-features.impact_help') }}</p></div><button formaction="{{ route('platform.plans.preview', $plan) }}" class="rounded-xl border px-4 py-3 font-medium">{{ __('platform-features.preview') }}</button></div>
                <p class="platform-feature-stale">{{ __('platform-features.stale') }}</p>
                @if ($preview)
                    <div class="mt-5 rounded-2xl bg-zinc-50 p-4"><p class="text-sm font-semibold">{{ __('platform-features.effective') }} {{ collect($preview['effective'])->reject(fn ($code) => $code === 'foundation')->count() }}</p>
                        <div class="mt-3 grid gap-3">@forelse ($preview['tenants'] as $impact)<div class="rounded-xl border bg-white p-4"><div class="font-semibold">{{ $impact['tenant']->name }}</div>@foreach($impact['errors'] as $conflict)<p class="mt-2 text-sm text-red-700" role="alert">{{ __('platform-features.impact_error') }}: {{ $conflict }}</p>@endforeach<div class="mt-2 flex flex-wrap gap-2 text-xs">@foreach($impact['added'] as $code)<span class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-800">+ {{ $moduleNames[$code] ?? $code }}</span>@endforeach @foreach($impact['removed'] as $code)<span class="rounded-full bg-red-100 px-2 py-1 text-red-800">− {{ $moduleNames[$code] ?? $code }}</span>@endforeach @if(!$impact['added'] && !$impact['removed'])<span class="text-zinc-500">{{ __('platform-features.no_change') }}</span>@endif</div></div>@empty<p class="text-sm text-zinc-500">{{ __('platform-features.no_tenants') }}</p>@endforelse</div>
                    </div>
                    @if ($preview['tenants']->isNotEmpty())<label class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><input type="checkbox" name="confirm_impact" value="1" class="mt-1"><span>{{ __('platform-features.confirm_impact') }}</span></label>@endif
                @endif
            </section>
        @endif

        <div class="platform-management__actions flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="{{ route('platform.plans.index') }}" class="rounded-xl border px-5 py-3 text-center">{{ __('platform-features.cancel') }}</a><button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ $editing ? __('platform-features.save') : __('platform-features.create') }}</button></div>
    </form>

    @if ($editing)
        <section class="grid gap-5 md:grid-cols-2">
            <form method="POST" action="{{ route('platform.plans.duplicate', $plan) }}" class="rounded-3xl border bg-white p-6 shadow-sm">@csrf<h2 class="font-semibold">{{ __('platform-features.duplicate') }}</h2><p class="mt-1 text-sm text-zinc-500">{{ __('platform-features.duplicate_help') }}</p><div class="mt-4 flex gap-2"><input name="name" value="{{ $plan->name }} Copy" class="min-w-0 flex-1 rounded-xl border p-3"><button class="rounded-xl border px-4">{{ __('platform-features.duplicate') }}</button></div></form>
            <div class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="font-semibold">{{ __('platform-features.lifecycle') }}</h2><p class="mt-1 text-sm text-zinc-500">{{ __('platform-features.assignable_help') }}</p><form method="POST" action="{{ route('platform.plans.status', $plan) }}" class="mt-4">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $plan->is_active ? 0 : 1 }}"><button class="rounded-xl border px-4 py-3">{{ $plan->is_active ? __('platform-features.deactivate') : __('platform-features.activate') }}</button></form>@if($plan->subscriptions->isEmpty())<form method="POST" action="{{ route('platform.plans.destroy', $plan) }}" class="mt-3" onsubmit="return confirm(@js(__('platform-features.delete_confirm')))">@csrf @method('DELETE')<button class="text-sm font-medium text-red-700">{{ __('platform-features.delete') }}</button></form>@endif</div>
        </section>
    @endif
    </div>
</x-platform-layout>
