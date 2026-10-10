<x-platform-layout title="Create tenant">
    <div class="mx-auto max-w-5xl space-y-6">
        <header>
            <a href="{{ route('platform.dashboard') }}" class="text-sm font-semibold text-emerald-700">← Back to tenants</a>
            <h1 class="mt-3 text-3xl font-bold text-zinc-950">Create a tenant workspace</h1>
            <p class="mt-2 max-w-2xl text-zinc-600">Create the organisation, its permanent address, isolated database, first administrator, and storage limit. Package, voucher, and payment setup come afterward.</p>
        </header>

        @if($errors->has('tenant'))<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $errors->first('tenant') }}</div>@endif

        <form method="POST" action="{{ route('platform.tenants.store') }}" class="space-y-6" data-tenant-provisioning-form>
            @csrf
            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex items-start gap-4"><span class="grid h-10 w-10 place-items-center rounded-2xl bg-emerald-100 font-bold text-emerald-800">1</span><div><h2 class="text-lg font-semibold">Organisation</h2><p class="text-sm text-zinc-500">The name can change later. The subdomain cannot.</p></div></div>
                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <label class="grid gap-1.5 text-sm font-semibold">Organisation name<input name="name" value="{{ old('name') }}" required class="rounded-xl border p-3 font-normal">@error('name')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Subdomain<input name="slug" value="{{ old('slug') }}" required data-tenant-slug class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">Address: <strong data-tenant-url>https://your-name.{{ config('tenancy.base_domain') }}</strong></span>@error('slug')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Timezone<select name="timezone" class="rounded-xl border p-3 font-normal"><option value="">Platform default</option><option value="Asia/Damascus" @selected(old('timezone') === 'Asia/Damascus')>Asia/Damascus</option><option value="UTC" @selected(old('timezone') === 'UTC')>UTC</option></select>@error('timezone')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Default language<select name="locale" class="rounded-xl border p-3 font-normal"><option value="">Platform default</option><option value="ar" @selected(old('locale') === 'ar')>Arabic</option><option value="en" @selected(old('locale') === 'en')>English</option></select>@error('locale')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1.5 text-sm font-semibold">Storage limit (GB)<input type="number" name="storage_limit_gb" value="{{ old('storage_limit_gb', 10) }}" step="0.1" min="0.1" required class="rounded-xl border p-3 font-normal"><span class="text-xs font-normal text-zinc-500">A tenant-level limit for its database, documents, media, and backups.</span>@error('storage_limit_gb')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <fieldset class="md:col-span-2">
                        <legend class="text-sm font-semibold">Learning path</legend>
                        <p class="mt-1 text-xs text-zinc-500">Choose carefully. The learning path is permanent and cannot be changed after the tenant is created.</p>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <label class="flex cursor-pointer gap-3 rounded-2xl border border-zinc-200 p-4">
                                <input type="radio" name="learning_path_type" value="{{ \App\Models\Landlord\Tenant::LEARNING_PATH_QURAN }}" required @checked(old('learning_path_type') === \App\Models\Landlord\Tenant::LEARNING_PATH_QURAN) class="mt-1">
                                <span><strong class="block">Quran</strong><span class="mt-1 block text-xs font-normal text-zinc-500">Memorisation with configurable partial, final, and Awqaf test stages.</span></span>
                            </label>
                            <label class="flex cursor-pointer gap-3 rounded-2xl border border-zinc-200 p-4">
                                <input type="radio" name="learning_path_type" value="{{ \App\Models\Landlord\Tenant::LEARNING_PATH_LESSON_LEVEL }}" required @checked(old('learning_path_type') === \App\Models\Landlord\Tenant::LEARNING_PATH_LESSON_LEVEL) class="mt-1">
                                <span><strong class="block">Lessons and levels</strong><span class="mt-1 block text-xs font-normal text-zinc-500">The tenant builds ordered levels from its lessons, groups, attendance, and assessments.</span></span>
                            </label>
                        </div>
                        @error('learning_path_type')<span class="mt-2 block text-xs text-red-700">{{ $message }}</span>@enderror
                    </fieldset>
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
            <div class="flex flex-col gap-4 rounded-2xl bg-zinc-950 p-5 text-white sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-zinc-300">The tenant remains in setup until its subscription is activated.</p><button type="submit" class="rounded-xl bg-emerald-400 px-5 py-3 font-bold text-zinc-950 transition hover:bg-emerald-300 disabled:cursor-wait disabled:opacity-70" data-tenant-provisioning-submit><span data-submit-label>Create tenant workspace</span><span class="hidden items-center gap-2" data-submit-busy><span class="h-4 w-4 animate-spin rounded-full border-2 border-zinc-950/30 border-t-zinc-950" aria-hidden="true"></span>Creating workspace…</span></button></div>
        </form>
    </div>

    <div class="fixed inset-0 z-50 hidden place-items-center bg-zinc-950/75 p-4 backdrop-blur-sm" data-tenant-provisioning-overlay role="status" aria-live="polite" aria-label="Creating tenant workspace">
        <div class="w-full max-w-md rounded-3xl border border-white/10 bg-white p-7 text-center shadow-2xl">
            <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-emerald-100 text-emerald-700">
                <span class="h-8 w-8 animate-spin rounded-full border-4 border-emerald-200 border-t-emerald-700" aria-hidden="true"></span>
            </div>
            <h2 class="mt-5 text-xl font-bold text-zinc-950">Creating the tenant workspace</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-600">We are preparing the isolated database, storage, settings, and first administrator. This can take up to a minute.</p>
            <div class="mt-5 rounded-2xl bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">Please keep this page open. You will be redirected automatically when setup finishes.</div>
            <div class="mt-5 flex items-center justify-center gap-2 text-xs font-semibold uppercase tracking-wider text-zinc-400"><span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500"></span>Provisioning securely</div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const slugInput = document.querySelector('[data-tenant-slug]');
            const urlOutput = document.querySelector('[data-tenant-url]');
            const form = document.querySelector('[data-tenant-provisioning-form]');
            const submit = document.querySelector('[data-tenant-provisioning-submit]');
            const overlay = document.querySelector('[data-tenant-provisioning-overlay]');
            const submitLabel = document.querySelector('[data-submit-label]');
            const submitBusy = document.querySelector('[data-submit-busy]');
            let submitting = false;

            const updateUrl = () => {
                if (! slugInput || ! urlOutput) return;
                const slug = slugInput.value.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'your-name';
                urlOutput.textContent = `https://${slug}.{{ config('tenancy.base_domain') }}`;
            };

            const resetSubmitting = () => {
                submitting = false;
                form?.removeAttribute('aria-busy');
                if (submit) submit.disabled = false;
                submitLabel?.classList.remove('hidden');
                submitBusy?.classList.add('hidden');
                submitBusy?.classList.remove('inline-flex');
                overlay?.classList.add('hidden');
                overlay?.classList.remove('grid');
                document.body.classList.remove('overflow-hidden');
            };

            slugInput?.addEventListener('input', updateUrl);
            updateUrl();

            form?.addEventListener('submit', (event) => {
                if (submitting) {
                    event.preventDefault();
                    return;
                }

                submitting = true;
                form.setAttribute('aria-busy', 'true');
                if (submit) submit.disabled = true;
                submitLabel?.classList.add('hidden');
                submitBusy?.classList.remove('hidden');
                submitBusy?.classList.add('inline-flex');
                overlay?.classList.remove('hidden');
                overlay?.classList.add('grid');
                document.body.classList.add('overflow-hidden');
            });

            window.addEventListener('pageshow', resetSubmitting);
        });
    </script>
</x-platform-layout>
