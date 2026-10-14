<x-platform-layout :title="__('platform-access.title')">
    <div class="platform-management platform-access">
        <header class="platform-management__header">
            <p class="text-sm font-semibold text-emerald-700">{{ __('platform-workspace.groups.administration') }}</p>
            <h1 class="mt-2 font-bold">{{ __('platform-access.title') }}</h1>
            <p class="mt-3 max-w-3xl text-zinc-500">{{ __('platform-access.intro') }}</p>
        </header>
        <x-platform.access-tabs current="users" />
        <section class="platform-operation-stats" aria-label="{{ __('platform-access.users') }}">
            @foreach(['total' => 'total', 'active' => 'active_users', 'password_change_required' => 'password_required'] as $key => $label)
                <article><span>{{ __('platform-access.'.$label) }}</span><strong>{{ number_format($administratorStats[$key]) }}</strong></article>
            @endforeach
        </section>
        <details class="rounded-3xl border bg-white p-6" @if($errors->platformUserForm->any()) open @endif>
            <summary class="cursor-pointer font-bold" data-platform-user-create-action>{{ __('platform-access.create_user') }}</summary>
            <p class="mt-3 text-sm text-zinc-500">{{ __('platform-access.create_help') }}</p>
            @if(!auth('platform')->user()->isPlatformOwner())<p class="mt-2 text-sm text-zinc-500">{{ __('platform-access.no_assignment') }}</p>@endif
            <form method="POST" action="{{ route('platform.access.users.store') }}" class="mt-5 space-y-5">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.name') }}<input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" class="rounded-xl border p-3"></label>
                    <label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.email') }}<input name="email" value="{{ old('email') }}" type="email" required maxlength="255" autocomplete="email" class="rounded-xl border p-3" dir="ltr"></label>
                    <label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.password') }}<input name="password" type="password" required minlength="12" autocomplete="new-password" class="rounded-xl border p-3"></label>
                    <label class="grid gap-2 text-sm font-semibold">{{ __('platform-access.confirm_password') }}<input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" class="rounded-xl border p-3"></label>
                </div>
                @if(auth('platform')->user()->isPlatformOwner())
                    <fieldset><legend class="font-semibold">{{ __('platform-access.assigned_roles') }}</legend><div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($roles as $role)<label class="flex gap-3 rounded-xl border p-3 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, old('roles', [])))><span>{{ $role->name }} @if($role->is_owner) · {{ __('platform-access.owner') }} @endif</span></label>@endforeach
                    </div></fieldset>
                @endif
                @if($errors->platformUserForm->any())<p class="text-sm text-red-700" role="alert">{{ $errors->platformUserForm->first() }}</p>@endif
                <button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ __('platform-access.create') }}</button>
            </form>
        </details>
        <section class="platform-request-queue rounded-3xl border bg-white" data-platform-users-grid>
            <div class="platform-request-heading"><div><h2 class="text-lg font-bold">{{ __('platform-access.users') }}</h2><p>{{ __('platform-access.matching', ['count' => number_format($administrators->total())]) }}</p></div></div>
            <form method="GET" action="{{ route('platform.access.index') }}" class="platform-request-filters">
                <label>{{ __('platform-access.search') }}<input name="search" value="{{ $userSearch }}" class="rounded-xl border p-3"></label>
                <label>{{ __('platform-access.status') }}<select name="status" class="rounded-xl border p-3">@foreach(['all', 'active', 'inactive'] as $status)<option value="{{ $status }}" @selected($userStatus === $status)>{{ __('platform-access.'.$status) }}</option>@endforeach</select></label>
                <button class="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white">{{ __('platform-access.filter') }}</button><a href="{{ route('platform.access.index') }}">{{ __('platform-access.reset') }}</a>
            </form>
            <div class="platform-access-mobile md:hidden">
                @forelse($administrators as $administrator)
                    <article class="space-y-3 p-5" data-platform-user-card="{{ $administrator->id }}">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="font-bold">{{ $administrator->name }}</h3><p class="mt-1 text-sm text-zinc-500" dir="ltr">{{ $administrator->email }}</p></div><span class="platform-request-status">{{ __('platform-access.'.($administrator->is_active ? 'active' : 'inactive')) }}</span></div>
                        <div class="flex flex-wrap gap-2">@forelse($administrator->roles as $role)<span class="platform-request-status">{{ $role->name }}</span>@empty<p class="text-sm text-zinc-500">{{ __('platform-access.no_roles') }}</p>@endforelse</div>
                        @if($administrator->must_change_password)<p class="text-sm text-zinc-500">{{ __('platform-access.password_required') }}</p>@endif
                        <a class="block rounded-xl border px-4 py-3 text-center text-sm font-semibold text-emerald-700" href="{{ route('platform.access.users.edit', $administrator) }}">{{ __('platform-access.manage') }}</a>
                    </article>
                @empty<p class="p-5 text-sm text-zinc-500">{{ __('platform-access.empty') }}</p>@endforelse
            </div>
            <div class="hidden overflow-x-auto md:block"><table class="platform-request-table w-full">
                <thead><tr><th>{{ __('platform-access.user') }}</th><th>{{ __('platform-access.assigned_roles') }}</th><th>{{ __('platform-access.password_state') }}</th><th>{{ __('platform-access.status') }}</th><th>{{ __('platform.ui.common.actions') }}</th></tr></thead>
                <tbody>@forelse($administrators as $administrator)
                    <tr data-platform-user-row="{{ $administrator->id }}">
                        <td><strong>{{ $administrator->name }}</strong><p dir="ltr">{{ $administrator->email }}</p></td>
                        <td><div class="flex flex-wrap gap-2">@forelse($administrator->roles as $role)<span class="platform-request-status">{{ $role->name }}</span>@empty<span>{{ __('platform-access.no_roles') }}</span>@endforelse</div></td>
                        <td>{{ __('platform-access.'.($administrator->must_change_password ? 'password_required' : 'ready')) }}</td>
                        <td><span class="platform-request-status">{{ __('platform-access.'.($administrator->is_active ? 'active' : 'inactive')) }}</span></td>
                        <td><a href="{{ route('platform.access.users.edit', $administrator) }}" class="font-semibold">{{ __('platform-access.manage') }}</a></td>
                    </tr>
                @empty<tr><td colspan="5" class="text-center">{{ __('platform-access.empty') }}</td></tr>@endforelse</tbody>
            </table></div>
            <div class="p-5">{{ $administrators->links() }}</div>
        </section>
    </div>
</x-platform-layout>
