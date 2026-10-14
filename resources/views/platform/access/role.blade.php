<x-platform-layout :title="__('platform-access.review_role')">
    <div class="platform-management platform-access">
        <header class="platform-management__header"><a class="text-sm text-emerald-700" href="{{ route('platform.access.roles.index') }}">{{ __('platform-access.back_roles') }}</a><h1 class="mt-3 font-bold">{{ $role->name }}</h1><p class="mt-3 text-zinc-500">{{ __('platform-access.assigned_count', ['count' => $role->administrators_count]) }}</p></header>
        <x-platform.access-tabs current="roles" />
        @if($role->is_owner)<p class="rounded-2xl border bg-white p-5">{{ __('platform-access.owner_protected') }}</p>@endif
        <form method="POST" action="{{ route('platform.access.roles.update', $role) }}" class="rounded-3xl border bg-white p-6 space-y-5">
            @csrf @method('PUT')
            <input type="hidden" name="workspace_form" value="role_permissions">
            <div class="grid gap-4 sm:grid-cols-2"><label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.role_name') }}<input name="name" value="{{ old('name', $role->name) }}" required maxlength="100" class="rounded-xl border p-3" @disabled($role->is_owner)></label><label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.description') }}<textarea name="description" maxlength="1000" class="rounded-xl border p-3" @disabled($role->is_owner)>{{ old('description', $role->description) }}</textarea></label></div>
            <h2 class="text-lg font-bold">{{ __('platform-access.permissions') }}</h2>
            <x-platform.permission-picker :groups="$permissions" :selected="$role->is_owner ? $permissions->flatten()->pluck('id')->all() : (old('workspace_form') === 'role_permissions' ? old('permissions', []) : $role->permissions->pluck('id')->all())" :disabled="$role->is_owner" />
            @if(!$role->is_owner)<button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ __('platform-access.save_role') }}</button>@endif
        </form>
        @if(!$role->is_owner)
            <section class="platform-tenant-danger rounded-3xl border bg-white p-6"><h2 class="text-lg font-bold">{{ __('platform-access.delete_role') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('platform-access.delete_help') }}</p><p class="mt-2 text-sm font-semibold">{{ __('platform-access.assigned_count', ['count' => $role->administrators_count]) }}</p>
                <form method="POST" action="{{ route('platform.access.roles.destroy', $role) }}" class="mt-5">@csrf @method('DELETE')<button class="rounded-xl border px-5 py-3 font-semibold" onclick="return confirm(@js(__('platform-access.delete_confirm')))">{{ __('platform-access.delete_role') }}</button></form>
            </section>
        @endif
    </div>
</x-platform-layout>
