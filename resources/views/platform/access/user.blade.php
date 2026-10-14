<x-platform-layout :title="__('platform-access.manage')">
    <div class="platform-management platform-access">
        <header class="platform-management__header">
            <a href="{{ route('platform.access.index') }}" class="text-sm text-emerald-700">{{ __('platform-access.back_users') }}</a>
            <h1 class="mt-3 font-bold">{{ $administrator->name }}</h1>
            <p class="mt-2 text-zinc-500" dir="ltr">{{ $administrator->email }}</p>
            <div class="mt-4 flex flex-wrap gap-2"><span class="platform-request-status">{{ __('platform-access.'.($administrator->is_active ? 'active' : 'inactive')) }}</span>@if($administrator->must_change_password)<span class="platform-request-status">{{ __('platform-access.password_required') }}</span>@endif</div>
        </header>
        <x-platform.access-tabs current="users" />
        <nav class="platform-request-tabs" aria-label="{{ __('platform-access.manage') }}">
            <a href="#account-details">{{ __('platform-access.account_details') }}</a>
            @if(auth('platform')->user()->isPlatformOwner())<a href="#assigned-roles">{{ __('platform-access.assigned_roles') }}</a>@endif
            <a href="#account-password">{{ __('platform-access.reset_password') }}</a><a href="#account-status">{{ __('platform-access.status') }}</a>
        </nav>
        <section id="account-details" class="rounded-3xl border bg-white p-6">
            <h2 class="text-lg font-bold">{{ __('platform-access.account_details') }}</h2>
            <form method="POST" action="{{ route('platform.access.users.update', $administrator) }}" class="mt-5 space-y-4">
                @csrf @method('PUT')
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.name') }}<input name="name" value="{{ old('name', $administrator->name) }}" required maxlength="255" class="rounded-xl border p-3"></label>
                    <label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.email') }}<input name="email" value="{{ old('email', $administrator->email) }}" type="email" required maxlength="255" class="rounded-xl border p-3" dir="ltr"></label>
                </div>
                <button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ __('platform-access.save_details') }}</button>
            </form>
        </section>
        @if(auth('platform')->user()->isPlatformOwner())
            <section id="assigned-roles" class="rounded-3xl border bg-white p-6">
                <h2 class="text-lg font-bold">{{ __('platform-access.assigned_roles') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-access.roles_help') }}</p>
                <form method="POST" action="{{ route('platform.access.users.roles', $administrator) }}" class="mt-5 space-y-4">
                    @csrf @method('PUT')
                    <input type="hidden" name="workspace_form" value="user_roles">
                    <fieldset><legend class="sr-only">{{ __('platform-access.assigned_roles') }}</legend><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($roles as $role)<label class="flex gap-3 rounded-xl border p-3 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(old('workspace_form') === 'user_roles' ? in_array($role->id, old('roles', [])) : $administrator->roles->contains($role))><span>{{ $role->name }}</span></label>@endforeach
                    </div></fieldset>
                    <button class="rounded-xl border px-5 py-3 font-semibold">{{ __('platform-access.save_roles') }}</button>
                </form>
            </section>
        @endif
        <section id="account-password" class="rounded-3xl border bg-white p-6">
            <h2 class="text-lg font-bold">{{ __('platform-access.reset_password') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-access.reset_help') }}</p>
            <form method="POST" action="{{ route('platform.access.users.password', $administrator) }}" class="mt-5 space-y-4">
                @csrf @method('PUT')
                <div class="grid gap-4 sm:grid-cols-2"><label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.password') }}<input name="password" type="password" required minlength="12" autocomplete="new-password" class="rounded-xl border p-3"></label><label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.confirm_password') }}<input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="rounded-xl border p-3"></label></div>
                <button class="rounded-xl border px-5 py-3 font-semibold" onclick="return confirm(@js(__('platform-access.reset_confirm')))">{{ __('platform-access.reset_password') }}</button>
            </form>
        </section>
        <section id="account-status" class="platform-tenant-danger rounded-3xl border bg-white p-6">
            <h2 class="text-lg font-bold">{{ __('platform-access.status') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-access.status_help') }}</p>
            <form method="POST" action="{{ route('platform.access.users.status', $administrator) }}" class="mt-5">
                @csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $administrator->is_active ? 0 : 1 }}">
                <button class="rounded-xl border px-5 py-3 font-semibold" @if($administrator->is_active) onclick="return confirm(@js(__('platform-access.deactivate_confirm')))" @endif>{{ __('platform-access.'.($administrator->is_active ? 'deactivate' : 'activate')) }}</button>
            </form>
        </section>
    </div>
</x-platform-layout>
