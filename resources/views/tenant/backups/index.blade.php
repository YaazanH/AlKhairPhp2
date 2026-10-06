<x-layouts.app :title="__('backups.tenant.title')">
    <div class="page-stack settings-admin-page">
        <section class="page-hero p-6 lg:p-8">
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('backups.tenant.title') }}</h1>
            <p class="mt-3 max-w-2xl text-sm text-white/75">{{ __('backups.tenant.subtitle', ['tenant' => $tenant->name]) }}</p>
        </section>

        <x-settings.admin-nav section="dashboard" current="settings.backups" />

        @if(session('status'))
            <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        @error('backup')
            <div class="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div>
        @enderror

        <section class="surface-panel settings-dark-surface p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('backups.tenant.history_title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('backups.tenant.history_help') }}</p>
                </div>
                <form method="POST" action="{{ route('settings.tenant-backups.create') }}">
                    @csrf
                    <button type="submit" class="pill-link pill-link--accent" data-tenant-backup-create>{{ __('backups.tenant.create_now') }}</button>
                </form>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                    <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-5 py-3 text-start">{{ __('backups.table.created_at') }}</th>
                            <th class="px-5 py-3 text-start">{{ __('backups.table.trigger') }}</th>
                            <th class="px-5 py-3 text-start">{{ __('backups.table.size') }}</th>
                            <th class="px-5 py-3 text-start">{{ __('backups.table.verification') }}</th>
                            <th class="px-5 py-3 text-start">{{ __('backups.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @forelse($backups as $backup)
                            <tr>
                                <td class="px-5 py-3">{{ $backup->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-3">{{ __('backups.triggers.'.$backup->trigger) }}</td>
                                <td class="px-5 py-3">{{ $backup->size_bytes ? number_format($backup->size_bytes / 1048576, 1).' MB' : '—' }}</td>
                                <td class="px-5 py-3">{{ __('backups.statuses.'.$backup->status) }}</td>
                                <td class="px-5 py-3">
                                    @if($backup->isUsable())
                                        <a href="{{ route('settings.tenant-backups.download', $backup) }}" class="text-emerald-700 hover:underline">{{ __('backups.actions.download') }}</a>
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-red-700">{{ __('backups.actions.restore') }}</summary>
                                            <form method="POST" action="{{ route('settings.tenant-backups.restore', $backup) }}" class="mt-2 max-w-md space-y-2 rounded border border-red-200 p-3 text-sm">
                                                @csrf
                                                <p class="text-red-700">{{ __('backups.tenant.restore_warning', ['tenant' => $tenant->slug]) }}</p>
                                                <input name="confirmation" required class="w-full rounded border px-2 py-1" placeholder="{{ __('backups.tenant.confirmation_placeholder', ['tenant' => $tenant->slug]) }}">
                                                <input name="password" required type="password" class="w-full rounded border px-2 py-1" placeholder="{{ __('backups.tenant.password_placeholder') }}">
                                                @error('password')<div class="text-xs text-red-700">{{ $message }}</div>@enderror
                                                <button class="rounded bg-red-700 px-3 py-1 text-white">{{ __('backups.tenant.restore_action') }}</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-neutral-500">{{ __('backups.tenant.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($backups->hasPages())
                <div class="mt-4">{{ $backups->links() }}</div>
            @endif
        </section>
    </div>
</x-layouts.app>
