<div class="platform-management platform-tenant-create" data-platform-management>
    <header class="platform-management__header"><a class="text-sm font-semibold text-emerald-700" href="{{ route('platform.tenants.index') }}">{{ __('platform-tenant.back') }}</a><h1 class="mt-3 font-bold">{{ __('platform-tenant.create') }}</h1><p class="mt-3 text-zinc-500">{{ __('platform-tenant.create_help') }}</p></header>
    <ol class="platform-create-steps" aria-label="{{ __('platform-tenant.steps') }}" data-create-steps>
        @foreach(['organisation','administrator','review'] as $step)<li><span>{{ $loop->iteration }}</span>{{ __('platform-tenant.'.$step) }}</li>@endforeach
    </ol>
    <form method="POST" action="{{ route('platform.tenants.store') }}" data-tenant-provisioning-form class="space-y-6">
        @csrf
        <section class="rounded-3xl border bg-white p-6" data-create-step="0"><h2 class="text-xl font-bold" tabindex="-1">{{ __('platform-tenant.organisation') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.permanent_help') }}</p>
            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <label class="grid gap-2 text-sm font-semibold">{{ __('platform.ui.organisation.name') }}<input name="name" required maxlength="255" value="{{ old('name') }}" class="rounded-xl border p-3">@error('name')<small class="text-red-700">{{ $message }}</small>@enderror</label>
                <label class="grid gap-2 text-sm font-semibold">{{ __('platform-tenant.subdomain') }}<input name="slug" required maxlength="80" pattern="[a-zA-Z0-9_\-]+" value="{{ old('slug') }}" class="rounded-xl border p-3" dir="ltr" data-tenant-slug><small class="text-zinc-500" data-tenant-url dir="ltr"></small>@error('slug')<small class="text-red-700">{{ $message }}</small>@enderror</label>
                <label class="grid gap-2 text-sm font-semibold">{{ __('platform.ui.organisation.timezone') }}<select name="timezone" class="rounded-xl border p-3"><option value="">{{ __('platform.ui.common.platform_default') }}</option>@foreach(['Asia/Damascus','UTC'] as $zone)<option @selected(old('timezone') === $zone)>{{ $zone }}</option>@endforeach</select></label>
                <label class="grid gap-2 text-sm font-semibold">{{ __('platform.ui.organisation.language') }}<select name="locale" class="rounded-xl border p-3"><option value="">{{ __('platform.ui.common.platform_default') }}</option><option value="ar" @selected(old('locale') === 'ar')>{{ __('platform.ui.common.arabic') }}</option><option value="en" @selected(old('locale') === 'en')>{{ __('platform.ui.common.english') }}</option></select></label>
                <label class="grid gap-2 text-sm font-semibold">{{ __('platform.ui.storage.limit_gb') }}<input type="number" name="storage_limit_gb" min="0.1" max="100000" step="0.1" required value="{{ old('storage_limit_gb', 10) }}" class="rounded-xl border p-3">@error('storage_limit_gb')<small class="text-red-700">{{ $message }}</small>@enderror</label>
                <fieldset class="md:col-span-2"><legend class="text-sm font-semibold">{{ __('platform-tenant.learning_path') }}</legend><p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.learning_help') }}</p><div class="mt-4 grid gap-3 sm:grid-cols-2">@foreach(\App\Models\Landlord\Tenant::learningPathTypes() as $path)<label class="platform-module-option flex gap-3 rounded-2xl border p-4"><input type="radio" name="learning_path_type" value="{{ $path }}" required @checked(old('learning_path_type') === $path)><span><strong>{{ __('platform-tenant.path_'.$path) }}</strong><small class="mt-2 block text-zinc-500">{{ __('platform-tenant.path_'.$path.'_help') }}</small></span></label>@endforeach</div>@error('learning_path_type')<small class="text-red-700">{{ $message }}</small>@enderror</fieldset>
            </div>
        </section>
        <section class="rounded-3xl border bg-white p-6" data-create-step="1"><h2 class="text-xl font-bold" tabindex="-1">{{ __('platform-tenant.administrator') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.administrator_help') }}</p><div class="mt-6 grid gap-5 md:grid-cols-2">
            <label class="grid gap-2 text-sm font-semibold">{{ __('platform-tenant.owner_name') }}<input name="owner_name" required maxlength="255" value="{{ old('owner_name') }}" class="rounded-xl border p-3">@error('owner_name')<small class="text-red-700">{{ $message }}</small>@enderror</label>
            <label class="grid gap-2 text-sm font-semibold">{{ __('platform-tenant.owner_email') }}<input type="email" name="owner_email" required value="{{ old('owner_email') }}" autocomplete="off" dir="ltr" class="rounded-xl border p-3">@error('owner_email')<small class="text-red-700">{{ $message }}</small>@enderror</label>
            <label class="grid gap-2 text-sm font-semibold md:col-span-2">{{ __('platform-tenant.password') }}<input type="password" name="owner_password" required minlength="8" autocomplete="new-password" class="rounded-xl border p-3">@error('owner_password')<small class="text-red-700">{{ $message }}</small>@enderror</label>
        </div></section>
        <section class="rounded-3xl border bg-white p-6" data-create-step="2"><h2 class="text-xl font-bold" tabindex="-1">{{ __('platform-tenant.review') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-tenant.review_help') }}</p><dl class="platform-create-review mt-6">@foreach(['name','slug','learning_path_type','storage_limit_gb','owner_name','owner_email'] as $field)<div><dt>{{ __('platform-tenant.review_'.$field) }}</dt><dd data-review-field="{{ $field }}">—</dd></div>@endforeach</dl><p class="mt-6 text-sm text-zinc-500">{{ __('platform-tenant.setup_help') }}</p></section>
        <div class="platform-management__actions flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('platform.tenants.index') }}" class="rounded-xl border px-4 py-3">{{ __('platform-tenant.cancel') }}</a>
            <div class="flex gap-3"><button type="button" class="rounded-xl border px-4 py-3" data-create-back hidden>{{ __('platform-tenant.previous') }}</button><button type="button" class="rounded-xl border px-4 py-3" data-create-next hidden>{{ __('platform-tenant.next') }}</button><button type="submit" class="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white" data-tenant-provisioning-submit>{{ __('platform-tenant.create') }}</button></div>
        </div>
    </form>
    <div class="platform-create-busy" data-tenant-provisioning-overlay hidden role="status" aria-live="polite"><div><span class="platform-create-spinner" aria-hidden="true"></span><h2>{{ __('platform-tenant.creating') }}</h2><p>{{ __('platform-tenant.wait_help') }}</p></div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-tenant-provisioning-form]');
    if (!form) return;
    const sections = [...form.querySelectorAll('[data-create-step]')], steps = [...document.querySelectorAll('[data-create-steps] li')];
    const next = form.querySelector('[data-create-next]'), back = form.querySelector('[data-create-back]'), submit = form.querySelector('[data-tenant-provisioning-submit]'), overlay = document.querySelector('[data-tenant-provisioning-overlay]');
    const slug = form.elements.slug, url = document.querySelector('[data-tenant-url]');
    let current = 0, submitting = false;
    const values = () => {
        url.textContent = @js(request()->getScheme().'://') + (slug.value.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'your-name') + '.' + @js(config('tenancy.base_domain')) + @js(in_array(request()->getPort(), [80,443], true) ? '' : ':'.request()->getPort());
        form.querySelectorAll('[data-review-field]').forEach(output => {
            const field = output.dataset.reviewField, control = form.elements[field];
            const selected = field === 'learning_path_type' ? form.querySelector('[name=learning_path_type]:checked') : null;
            output.textContent = selected ? selected.closest('label').querySelector('strong').textContent : (control?.value || '—');
        });
    };
    const show = (index, focus = true) => {
        current = index; sections.forEach((section,i) => section.hidden = i !== current);
        steps.forEach((step,i) => i === current ? step.setAttribute('aria-current','step') : step.removeAttribute('aria-current'));
        back.hidden = current === 0; next.hidden = current === 2; submit.hidden = current !== 2; values();
        if (focus) sections[current].querySelector('h2').focus();
    };
    const validate = index => {
        const invalid = [...sections[index].querySelectorAll('input,select,textarea')].find(control => !control.checkValidity());
        if (invalid) { show(index, false); invalid.reportValidity(); return false; } return true;
    };
    form.noValidate = true;
    next.addEventListener('click', () => { if (validate(current)) show(current + 1); });
    back.addEventListener('click', () => show(current - 1));
    form.addEventListener('input', values); form.addEventListener('change', values);
    const ownerErrors = @js($errors->hasAny(['owner_name','owner_email','owner_password']));
    show(ownerErrors ? 1 : 0, false);
    form.addEventListener('submit', event => {
        if (submitting) { event.preventDefault(); return; }
        if (current !== 2) { event.preventDefault(); if(validate(current)) show(current + 1); return; }
        if(!validate(0) || !validate(1)) { event.preventDefault(); return; }
        submitting = true; form.setAttribute('aria-busy','true'); submit.disabled = true; overlay.hidden = false;
    });
    window.addEventListener('pageshow', () => { submitting = false; form.removeAttribute('aria-busy'); submit.disabled = false; overlay.hidden = true; });
});
</script>
