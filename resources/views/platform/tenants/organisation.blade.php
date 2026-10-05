@php($canManageTenant = auth('platform')->user()->hasPlatformPermission('manage.tenants'))
<x-platform.tenant-workspace :tenant="$tenant" current="organisation">
    <div class="space-y-6">
        <section class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">Organisation information</h2><p class="mt-1 text-sm text-zinc-500">Changing the organisation name never changes its permanent address or database.</p>
            <form method="POST" action="{{ route('platform.tenants.update', $tenant) }}" class="mt-6 grid gap-5 md:grid-cols-2">@csrf @method('PUT')
                <label class="grid gap-1.5 text-sm font-semibold">Organisation name<input name="name" value="{{ old('name', $tenant->name) }}" required class="rounded-xl border p-3 font-normal">@error('name')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1.5 text-sm font-semibold">Permanent tenant address<input value="{{ $tenant->slug }}.{{ config('tenancy.base_domain') }}" readonly class="rounded-xl border bg-zinc-50 p-3 font-normal text-zinc-500"></label>
                <label class="grid gap-1.5 text-sm font-semibold">Timezone<select name="timezone" class="rounded-xl border p-3 font-normal"><option value="">Platform default</option><option value="Asia/Damascus" @selected(old('timezone', $tenant->timezone) === 'Asia/Damascus')>Asia/Damascus</option><option value="UTC" @selected(old('timezone', $tenant->timezone) === 'UTC')>UTC</option></select></label>
                <label class="grid gap-1.5 text-sm font-semibold">Default language<select name="locale" class="rounded-xl border p-3 font-normal"><option value="">Platform default</option><option value="ar" @selected(old('locale', $tenant->locale) === 'ar')>Arabic</option><option value="en" @selected(old('locale', $tenant->locale) === 'en')>English</option></select></label>
                @if($canManageTenant)<div class="md:col-span-2"><button class="rounded-xl bg-zinc-950 px-5 py-3 font-bold text-white">Save organisation</button></div>@endif
            </form>
        </section>
        @if($canManageTenant)<section class="rounded-3xl border bg-white p-6 shadow-sm"><h2 class="text-xl font-bold">Reset tenant administrator password</h2><p class="mt-1 text-sm text-zinc-500">The administrator will be required to replace this temporary password at the next login.</p><form method="POST" action="{{ route('platform.tenants.administrator-password', $tenant) }}" class="mt-5 grid gap-4 md:grid-cols-2">@csrf @method('PUT')<input type="password" name="password" required minlength="8" placeholder="Temporary password" class="rounded-xl border p-3"><input type="password" name="password_confirmation" required placeholder="Confirm password" class="rounded-xl border p-3"><div class="md:col-span-2"><button class="rounded-xl border border-zinc-300 px-5 py-3 font-semibold">Reset password</button></div></form></section>@endif
    </div>
</x-platform.tenant-workspace>
