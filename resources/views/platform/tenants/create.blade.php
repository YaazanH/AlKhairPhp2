<x-platform-layout title="Create tenant">
    <div class="mx-auto max-w-5xl space-y-6">
        <header>
            <a href="{{ route('platform.dashboard') }}" class="text-sm font-semibold text-emerald-700">← Back to tenants</a>
            <h1 class="mt-3 text-3xl font-bold text-zinc-950">Create a tenant workspace</h1>
            <p class="mt-2 max-w-2xl text-zinc-600">Create the organisation, its permanent address, isolated database, first administrator, and storage limit. Package, voucher, and payment setup come afterward.</p>
        </header>

        @if($errors->has('tenant'))<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first('tenant') }}</div>@endif

        <form method="POST" action="{{ route('platform.tenants.store') }}" class="space-y-6">
            @csrf
            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex items-start gap-4"><span class="grid h-10 w-10 place-items-center rounded-2xl bg-emerald-100 font-bold text-emerald-800">1</span><div><h2 class="text-lg font-semibold">Organisation</h2><p class="text-sm text-zinc-500">The name can change later. The subdomain cannot.</p></div></div>
                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <label class="grid gap-1.5 text-sm font-semibold">Organisation name<input name="name" value="{{ old('name') }}" required class="rounded-xl border p-3 font-normal">@error('name')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Subdomain<input name="slug" value="{{ old('slug') }}" required data-tenant-slug class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">Address: <strong data-tenant-url>https://your-name.{{ config('tenancy.base_domain') }}</strong></span>@error('slug')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Timezone<select name="timezone" class="rounded-xl border p-3 font-normal"><option value="">Platform default</option><option value="Asia/Damascus" @selected(old('timezone') === 'Asia/Damascus')>Asia/Damascus</option><option value="UTC" @selected(old('timezone') === 'UTC')>UTC</option></select>@error('timezone')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Default language<select name="locale" class="rounded-xl border p-3 font-normal"><option value="">Platform default</option><option value="ar" @selected(old('locale') === 'ar')>Arabic</option><option value="en" @selected(old('locale') === 'en')>English</option></select>@error('locale')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Storage limit (GB)<input type="number" name="storage_limit_gb" value="{{ old('storage_limit_gb', 10) }}" step="0.1" min="0.1" required class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">A tenant-level limit for its database, documents, media, and backups.</span>@error('storage_limit_gb')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                </div>
            </section>
            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex items-start gap-4"><span class="grid h-10 w-10 place-items-center rounded-2xl bg-sky-100 font-bold text-sky-800">2</span><div><h2 class="text-lg font-semibold">First tenant administrator</h2><p class="text-sm text-zinc-500">Share the temporary password manually. The administrator must replace it after signing in.</p></div></div>
                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <label class="grid gap-1.5 text-sm font-semibold">Full name<input name="owner_name" value="{{ old('owner_name') }}" required class="rounded-xl border p-3 font-normal">@error('owner_name')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Email address<input type="email" name="owner_email" value="{{ old('owner_email') }}" required class="rounded-xl border p-3 font-normal">@error('owner_email')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold md:col-span-2">Temporary password<input type="password" name="owner_password" required minlength="8" class="rounded-xl border p-3 font-normal">@error('owner_password')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                </div>
            </section>
            <div class="flex flex-col gap-4 rounded-2xl bg-zinc-950 p-5 text-white sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-zinc-300">The tenant remains in setup until its subscription is activated.</p><button class="rounded-xl bg-emerald-400 px-5 py-3 font-bold text-zinc-950 hover:bg-emerald-300">Create tenant workspace</button></div>
        </form>
    </div>
    <script>document.addEventListener('DOMContentLoaded',()=>{const i=document.querySelector('[data-tenant-slug]'),o=document.querySelector('[data-tenant-url]');if(!i||!o)return;const update=()=>{const slug=i.value.trim().toLowerCase().replace(/[^a-z0-9_-]+/g,'-').replace(/^-+|-+$/g,'')||'your-name';o.textContent=`https://${slug}.{{ config('tenancy.base_domain') }}`};i.addEventListener('input',update);update()})</script>
</x-platform-layout>
