<x-platform-layout :title="__('platform-access.roles')">
    <div class="platform-management platform-access">
        <header class="platform-management__header"><p class="text-sm font-semibold text-emerald-700">{{ __('platform-workspace.groups.administration') }}</p><h1 class="mt-2 font-bold">{{ __('platform-access.roles') }}</h1><p class="mt-3 max-w-3xl text-zinc-500">{{ __('platform-access.role_intro') }}</p></header>
        <x-platform.access-tabs current="roles" />
        @if($errors->has('role'))<p class="rounded-xl border p-4" role="alert">{{ $errors->first('role') }}</p>@endif
        <details class="rounded-3xl border bg-white p-6" @if($errors->roleForm->any()) open @endif>
            <summary class="cursor-pointer font-bold">{{ __('platform-access.create_role') }}</summary>
            <form method="POST" action="{{ route('platform.access.roles.store') }}" class="mt-5 space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2"><label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.role_name') }}<input name="name" value="{{ old('name') }}" required maxlength="100" class="rounded-xl border p-3"></label><label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.description') }}<textarea name="description" maxlength="1000" class="rounded-xl border p-3">{{ old('description') }}</textarea></label></div>
                <x-platform.permission-picker :groups="$permissions" :selected="old('permissions', [])" />
                @if($errors->roleForm->any())<p class="text-sm text-red-700" role="alert">{{ $errors->roleForm->first() }}</p>@endif
                <button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ __('platform-access.create_role') }}</button>
            </form>
        </details>
        <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach($roles as $role)
                <article class="platform-access-role rounded-3xl border bg-white p-6">
                    <div class="flex flex-wrap gap-2">@if($role->is_owner)<span class="platform-request-status">{{ __('platform-access.owner') }}</span>@endif<span class="platform-request-status">{{ __('platform-access.assigned_count', ['count' => $role->administrators_count]) }}</span></div>
                    <h2 class="mt-4 text-xl font-bold">{{ $role->name }}</h2><p class="mt-2 text-sm text-zinc-500">{{ $role->description ?: __('platform-access.no_description') }}</p>
                    <p class="mt-5 text-sm">{{ $role->is_owner ? __('platform-access.all_permissions') : __('platform-access.permission_count', ['count' => $role->permissions->count()]) }}</p>
                    <a class="mt-5 block text-sm font-semibold text-emerald-700" href="{{ route('platform.access.roles.edit', $role) }}">{{ __('platform-access.review_role') }}</a>
                </article>
            @endforeach
        </section>
    </div>
</x-platform-layout>
