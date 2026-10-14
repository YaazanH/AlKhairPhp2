<x-platform-layout :title="__('platform.ui.backups.title')">
    <div class="platform-management platform-operations">
        <header class="platform-management__header">
            <p class="text-sm font-semibold text-emerald-700">{{ __('platform-workspace.groups.operations') }}</p>
            <h1 class="mt-2 font-bold">{{ __('platform.ui.backups.title') }}</h1>
            <p class="mt-3 max-w-3xl text-zinc-500">{{ __('platform-operations.backup_intro') }}</p>
        </header>
        <x-platform.operations-tabs current="backups" />
        <section aria-label="{{ __('platform-operations.backup_summary') }}">
            <div class="platform-operation-stats">
                @foreach(['verified', 'failed', 'creating'] as $key)
                    <article><span>{{ __('platform-operations.'.$key) }}</span><strong>{{ number_format($backupStats[$key]) }}</strong></article>
                @endforeach
            </div>
            <p class="mt-3 text-sm text-zinc-500">{{ __('platform-operations.backup_summary') }}</p>
        </section>
        @if(auth('platform')->user()->hasPlatformPermission('manage.backups'))
            <details class="rounded-3xl border bg-white p-6" @if($errors->has('tenant_id')) open @endif>
                <summary class="cursor-pointer font-bold">{{ __('platform.ui.backups.create_title') }}</summary>
                <p class="mt-3 text-sm text-zinc-500">{{ __('platform-operations.create_help') }}</p>
                <p class="mt-2 text-sm text-zinc-500">{{ __('platform-operations.operational_required') }}</p>
                <form method="POST" action="{{ route('platform.backups.create') }}" class="mt-5 flex flex-wrap items-end gap-4" data-operation-form>
                    @csrf
                    <label class="grid flex-1 gap-2 text-sm font-medium">{{ __('platform.ui.common.tenant') }}
                        <select name="tenant_id" required class="w-full rounded-xl border px-3 py-3">
                            <option value="">{{ __('platform.ui.backups.select_tenant') }}</option>
                            @foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected((string) old('tenant_id') === (string) $tenant->id) @disabled(!$tenant->isOperational() || blank($tenant->database_name))>{{ $tenant->name }} ({{ $tenant->slug }})</option>@endforeach
                        </select>
                    </label>
                    <button class="rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ __('platform.ui.backups.create') }}</button>
                    <a href="{{ route('platform.settings.index') }}#backups" class="text-sm text-emerald-700">{{ __('platform-operations.policy') }}</a>
                    <p class="w-full text-sm text-zinc-500" data-operation-busy hidden role="status">{{ __('platform-operations.busy') }}</p>
                </form>
            </details>
        @endif
        <section class="platform-request-queue rounded-3xl border bg-white">
            <div class="platform-request-heading"><h2 class="text-lg font-bold">{{ __('platform.ui.backups.history') }}</h2><span>{{ number_format($backups->total()) }}</span></div>
            <form method="GET" action="{{ route('platform.backups.index') }}" class="platform-request-filters">
                <label>{{ __('platform-operations.search') }}<input name="search" value="{{ request('search') }}" maxlength="255" class="rounded-xl border p-3"></label>
                <label>{{ __('platform.ui.common.tenant') }}<select name="tenant" class="rounded-xl border p-3"><option value="">{{ __('platform-operations.all_tenants') }}</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected((string) request('tenant') === (string) $tenant->id)>{{ $tenant->name }}</option>@endforeach</select></label>
                <label>{{ __('platform.ui.common.status') }}<select name="state" class="rounded-xl border p-3"><option value="all">{{ __('platform-operations.all_states') }}</option><option value="verified" @selected(request('state') === 'verified')>{{ __('platform-operations.verified_filter') }}</option>@foreach(['creating','completed','failed'] as $state)<option value="{{ $state }}" @selected(request('state') === $state)>{{ __($state === 'completed' ? 'platform-operations.completed' : 'backups.statuses.'.$state) }}</option>@endforeach</select></label>
                <label>{{ __('platform.ui.common.type') }}<select name="trigger" class="rounded-xl border p-3"><option value="all">{{ __('platform-operations.all_triggers') }}</option>@foreach(['manual','scheduled','pre_restore'] as $trigger)<option value="{{ $trigger }}" @selected(request('trigger') === $trigger)>{{ __('backups.triggers.'.$trigger) }}</option>@endforeach</select></label>
                <button class="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white">{{ __('platform-operations.filter') }}</button>
                <a href="{{ route('platform.backups.index') }}">{{ __('platform-operations.reset') }}</a>
            </form>
            <div class="overflow-x-auto"><table class="platform-request-table w-full">
                <thead><tr><th>{{ __('platform.ui.common.tenant') }}</th><th>{{ __('platform.ui.common.created') }}</th><th>{{ __('platform.ui.common.type') }}</th><th>{{ __('platform.ui.common.size') }}</th><th>{{ __('platform.ui.common.status') }}</th><th>{{ __('platform.ui.common.actions') }}</th></tr></thead>
                <tbody>@forelse($backups as $backup)
                    <tr data-backup-row="{{ $backup->id }}">
                        <td><strong>{{ $backup->tenant?->name ?? '—' }}</strong><p dir="ltr">{{ $backup->tenant?->slug }}</p><small class="platform-operation-filename">{{ $backup->filename }}</small></td>
                        <td><time dir="ltr">{{ $backup->created_at->format('Y-m-d H:i') }}</time></td>
                        <td>{{ __('backups.triggers.'.$backup->trigger) }}</td>
                        <td dir="ltr">{{ $backup->size_bytes !== null ? number_format($backup->size_bytes / 1048576, 1).' MB' : '—' }}</td>
                        <td><span class="platform-request-status">{{ __($backup->status === 'completed' ? 'platform-operations.completed' : 'backups.statuses.'.$backup->status) }}</span><p>{{ $backup->isUsable() ? __('platform-operations.verified_at', ['date' => $backup->verified_at->format('Y-m-d H:i')]) : __('platform-operations.not_verified') }}</p>@if($backup->status === 'failed')<p>{{ __('platform-operations.failure_help') }}</p>@endif</td>
                        <td>
                            @if($backup->isUsable())<a class="font-semibold" href="{{ route('platform.backups.download', $backup) }}">{{ __('platform.ui.common.download') }}</a>@endif
                            @if($backup->restore_count > 0)<p>{{ __('platform-operations.restored', ['count' => $backup->restore_count]) }}</p>@endif
                            @if($backup->restored_at)<p>{{ __('platform-operations.last_restored', ['date' => $backup->restored_at->format('Y-m-d H:i')]) }}</p>@endif
                            @if($backup->tenant && $backup->isUsable() && auth('platform')->user()->hasPlatformPermission('restore.backups') && $backup->tenant->isOperational() && filled($backup->tenant->database_name))
                                <details class="platform-backup-restore mt-4" @if((string) old('restore_backup_id') === (string) $backup->id && $errors->has('confirmation')) open @endif>
                                    <summary class="cursor-pointer font-semibold">{{ __('platform-operations.restore_title') }}</summary>
                                    <form method="POST" action="{{ route('platform.backups.restore', $backup) }}" class="mt-3 space-y-3 rounded-xl border p-4" data-operation-form>
                                        @csrf
                                        <input type="hidden" name="restore_backup_id" value="{{ $backup->id }}">
                                        <strong class="block">{{ __('platform-operations.restore_scope', ['tenant' => $backup->tenant->name, 'date' => $backup->created_at->format('Y-m-d H:i')]) }}</strong>
                                        <p>{{ __('platform-operations.restore_help') }}</p>
                                        <label class="grid gap-2 text-sm">{{ __('platform-operations.confirmation', ['slug' => $backup->tenant->slug]) }}<input name="confirmation" required pattern="{{ $backup->tenant->slug }}" autocomplete="off" class="w-full rounded-xl border p-3" dir="ltr" value="{{ (string) old('restore_backup_id') === (string) $backup->id ? old('confirmation') : '' }}"></label>
                                        <button class="rounded-xl border px-4 py-3 font-semibold">{{ __('platform.ui.backups.restore') }}</button>
                                        <p data-operation-busy hidden role="status">{{ __('platform-operations.busy') }}</p>
                                    </form>
                                </details>
                            @elseif($backup->isUsable() && auth('platform')->user()->hasPlatformPermission('restore.backups'))
                                <p>{{ __('platform-operations.operational_required') }}</p>
                            @endif
                        </td>
                    </tr>
                @empty<tr><td colspan="6" class="text-center">{{ __('platform.ui.backups.empty') }}</td></tr>@endforelse</tbody>
            </table></div>
            <div class="p-5">{{ $backups->links() }}</div>
        </section>
    </div>
    <script>
        document.querySelectorAll('[data-operation-form]').forEach(form => {
            form.addEventListener('submit', event => {
                if (form.dataset.busy === 'true') { event.preventDefault(); return; }
                form.dataset.busy = 'true';
                form.setAttribute('aria-busy', 'true');
                form.querySelectorAll('button').forEach(button => button.disabled = true);
                form.querySelector('[data-operation-busy]').hidden = false;
            });
        });
        window.addEventListener('pageshow', () => document.querySelectorAll('[data-operation-form]').forEach(form => {
            delete form.dataset.busy;
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button').forEach(button => button.disabled = false);
            form.querySelector('[data-operation-busy]').hidden = true;
        }));
    </script>
</x-platform-layout>
