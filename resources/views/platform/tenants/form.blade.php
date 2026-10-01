@php
    $editing = (bool) $tenant;
    $moduleNames = collect($moduleCatalog)->mapWithKeys(fn ($module) => [$module['code'] => $module['name']]);
    $selectedExtras = old('modules', $editing ? $moduleSnapshot['extras'] : []);
    $modulePreview = session('module_preview');
@endphp
<x-platform-layout :title="$editing ? 'Manage tenant' : 'Create tenant'">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div><a href="{{ route('platform.dashboard') }}" class="text-sm font-medium text-emerald-700">← Back to tenants</a><h1 class="mt-3 text-3xl font-bold">{{ $editing ? 'Manage '.$tenant->name : 'Create tenant' }}</h1><p class="mt-1 text-zinc-500">{{ $editing ? $tenant->slug.'.'.config('tenancy.base_domain') : 'Provision an isolated tenant and its first administrator.' }}</p></div>
        @if($editing)<span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium">{{ str($tenant->status)->replace('_', ' ')->title() }}</span>@endif
    </header>

    <section class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold">Tenant details</h2>
        <form method="POST" action="{{ $editing ? route('platform.tenants.update', $tenant) : route('platform.tenants.store') }}" class="mt-5 grid gap-4 md:grid-cols-2">
            @csrf @if($editing) @method('PUT') @endif
            <label class="grid gap-1 text-sm font-medium">Organisation name<input name="name" value="{{ old('name', $tenant?->name) }}" required class="rounded-xl border p-3 font-normal @error('name') border-red-400 @enderror">@error('name')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
            @if($editing)
                <label class="grid gap-1 text-sm font-medium">Tenant address<input value="{{ $tenant->slug }}.{{ config('tenancy.base_domain') }}" readonly class="rounded-xl border bg-zinc-50 p-3 font-normal text-zinc-500"><span class="text-xs font-normal text-zinc-500">This address is permanent. Renaming the organisation does not change it.</span></label>
            @else
                <label class="grid gap-1 text-sm font-medium">Subdomain<input name="slug" value="{{ old('slug') }}" required data-tenant-slug class="rounded-xl border p-3 font-normal @error('slug') border-red-400 @enderror"><span class="text-xs font-normal text-zinc-500">Your tenant will use <strong data-tenant-url>https://your-name.{{ config('tenancy.base_domain') }}</strong>. This address cannot be changed later.</span>@error('slug')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
            @endif
            <label class="grid gap-1 text-sm font-medium">Timezone<select name="timezone" class="rounded-xl border p-3 font-normal @error('timezone') border-red-400 @enderror"><option value="">Default timezone</option><option value="Asia/Damascus" @selected(old('timezone', $tenant?->timezone) === 'Asia/Damascus')>Asia/Damascus</option><option value="UTC" @selected(old('timezone', $tenant?->timezone) === 'UTC')>UTC</option></select>@error('timezone')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1 text-sm font-medium">Default language<select name="locale" class="rounded-xl border p-3 font-normal @error('locale') border-red-400 @enderror"><option value="">Default language</option><option value="ar" @selected(old('locale', $tenant?->locale) === 'ar')>Arabic</option><option value="en" @selected(old('locale', $tenant?->locale) === 'en')>English</option></select>@error('locale')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
            @unless($editing)
                <label class="grid gap-1 text-sm font-medium">Tenant administrator name<input name="owner_name" value="{{ old('owner_name') }}" required class="rounded-xl border p-3 font-normal @error('owner_name') border-red-400 @enderror">@error('owner_name')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-medium">Tenant administrator email (login username)<input name="owner_email" value="{{ old('owner_email') }}" type="email" required class="rounded-xl border p-3 font-normal @error('owner_email') border-red-400 @enderror">@error('owner_email')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-medium">Temporary password<input name="owner_password" type="password" required class="rounded-xl border p-3 font-normal @error('owner_password') border-red-400 @enderror">@error('owner_password')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-medium">Initial package<select name="plan" class="rounded-xl border p-3 font-normal @error('plan') border-red-400 @enderror">@foreach($plans as $plan)<option value="{{ $plan->code }}" @selected(old('plan') === $plan->code)>{{ $plan->name }}</option>@endforeach</select>@error('plan')<span class="text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
            @endunless
            <button class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white md:col-span-2">{{ $editing ? 'Save tenant details' : 'Create tenant' }}</button>
        </form>
    </section>

    @if($editing)
        <section class="grid gap-5 md:grid-cols-2">
            <div class="rounded-3xl border bg-white p-6 shadow-sm"><div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Package</h2><a href="{{ route('platform.plans.index') }}" class="text-sm text-emerald-700">Manage packages</a></div><form class="mt-4 flex flex-col gap-3 sm:flex-row" method="POST" action="{{ route('platform.tenants.subscription.update', $tenant) }}">@csrf @method('PUT')<select name="plan" class="min-w-0 flex-1 rounded-xl border p-3">@foreach($plans as $plan)<option value="{{ $plan->code }}" @selected($tenant->subscription?->plan?->code === $plan->code)>{{ $plan->name }}{{ $plan->is_active ? '' : ' (inactive)' }}</option>@endforeach</select><button class="rounded-xl border px-4 py-3">Save package</button></form></div>
            <div class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="font-semibold">Lifecycle</h2><p class="mt-1 text-sm text-zinc-500">Suspension blocks tenant business access without deleting data.</p><form class="mt-4" method="POST" action="{{ route('platform.tenants.status', $tenant) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $tenant->status === 'suspended' ? 'active' : 'suspended' }}"><button class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">{{ $tenant->status === 'suspended' ? 'Activate tenant' : 'Suspend tenant' }}</button></form></div>
        </section>

        <section class="rounded-3xl border bg-white p-6 shadow-sm">
            <div><p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">Additive only</p><h2 class="mt-1 text-lg font-semibold">Tenant-specific extras</h2><p class="mt-1 text-sm text-zinc-500">Grey checked modules are already supplied by the package and cannot be selected again. Choose only the additional modules needed by this tenant.</p></div>
            <form method="POST" action="{{ route('platform.tenants.extras.update', $tenant) }}" class="mt-5 space-y-5">@csrf @method('PUT')<input type="hidden" name="expected_version" value="{{ $moduleSnapshot['version'] }}">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($moduleCatalog as $module)
                        @php($fromPackage = in_array($module['code'], $packageModules, true))
                        <label class="rounded-2xl border p-4 transition {{ $fromPackage ? 'cursor-not-allowed border-zinc-200 bg-zinc-100 text-zinc-500 opacity-75' : ($module['is_available'] ? 'cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50' : 'cursor-not-allowed opacity-55') }}" @if($fromPackage) data-package-module-card="{{ $module['code'] }}" @endif>
                            <span class="flex items-start gap-3"><input type="checkbox" name="modules[]" value="{{ $module['code'] }}" class="mt-1" @if($fromPackage) data-package-module="{{ $module['code'] }}" @endif @checked($fromPackage || in_array($module['code'], $selectedExtras, true)) @disabled($fromPackage || ! $module['is_available'])><span><span class="block font-semibold">{{ $module['name'] }}</span><span class="mt-1 block text-xs text-zinc-500">{{ $fromPackage ? 'Included by package · manage from the package settings' : 'Optional tenant extra' }}</span></span></span>
                        </label>
                    @endforeach
                </div>
                @if($modulePreview)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><h3 class="font-semibold text-emerald-900">Preview ready</h3><div class="mt-2 flex flex-wrap gap-2 text-xs">@foreach($modulePreview['added'] as $code)<span class="rounded-full bg-white px-2 py-1 text-emerald-800">+ {{ $moduleNames[$code] ?? $code }}</span>@endforeach @foreach($modulePreview['removed'] as $code)<span class="rounded-full bg-white px-2 py-1 text-red-700">− {{ $moduleNames[$code] ?? $code }}</span>@endforeach @if(!$modulePreview['added'] && !$modulePreview['removed'])<span class="text-emerald-800">No effective module change. You may still be changing explicit provenance.</span>@endif</div></div>@endif
                <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><input type="checkbox" name="confirm_extras" value="1" class="mt-1"><span>I reviewed this tenant-only change. It does not modify the shared package.</span></label>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end"><button formaction="{{ route('platform.tenants.extras.preview', $tenant) }}" class="rounded-xl border px-4 py-3">Preview changes</button><button class="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white">Save tenant extras</button></div>
            </form>
        </section>

        @if($moduleAuditEvents->isNotEmpty())<section class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="font-semibold">Recent module changes</h2><div class="mt-4 divide-y">@foreach($moduleAuditEvents as $event)<div class="py-3 text-sm"><div class="flex flex-wrap justify-between gap-2"><span class="font-medium">Extras updated</span><time class="text-zinc-500">{{ $event->created_at->format('Y-m-d H:i') }}</time></div><p class="mt-1 text-zinc-500">{{ count($event->properties['after'] ?? []) }} explicit extras after this change.</p></div>@endforeach</div></section>@endif

        <section class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="font-semibold">Reset tenant administrator password</h2><p class="mt-1 text-sm text-zinc-500">Use this when the tenant administrator has forgotten their password.</p>@if(filled($tenant->database_name))<form method="POST" action="{{ route('platform.tenants.administrator-password', $tenant) }}" class="mt-4 grid max-w-xl gap-3 md:grid-cols-2">@csrf @method('PUT')<label class="grid gap-1 text-sm font-medium">New password<input name="password" type="password" required class="rounded-xl border p-3 font-normal"></label><label class="grid gap-1 text-sm font-medium">Confirm new password<input name="password_confirmation" type="password" required class="rounded-xl border p-3 font-normal"></label><button class="rounded-xl border px-4 py-3 md:col-span-2">Reset password</button></form>@else<p class="mt-3 text-sm text-amber-700">Password reset becomes available after the tenant is provisioned.</p>@endif</section>
        <section class="rounded-3xl border border-red-200 bg-red-50 p-6"><h2 class="font-semibold text-red-900">Delete tenant</h2><p class="mt-1 text-sm text-red-800">Permanently removes its database and files. Type <strong>{{ $tenant->slug }}</strong> to confirm.</p><form method="POST" action="{{ route('platform.tenants.destroy', $tenant) }}" class="mt-4 flex max-w-lg flex-col gap-2 sm:flex-row" onsubmit="return confirm('Permanently delete this tenant?')">@csrf @method('DELETE')<input name="confirm_slug" placeholder="{{ $tenant->slug }}" class="min-w-0 flex-1 rounded-xl border border-red-300 p-3"><button class="rounded-xl bg-red-700 px-4 py-3 text-white">Delete</button></form></section>
    @endif
</x-platform-layout>
@unless($editing)
<script>
document.addEventListener('DOMContentLoaded', () => { const input = document.querySelector('[data-tenant-slug]'); const output = document.querySelector('[data-tenant-url]'); if (!input || !output) return; const update = () => { const slug = input.value.trim().toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, ''); output.textContent = 'https://' + (slug || 'your-name') + '.{{ config('tenancy.base_domain') }}'; }; input.addEventListener('input', update); update(); });
</script>
@endunless
