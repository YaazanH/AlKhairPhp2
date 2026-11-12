@php
    $format = function (int|float|null $bytes): string {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) { $bytes /= 1024; $unit++; }
        return number_format($bytes, $unit === 0 ? 0 : 1).' '.$units[$unit];
    };
    $backupTimezone = app(\App\Services\SystemBackupService::class)->timezone();
    $restoreSelection = $canManageBackups && $backups ? $backups->firstWhere('id', (int) old('restore_backup_id')) : null;
@endphp

<x-layouts.app :title="__('backups.tenant.title')">
    <div class="page-stack settings-admin-page" data-tenant-backup-storage-page
        x-data="{ restoreUrl: @js($restoreSelection ? route('settings.tenant-backups.restore', $restoreSelection) : null), restoreId: @js($restoreSelection?->id) }"
        x-init="if (restoreUrl && @js($errors->has('password') || $errors->has('confirmation'))) $nextTick(() => $refs.restoreDialog.showModal())">
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
            <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" data-settings-table data-backup-history-table data-tenant-backup-history>
                <div class="admin-grid-meta items-center" data-mobile-title-action-row data-backup-history-title-action-row>
                    <div>
                        <div class="admin-grid-meta__title">{{ __('backups.tenant.history_title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('backups.tenant.history_help') }}</div>
                    </div>
                    <form method="POST" action="{{ route('settings.tenant-backups.create') }}" class="admin-toolbar__actions" data-backup-history-actions>
                        @csrf
                        <button type="submit" class="admin-icon-button admin-icon-button--accent disabled:cursor-wait disabled:opacity-50" title="{{ __('backups.actions.create') }}" aria-label="{{ __('backups.actions.create') }}" data-tenant-backup-create data-backup-create-action>
                            <x-admin-action-icon name="backup-upload" />
                        </button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                            <tr>
                                <th data-table-number-column scope="col" class="w-12 whitespace-nowrap px-3 py-4 text-center">#</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.created_at') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.trigger') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.contents') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.size') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.verification') }}</th>
                                <th class="admin-actions-column px-5 py-3 text-center font-medium">{{ __('backups.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @forelse($backups as $backup)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-4 text-center text-neutral-300" data-row-number>{{ $backups->firstItem() + $loop->index }}</td>
                                    <td class="px-5 py-3"><div class="font-medium text-white"><bdi dir="ltr">{{ \App\Support\DateDisplay::html($backup->created_at->timezone($backupTimezone)->format('d-m-Y H:i')) }}</bdi></div></td>
                                    <td class="px-5 py-3">{{ __('backups.triggers.'.$backup->trigger) }}</td>
                                    <td class="px-5 py-3"><div>{{ __('backups.scopes.full') }}</div>@if(data_get($backup->manifest_summary, 'files_count') !== null)<div class="mt-1 text-xs text-neutral-500">{{ __('backups.table.files_count', ['count' => number_format((int) data_get($backup->manifest_summary, 'files_count'))]) }}</div>@endif</td>
                                    <td class="px-5 py-3"><bdi dir="ltr">{{ $backup->size_bytes ? $format($backup->size_bytes) : '—' }}</bdi></td>
                                    <td class="px-5 py-3">
                                        @if($backup->verified_at)
                                            <div class="text-xs text-neutral-500" data-backup-verification-details><span>{{ __('backups.table.verified_at') }}</span> <bdi dir="ltr">{{ \App\Support\DateDisplay::html($backup->verified_at->timezone($backupTimezone)->format('d-m-Y H:i')) }}</bdi></div>
                                        @elseif($backup->error_message)
                                            <div class="max-w-xs text-xs text-red-300" title="{{ $backup->error_message }}" data-backup-verification-details>{{ __('backups.table.not_verified') }}</div>
                                        @else
                                            <span class="text-xs text-neutral-500">{{ __('backups.statuses.'.$backup->status) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($backup->isUsable())
                                            <div class="admin-action-cluster admin-action-cluster--end">
                                                <a href="{{ route('settings.tenant-backups.download', $backup) }}" class="admin-icon-button" title="{{ __('backups.actions.download') }}" aria-label="{{ __('backups.actions.download') }}" data-backup-download-action>
                                                    <x-admin-action-icon name="download" />
                                                </a>
                                                <button type="button" class="admin-icon-button admin-icon-button--danger" title="{{ __('backups.actions.restore') }}" aria-label="{{ __('backups.actions.restore') }}"
                                                    data-backup-restore-action data-backup-id="{{ $backup->id }}" data-restore-url="{{ route('settings.tenant-backups.restore', $backup) }}"
                                                    x-on:click="restoreUrl = $el.dataset.restoreUrl; restoreId = $el.dataset.backupId; $refs.restoreDialog.showModal()">
                                                    <x-admin-action-icon name="restore-point" />
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="admin-empty-state">{{ __('backups.tenant.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($backups->hasPages())
                    <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">{{ $backups->links() }}</div>
                @endif
            </section>
            <dialog x-ref="restoreDialog" class="admin-modal__dialog admin-modal__dialog--2xl tenant-backup-restore-dialog" aria-labelledby="tenant-backup-restore-title" data-tenant-backup-restore-modal>
                <div class="admin-modal__header">
                    <h2 id="tenant-backup-restore-title" class="admin-modal__title">{{ __('backups.actions.restore') }}</h2>
                    <button type="button" class="admin-modal__close" x-on:click="$refs.restoreDialog.close()" aria-label="{{ __('crud.common.actions.close') }}"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="admin-modal__body">
                    <form method="POST" x-bind:action="restoreUrl" class="space-y-4">
                        @csrf
                        <input type="hidden" name="restore_backup_id" x-bind:value="restoreId">
                        <div class="rounded-xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm text-red-800 dark:text-red-100">{{ __('backups.tenant.restore_warning', ['tenant' => $tenant->slug]) }}</div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm">{{ __('backups.tenant.password_placeholder') }}<input name="password" required type="password" autocomplete="current-password" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                                @error('password')<div class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="block text-sm">{{ __('backups.tenant.confirmation_placeholder', ['tenant' => $tenant->slug]) }}<input name="confirmation" required autocomplete="off" class="mt-1 w-full rounded-xl px-4 py-3"></label>
                                @error('confirmation')<div class="mt-1 text-sm text-red-700 dark:text-red-300">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="flex justify-start"><button type="submit" class="admin-icon-button admin-icon-button--accent admin-modal-action-button" title="{{ __('backups.tenant.restore_action') }}" aria-label="{{ __('backups.tenant.restore_action') }}" data-backup-restore-confirm-action><x-admin-action-icon name="reactivate" class="admin-modal-action__icon" /></button></div>
                    </form>
                </div>
            </dialog>
        @endif
    </div>
</x-layouts.app>
