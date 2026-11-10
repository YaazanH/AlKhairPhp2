@php($format = fn (int $bytes) => number_format($bytes / 1048576, 1).' MB')

<x-layouts.app :title="__('backups.tenant.title')">
    <div class="page-stack settings-admin-page" data-tenant-backup-storage-page>
        <section class="page-hero p-6 lg:p-8">
            <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('backups.tenant.title') }}</h1>
            <p class="mt-3 max-w-2xl text-sm text-white/75">{{ __('backups.tenant.subtitle', ['tenant' => $tenant->name]) }}</p>
        </section>

        <x-settings.admin-nav section="dashboard" current="settings.backups" />

        @if(session('status'))
            <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        @error('backup')
            <div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>
        @enderror

        @if($canViewStorage && $usage)
            <section class="surface-panel settings-dark-surface p-5 lg:p-6" data-tenant-storage-summary>
                <div class="admin-toolbar">
                    <div>
                        <div class="admin-toolbar__title">{{ __('storage.total', ['size' => $format($usage['total'])]) }}</div>
                        <p class="admin-toolbar__subtitle">{{ __('storage.help') }}</p>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach(['media', 'documents', 'other_files', 'database', 'backups'] as $key)
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="text-xs font-semibold text-neutral-400">{{ __('storage.categories.'.$key) }}</div>
                            <div class="mt-2 text-lg font-semibold text-white">{{ $format($usage[$key]) }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if($canManageBackups && $backups)
            <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" data-settings-table data-tenant-backup-history>
                <div class="admin-grid-meta items-center" data-mobile-title-action-row>
                    <div>
                        <div class="admin-grid-meta__title">{{ __('backups.tenant.history_title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('backups.tenant.history_help') }}</div>
                    </div>
                    <form method="POST" action="{{ route('settings.tenant-backups.create') }}" class="admin-toolbar__actions">
                        @csrf
                        <button type="submit" class="admin-icon-button admin-icon-button--accent" title="{{ __('backups.tenant.create_now') }}" aria-label="{{ __('backups.tenant.create_now') }}" data-tenant-backup-create>
                            <x-admin-action-icon name="backup-upload" />
                        </button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                            <tr>
                                <th class="px-5 py-3 text-start">{{ __('backups.table.created_at') }}</th>
                                <th class="px-5 py-3 text-start">{{ __('backups.table.trigger') }}</th>
                                <th class="px-5 py-3 text-start">{{ __('backups.table.size') }}</th>
                                <th class="px-5 py-3 text-start">{{ __('backups.table.verification') }}</th>
                                <th class="admin-actions-column px-5 py-3 text-center">{{ __('backups.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @forelse($backups as $backup)
                                <tr>
                                    <td class="px-5 py-3"><bdi dir="ltr">{{ \App\Support\DateDisplay::html($backup->created_at->format('d-m-Y H:i')) }}</bdi></td>
                                    <td class="px-5 py-3">{{ __('backups.triggers.'.$backup->trigger) }}</td>
                                    <td class="px-5 py-3"><bdi dir="ltr">{{ $backup->size_bytes ? $format($backup->size_bytes) : '—' }}</bdi></td>
                                    <td class="px-5 py-3"><span class="status-chip">{{ __('backups.statuses.'.$backup->status) }}</span></td>
                                    <td class="px-5 py-3">
                                        @if($backup->isUsable())
                                            <div class="admin-action-cluster admin-action-cluster--end">
                                                <a href="{{ route('settings.tenant-backups.download', $backup) }}" class="admin-icon-button" title="{{ __('backups.actions.download') }}" aria-label="{{ __('backups.actions.download') }}">
                                                    <x-admin-action-icon name="download" />
                                                </a>
                                                <details class="relative">
                                                    <summary class="admin-icon-button admin-icon-button--danger cursor-pointer list-none" title="{{ __('backups.actions.restore') }}" aria-label="{{ __('backups.actions.restore') }}">
                                                        <x-admin-action-icon name="restore-point" />
                                                    </summary>
                                                    <form method="POST" action="{{ route('settings.tenant-backups.restore', $backup) }}" class="absolute end-0 z-20 mt-2 w-80 space-y-3 rounded-2xl border border-red-300/20 bg-neutral-950 p-4 shadow-2xl">
                                                        @csrf
                                                        <p class="text-sm leading-6 text-red-200">{{ __('backups.tenant.restore_warning', ['tenant' => $tenant->slug]) }}</p>
                                                        <input name="confirmation" required class="w-full rounded-xl" placeholder="{{ __('backups.tenant.confirmation_placeholder', ['tenant' => $tenant->slug]) }}">
                                                        <input name="password" required type="password" autocomplete="current-password" class="w-full rounded-xl" placeholder="{{ __('backups.tenant.password_placeholder') }}">
                                                        @error('password')<div class="text-xs text-red-300">{{ $message }}</div>@enderror
                                                        <button type="submit" class="admin-icon-button admin-icon-button--danger" title="{{ __('backups.tenant.restore_action') }}" aria-label="{{ __('backups.tenant.restore_action') }}">
                                                            <x-admin-action-icon name="database-restore" />
                                                        </button>
                                                    </form>
                                                </details>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-10 text-center text-neutral-500">{{ __('backups.tenant.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($backups->hasPages())
                    <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">{{ $backups->links() }}</div>
                @endif
            </section>
        @endif
    </div>
</x-layouts.app>
