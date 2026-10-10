<x-platform-layout title="Platform access">
    <div class="space-y-8" x-data="{ createUser: {{ $errors->platformUserForm->any() ? 'true' : 'false' }}, editingUser: null }">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold text-emerald-700">Platform administration</p>
                <h1 class="mt-1 text-3xl font-bold text-zinc-950">Roles and users</h1>
                <p class="mt-2 max-w-2xl text-zinc-600">Create Platform roles, assign only the permissions needed, and manage Platform users.</p>
            </div>
            <button type="button" x-on:click="createUser = true" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white shadow-sm transition hover:bg-emerald-800" data-platform-user-create-action>
                <span class="text-lg leading-none">+</span> Add Platform user
            </button>
        </header>

        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
        @endif
        @if($errors->has('status') || $errors->has('roles') || $errors->has('role'))
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first('status') ?: ($errors->first('roles') ?: $errors->first('role')) }}</div>
        @endif

        <section class="grid grid-cols-3 gap-2 sm:gap-4" aria-label="Platform user summary">
            <article class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-5"><p class="text-xs font-medium leading-4 text-zinc-500 sm:text-sm">Total users</p><p class="mt-2 text-2xl font-bold text-zinc-950 sm:text-3xl">{{ number_format($administratorStats['total']) }}</p></article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-5"><p class="text-xs font-medium leading-4 text-zinc-500 sm:text-sm">Active users</p><p class="mt-2 text-2xl font-bold text-emerald-700 sm:text-3xl">{{ number_format($administratorStats['active']) }}</p></article>
            <article class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm sm:p-5"><p class="text-xs font-medium leading-4 text-zinc-500 sm:text-sm">Password change required</p><p class="mt-2 text-2xl font-bold text-amber-700 sm:text-3xl">{{ number_format($administratorStats['password_change_required']) }}</p></article>
        </section>

        <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm" data-platform-users-grid>
            <div class="flex flex-col gap-4 border-b border-zinc-200 p-5 lg:flex-row lg:items-center lg:justify-between">
                <div><h2 class="text-lg font-bold text-zinc-950">Platform users</h2><p class="mt-1 text-sm text-zinc-500">{{ number_format($administrators->total()) }} matching users</p></div>
                <form method="GET" action="{{ route('platform.access.index') }}" class="grid gap-3 sm:grid-cols-[minmax(15rem,1fr)_10rem_auto]">
                    <label class="sr-only" for="platform-user-search">Search Platform users</label>
                    <input id="platform-user-search" name="search" value="{{ $userSearch }}" placeholder="Search name or email" class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <label class="sr-only" for="platform-user-status">User status</label>
                    <select id="platform-user-status" name="status" class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="all" @selected($userStatus === 'all')>All statuses</option>
                        <option value="active" @selected($userStatus === 'active')>Active</option>
                        <option value="inactive" @selected($userStatus === 'inactive')>Inactive</option>
                    </select>
                    <button class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Filter</button>
                </form>
            </div>

            @if($administrators->isEmpty())
                <div class="px-6 py-16 text-center"><div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-zinc-100 text-xl text-zinc-500">⌕</div><h3 class="mt-4 font-semibold text-zinc-900">No Platform users found</h3><p class="mt-1 text-sm text-zinc-500">Change the filters or create a new Platform user.</p></div>
            @else
                <div class="divide-y divide-zinc-200 md:hidden">
                    @foreach($administrators as $administrator)
                        @php($initials = Illuminate\Support\Str::of($administrator->name)->explode(' ')->filter()->take(2)->map(fn ($part) => Illuminate\Support\Str::upper(Illuminate\Support\Str::substr($part, 0, 1)))->implode(''))
                        <article class="space-y-4 p-5" data-platform-user-card="{{ $administrator->id }}">
                            <div class="flex items-start justify-between gap-3"><div class="flex min-w-0 items-center gap-3"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-100 text-sm font-bold text-emerald-800">{{ $initials ?: 'PU' }}</span><div class="min-w-0"><h3 class="truncate font-bold text-zinc-950">{{ $administrator->name }}</h3><p class="truncate text-sm text-zinc-500">{{ $administrator->email }}</p></div></div><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $administrator->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700' }}">{{ $administrator->is_active ? 'Active' : 'Inactive' }}</span></div>
                            <div class="flex flex-wrap gap-2">@forelse($administrator->roles as $role)<span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700">{{ $role->name }}</span>@empty<span class="text-sm text-zinc-400">No role assigned</span>@endforelse</div>
                            @if($administrator->must_change_password)<p class="text-xs font-semibold text-amber-700">Temporary password must be changed</p>@endif
                            <button type="button" x-on:click="editingUser = {{ $administrator->id }}" class="w-full rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Manage user</button>
                        </article>
                    @endforeach
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500"><tr><th class="px-6 py-4">User</th><th class="px-6 py-4">Roles</th><th class="px-6 py-4">Password</th><th class="px-6 py-4">Status</th><th class="px-6 py-4 text-right">Actions</th></tr></thead>
                        <tbody class="divide-y divide-zinc-100">
                            @foreach($administrators as $administrator)
                                @php($initials = Illuminate\Support\Str::of($administrator->name)->explode(' ')->filter()->take(2)->map(fn ($part) => Illuminate\Support\Str::upper(Illuminate\Support\Str::substr($part, 0, 1)))->implode(''))
                                <tr class="transition hover:bg-zinc-50" data-platform-user-row="{{ $administrator->id }}">
                                    <td class="px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-emerald-100 text-xs font-bold text-emerald-800">{{ $initials ?: 'PU' }}</span><div><div class="font-bold text-zinc-950">{{ $administrator->name }}</div><div class="text-zinc-500">{{ $administrator->email }}</div></div></div></td>
                                    <td class="px-6 py-4"><div class="flex max-w-sm flex-wrap gap-2">@forelse($administrator->roles as $role)<span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700">{{ $role->name }}</span>@empty<span class="text-zinc-400">No role assigned</span>@endforelse</div></td>
                                    <td class="px-6 py-4"><span class="text-xs font-semibold {{ $administrator->must_change_password ? 'text-amber-700' : 'text-zinc-500' }}">{{ $administrator->must_change_password ? 'Change required' : 'Ready' }}</span></td>
                                    <td class="px-6 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $administrator->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700' }}">{{ $administrator->is_active ? 'Active' : 'Inactive' }}</span></td>
                                    <td class="px-6 py-4 text-right"><button type="button" x-on:click="editingUser = {{ $administrator->id }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-white">Manage</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($administrators->hasPages())<div class="border-t border-zinc-200 px-5 py-4">{{ $administrators->links() }}</div>@endif
            @endif
        </section>

        @if(auth('platform')->user()->isPlatformOwner())
            <section class="space-y-5">
                <div><p class="text-sm font-semibold text-emerald-700">Access design</p><h2 class="mt-1 text-2xl font-bold text-zinc-950">Platform roles</h2><p class="mt-1 text-sm text-zinc-500">Roles apply only to Platform management and never change tenant permissions.</p></div>
                <div class="grid gap-5 xl:grid-cols-[minmax(20rem,0.8fr)_minmax(0,1.2fr)]">
                    <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm"><h3 class="text-lg font-bold">Create a role</h3><form method="POST" action="{{ route('platform.access.roles.store') }}" class="mt-5 space-y-4">@csrf
                        <input name="name" required placeholder="Role name" class="w-full rounded-xl border p-3"><textarea name="description" placeholder="What this role is responsible for" class="w-full rounded-xl border p-3"></textarea>
                        <div class="grid max-h-72 gap-2 overflow-y-auto sm:grid-cols-2">@foreach($permissions as $permission)<label class="flex gap-2 rounded-xl border p-3 text-sm"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}"> <span><strong>{{ $permission->name }}</strong><br><span class="text-zinc-500">{{ $permission->code }}</span></span></label>@endforeach</div>
                        <button class="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white">Create role</button>
                    </form></div>
                    <div class="space-y-3">@foreach($roles as $role)<details class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm" @if($role->is_owner) open @endif><summary class="cursor-pointer font-semibold text-zinc-950">{{ $role->name }} @if($role->is_owner)<span class="ml-2 rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-800">Owner</span>@endif <span class="ml-2 text-sm font-normal text-zinc-500">{{ $role->administrators_count }} users</span></summary><p class="mt-3 text-sm text-zinc-600">{{ $role->description ?: 'No description' }}</p><p class="mt-3 text-sm"><strong>Permissions:</strong> {{ $role->is_owner ? 'All permissions' : ($role->permissions->pluck('name')->join(', ') ?: 'None') }}</p>@if(!$role->is_owner)<form method="POST" action="{{ route('platform.access.roles.update', $role) }}" class="mt-4 space-y-3 border-t pt-4">@csrf @method('PUT')<input name="name" value="{{ $role->name }}" required class="w-full rounded-xl border p-3"><textarea name="description" class="w-full rounded-xl border p-3">{{ $role->description }}</textarea><div class="grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">@foreach($permissions as $permission)<label class="flex gap-2 rounded-xl border p-3 text-sm"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($role->permissions->contains($permission))> {{ $permission->name }}</label>@endforeach</div><div class="flex gap-3"><button class="rounded-xl border px-4 py-2 font-semibold">Save role</button><button formmethod="POST" formaction="{{ route('platform.access.roles.destroy', $role) }}" name="_method" value="DELETE" class="rounded-xl border border-red-300 px-4 py-2 font-semibold text-red-700" onclick="return confirm('Delete this role?')">Delete role</button></div></form>@endif</details>@endforeach</div>
                </div>
            </section>
        @endif

        <div x-cloak x-show="createUser" x-on:keydown.escape.window="createUser = false" class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="create-platform-user-title">
            <div x-on:click.outside="createUser = false" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-4"><div><p class="text-sm font-semibold text-emerald-700">Platform access</p><h2 id="create-platform-user-title" class="mt-1 text-2xl font-bold">Add Platform user</h2><p class="mt-1 text-sm text-zinc-500">The temporary password remains valid until the user changes it.</p></div><button type="button" x-on:click="createUser = false" class="grid h-10 w-10 place-items-center rounded-xl border text-xl text-zinc-500" aria-label="Close">×</button></div>
                <form method="POST" action="{{ route('platform.access.users.store') }}" class="mt-6 grid gap-4">@csrf
                    <label class="grid gap-1.5 text-sm font-semibold">Full name<input name="name" required class="rounded-xl border p-3 font-normal" value="{{ old('name') }}"></label>
                    <label class="grid gap-1.5 text-sm font-semibold">Email address<input name="email" type="email" required class="rounded-xl border p-3 font-normal" value="{{ old('email') }}"></label>
                    <div class="grid gap-4 sm:grid-cols-2"><label class="grid gap-1.5 text-sm font-semibold">Temporary password<input name="password" type="password" required minlength="12" class="rounded-xl border p-3 font-normal"></label><label class="grid gap-1.5 text-sm font-semibold">Confirm password<input name="password_confirmation" type="password" required minlength="12" class="rounded-xl border p-3 font-normal"></label></div>
                    @if(auth('platform')->user()->isPlatformOwner())<fieldset><legend class="text-sm font-semibold">Roles</legend><div class="mt-2 grid gap-2 sm:grid-cols-2">@foreach($roles as $role)<label class="flex gap-2 rounded-xl border p-3 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->id }}"> {{ $role->name }}@if($role->is_owner)<span class="text-amber-700">(Owner)</span>@endif</label>@endforeach</div></fieldset>@endif
                    @if($errors->platformUserForm->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->platformUserForm->first() }}</div>@endif
                    <div class="flex justify-end gap-3 border-t pt-4"><button type="button" x-on:click="createUser = false" class="rounded-xl border px-4 py-2.5 font-semibold">Cancel</button><button class="rounded-xl bg-emerald-700 px-5 py-2.5 font-semibold text-white">Create Platform user</button></div>
                </form>
            </div>
        </div>

        @foreach($administrators as $administrator)
            <div x-cloak x-show="editingUser === {{ $administrator->id }}" x-on:keydown.escape.window="editingUser = null" class="fixed inset-0 z-50 grid place-items-center bg-zinc-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="platform-user-{{ $administrator->id }}-title">
                <div x-on:click.outside="editingUser = null" class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-sm font-semibold text-emerald-700">Platform user</p><h2 id="platform-user-{{ $administrator->id }}-title" class="mt-1 text-2xl font-bold">{{ $administrator->name }}</h2><p class="mt-1 text-sm text-zinc-500">{{ $administrator->email }}</p></div><button type="button" x-on:click="editingUser = null" class="grid h-10 w-10 place-items-center rounded-xl border text-xl text-zinc-500" aria-label="Close">×</button></div>
                    <div class="mt-6 grid gap-5 lg:grid-cols-2">
                        <form method="POST" action="{{ route('platform.access.users.update', $administrator) }}" class="space-y-3 rounded-2xl border p-4">@csrf @method('PUT')<h3 class="font-bold">Account details</h3><input name="name" value="{{ $administrator->name }}" required class="w-full rounded-xl border p-3"><input name="email" type="email" value="{{ $administrator->email }}" required class="w-full rounded-xl border p-3"><button class="rounded-xl border px-4 py-2 font-semibold">Save details</button></form>
                        <form method="POST" action="{{ route('platform.access.users.password', $administrator) }}" class="space-y-3 rounded-2xl border p-4">@csrf @method('PUT')<h3 class="font-bold">Reset temporary password</h3><input name="password" type="password" required minlength="12" placeholder="New password (12+ characters)" class="w-full rounded-xl border p-3"><input name="password_confirmation" type="password" required minlength="12" placeholder="Confirm password" class="w-full rounded-xl border p-3"><button class="rounded-xl border px-4 py-2 font-semibold">Reset password</button></form>
                    </div>
                    @if(auth('platform')->user()->isPlatformOwner())<form method="POST" action="{{ route('platform.access.users.roles', $administrator) }}" class="mt-5 rounded-2xl border p-4">@csrf @method('PUT')<h3 class="font-bold">Assigned roles</h3><div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach($roles as $role)<label class="flex gap-2 rounded-xl border p-3 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked($administrator->roles->contains($role))> {{ $role->name }}</label>@endforeach</div><button class="mt-3 rounded-xl border px-4 py-2 font-semibold">Save roles</button></form>@endif
                    <form method="POST" action="{{ route('platform.access.users.status', $administrator) }}" class="mt-5 flex items-center justify-between gap-4 rounded-2xl border p-4">@csrf @method('PATCH')<div><h3 class="font-bold">Account status</h3><p class="text-sm text-zinc-500">{{ $administrator->is_active ? 'Deactivate this user to prevent Platform login.' : 'Activate this user to restore Platform login.' }}</p></div><input type="hidden" name="is_active" value="{{ $administrator->is_active ? 0 : 1 }}"><button class="shrink-0 rounded-xl border px-4 py-2 font-semibold {{ $administrator->is_active ? 'border-red-300 text-red-700' : 'border-emerald-300 text-emerald-700' }}">{{ $administrator->is_active ? 'Deactivate user' : 'Activate user' }}</button></form>
                </div>
            </div>
        @endforeach
    </div>
</x-platform-layout>
