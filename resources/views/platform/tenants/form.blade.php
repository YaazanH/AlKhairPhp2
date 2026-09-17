<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="bg-zinc-50"><main class="mx-auto max-w-4xl space-y-6 p-6 md:p-10">
<a href="{{ route('platform.dashboard') }}" class="text-sm text-emerald-700">Back to tenants</a>
<header><p class="text-sm text-emerald-700">Platform administration</p><h1 class="text-3xl font-bold">{{ $tenant ? 'Manage '.$tenant->name : 'Create tenant' }}</h1></header>
@if ($errors->any())<div class="rounded-xl bg-red-50 p-4 text-red-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if (session('status'))<div class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('status') }}</div>@endif
<section class="rounded-2xl border bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold">Tenant details</h2>
<form method="POST" action="{{ $tenant ? route('platform.tenants.update', $tenant) : route('platform.tenants.store') }}" class="mt-5 grid gap-4 md:grid-cols-2">
@csrf
@if ($tenant)
@method('PUT')
@endif
<input name="name" value="{{ old('name', $tenant?->name) }}" placeholder="Organisation name" required class="rounded-xl border p-3">
<input name="slug" value="{{ old('slug', $tenant?->slug) }}" placeholder="Subdomain" required class="rounded-xl border p-3">
<select name="timezone" class="rounded-xl border p-3"><option value="">Default timezone</option><option value="Asia/Damascus" @selected(old('timezone', $tenant?->timezone) === 'Asia/Damascus')>Asia/Damascus</option><option value="UTC" @selected(old('timezone', $tenant?->timezone) === 'UTC')>UTC</option></select>
<select name="locale" class="rounded-xl border p-3"><option value="">Default language</option><option value="ar" @selected(old('locale', $tenant?->locale) === 'ar')>Arabic</option><option value="en" @selected(old('locale', $tenant?->locale) === 'en')>English</option></select>
@unless ($tenant)
<input name="owner_name" placeholder="Tenant administrator name" required class="rounded-xl border p-3"><input name="owner_email" type="email" placeholder="Tenant administrator email" required class="rounded-xl border p-3"><input name="owner_password" type="password" placeholder="Temporary password" required class="rounded-xl border p-3">
<select name="plan" class="rounded-xl border p-3">@foreach ($plans as $plan)<option value="{{ $plan->code }}">{{ $plan->name }}</option>@endforeach</select>
@endunless
<button class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white md:col-span-2">{{ $tenant ? 'Save changes' : 'Create tenant' }}</button></form></section>
@if ($tenant)
<section class="grid gap-6 md:grid-cols-2"><div class="rounded-2xl border bg-white p-6 shadow-sm"><h2 class="font-semibold">Package</h2><form class="mt-4 flex gap-2" method="POST" action="{{ route('platform.tenants.subscription.update', $tenant) }}">@csrf @method('PUT')<select name="plan" class="min-w-0 flex-1 rounded-xl border p-3">@foreach ($plans as $plan)<option value="{{ $plan->code }}" @selected($tenant->subscription?->plan?->code === $plan->code)>{{ $plan->name }}</option>@endforeach</select><button class="rounded-xl border px-4">Save</button></form></div><div class="rounded-2xl border bg-white p-6 shadow-sm"><h2 class="font-semibold">Lifecycle</h2><form class="mt-4" method="POST" action="{{ route('platform.tenants.status', $tenant) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $tenant->status === 'suspended' ? 'active' : 'suspended' }}"><button class="rounded-xl bg-amber-500 px-4 py-3 text-white">{{ $tenant->status === 'suspended' ? 'Activate' : 'Suspend' }}</button></form></div></section>
<section class="rounded-2xl border border-red-200 bg-red-50 p-6"><h2 class="font-semibold text-red-900">Delete tenant</h2><p class="mt-1 text-sm text-red-800">Permanently removes its database and files. Type <strong>{{ $tenant->slug }}</strong> to confirm.</p><form method="POST" action="{{ route('platform.tenants.destroy', $tenant) }}" class="mt-4 flex max-w-lg gap-2" onsubmit="return confirm('Permanently delete this tenant?')">@csrf @method('DELETE')<input name="confirm_slug" placeholder="{{ $tenant->slug }}" class="min-w-0 flex-1 rounded-xl border border-red-300 p-3"><button class="rounded-xl bg-red-700 px-4 py-3 text-white">Delete</button></form></section>
@endif
</main>@fluxScripts</body></html>
