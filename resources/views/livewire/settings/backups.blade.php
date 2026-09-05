<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\SystemBackup;
use App\Services\SystemBackupService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithFileUploads;
    use WithPagination;

    public bool $showSettingsModal = false;
    public bool $showAppKeyModal = false;
    public bool $appKeyRevealed = false;
    public bool $showRestoreModal = false;
    public bool $showFileRestoreModal = false;
    public string $frequency = 'daily';
    public string $backupTime = '02:00';
    public string $weekday = '5';
    public string $retentionCount = '14';
    public string $healthWarningHours = '48';
    public string $appKeyPassword = '';
    public ?int $restoreBackupId = null;
    public string $restorePassword = '';
    public string $restoreConfirmation = '';
    public $restoreFile = null;

    public function mount(): void
    {
        $this->authorizePermission('backups.manage');
        $this->loadSettings();
    }

    public function with(): array
    {
        $service = app(SystemBackupService::class);

        return [
            'backups' => SystemBackup::query()->with('creator')->latest()->paginate(10),
            'health' => $service->health(),
            'nextScheduledAt' => $service->nextScheduledAt(),
            'backupTimezone' => $service->timezone(),
            'selectedRestoreBackup' => $this->restoreBackupId
                ? SystemBackup::query()->find($this->restoreBackupId)
                : null,
        ];
    }

    public function createBackup(): void
    {
        $this->authorizePermission('backups.manage');
        $this->resetErrorBag('backup');

        try {
            app(SystemBackupService::class)->create(auth()->user());
            $this->resetPage();
            session()->flash('status', __('backups.messages.created'));
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('backup', __('backups.errors.operation_failed'));
        }
    }

    public function openSettings(): void
    {
        $this->authorizePermission('backups.manage');
        $this->loadSettings();
        $this->resetValidation();
        $this->showAppKeyModal = false;
        $this->appKeyRevealed = false;
        $this->appKeyPassword = '';
        $this->showSettingsModal = true;
    }

    public function closeSettings(): void
    {
        $this->showSettingsModal = false;
        $this->showAppKeyModal = false;
        $this->appKeyRevealed = false;
        $this->appKeyPassword = '';
        $this->resetValidation();
    }

    public function openAppKeyInfo(): void
    {
        $this->authorizePermission('backups.manage');
        $this->showSettingsModal = false;
        $this->appKeyPassword = '';
        $this->appKeyRevealed = false;
        $this->resetErrorBag('appKeyPassword');
        $this->showAppKeyModal = true;
    }

    public function closeAppKeyInfo(): void
    {
        $this->showSettingsModal = false;
        $this->showAppKeyModal = false;
        $this->appKeyRevealed = false;
        $this->appKeyPassword = '';
        $this->resetErrorBag('appKeyPassword');
    }

    public function revealAppKey(): void
    {
        $this->authorizePermission('backups.manage');
        $this->resetErrorBag('appKeyPassword');
        $this->validate([
            'appKeyPassword' => ['required', 'string'],
        ]);

        if (! Hash::check($this->appKeyPassword, auth()->user()->password)) {
            $this->addError('appKeyPassword', __('backups.errors.password'));

            return;
        }

        $this->appKeyPassword = '';
        $this->appKeyRevealed = true;
    }

    public function saveSettings(): void
    {
        $this->authorizePermission('backups.manage');

        $validated = $this->validate([
            'frequency' => ['required', Rule::in(['disabled', 'daily', 'weekly'])],
            'backupTime' => ['required', 'date_format:H:i'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'retentionCount' => ['required', 'integer', 'between:1,100'],
            'healthWarningHours' => ['required', 'integer', 'between:1,720'],
        ]);

        app(SystemBackupService::class)->saveSettings([
            'frequency' => $validated['frequency'],
            'time' => $validated['backupTime'],
            'weekday' => (int) $validated['weekday'],
            'retention_count' => (int) $validated['retentionCount'],
            'health_warning_hours' => (int) $validated['healthWarningHours'],
        ]);

        $this->showSettingsModal = false;
        session()->flash('status', __('backups.messages.settings_saved'));
    }

    public function openRestore(int $backupId): void
    {
        $this->authorizePermission('backups.manage');
        $backup = SystemBackup::query()->findOrFail($backupId);
        abort_unless($backup->isUsable(), 404);

        $this->restoreBackupId = $backup->id;
        $this->restorePassword = '';
        $this->restoreConfirmation = '';
        $this->resetValidation();
        $this->showRestoreModal = true;
    }

    public function closeRestore(): void
    {
        $this->showRestoreModal = false;
        $this->restoreBackupId = null;
        $this->restorePassword = '';
        $this->restoreConfirmation = '';
        $this->resetValidation();
    }

    public function openFileRestore(): void
    {
        $this->authorizePermission('backups.manage');
        $this->restoreBackupId = null;
        $this->restoreFile = null;
        $this->restorePassword = '';
        $this->restoreConfirmation = '';
        $this->resetValidation();
        $this->showFileRestoreModal = true;
    }

    public function closeFileRestore(): void
    {
        $this->showFileRestoreModal = false;
        $this->restoreFile = null;
        $this->restorePassword = '';
        $this->restoreConfirmation = '';
        $this->resetValidation();
    }

    public function restoreBackupFromFile(): void
    {
        $this->authorizePermission('backups.manage');
        $phrase = __('backups.restore.confirmation_phrase');
        $maximumKilobytes = max(1, (int) config('backups.max_upload_mb', 50)) * 1024;

        $this->validate([
            'restoreFile' => ['required', 'file', 'max:'.$maximumKilobytes],
            'restorePassword' => ['required', 'string'],
            'restoreConfirmation' => ['required', 'string', Rule::in([$phrase])],
        ], [
            'restoreFile.required' => __('backups.errors.file_required'),
            'restoreFile.file' => __('backups.errors.invalid_file'),
            'restoreFile.max' => __('backups.errors.file_too_large', ['size' => config('backups.max_upload_mb', 50)]),
            'restoreConfirmation.in' => __('backups.errors.confirmation'),
        ]);

        if (! $this->restoreFile instanceof TemporaryUploadedFile
            || ! str_ends_with(strtolower($this->restoreFile->getClientOriginalName()), '.alkhair-backup')) {
            $this->addError('restoreFile', __('backups.errors.invalid_file'));

            return;
        }

        if (! Hash::check($this->restorePassword, auth()->user()->password)) {
            $this->addError('restorePassword', __('backups.errors.password'));

            return;
        }

        try {
            $service = app(SystemBackupService::class);
            $backup = $service->import(
                $this->restoreFile->getRealPath(),
                $this->restoreFile->getClientOriginalName(),
                auth()->user(),
            );
            $service->restore($backup, auth()->user());
            $this->closeFileRestore();
            $this->resetPage();
            session()->flash('status', __('backups.messages.restored_from_file'));
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('restoreFileOperation', __('backups.errors.restore_file_failed'));
        }
    }

    public function restoreBackup(): void
    {
        $this->authorizePermission('backups.manage');
        $phrase = __('backups.restore.confirmation_phrase');

        $this->validate([
            'restorePassword' => ['required', 'string'],
            'restoreConfirmation' => ['required', 'string', Rule::in([$phrase])],
        ], [
            'restoreConfirmation.in' => __('backups.errors.confirmation'),
        ]);

        if (! Hash::check($this->restorePassword, auth()->user()->password)) {
            $this->addError('restorePassword', __('backups.errors.password'));

            return;
        }

        $backup = SystemBackup::query()->findOrFail($this->restoreBackupId);

        try {
            app(SystemBackupService::class)->restore($backup, auth()->user());
            $this->closeRestore();
            session()->flash('status', __('backups.messages.restored'));
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('restore', __('backups.errors.operation_failed'));
        }
    }

    public function deleteBackup(int $backupId): void
    {
        $this->authorizePermission('backups.manage');
        app(SystemBackupService::class)->delete(SystemBackup::query()->findOrFail($backupId));
        $this->resetPage();
        session()->flash('status', __('backups.messages.deleted'));
    }

    public function formatBytes(int|float|null $bytes): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return number_format($bytes, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    private function loadSettings(): void
    {
        $settings = app(SystemBackupService::class)->settings();
        $this->frequency = $settings['frequency'];
        $this->backupTime = $settings['time'];
        $this->weekday = (string) $settings['weekday'];
        $this->retentionCount = (string) $settings['retention_count'];
        $this->healthWarningHours = (string) $settings['health_warning_hours'];
    }
}; ?>

<div class="page-stack settings-admin-page" data-backup-recovery-page>
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('ui.common.settings') }}</h1>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.backups" />

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif
    @error('backup')
        <div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>
    @enderror

    <section class="surface-panel settings-dark-surface p-5 lg:p-6" data-settings-dark-surface="backup-health" data-backup-health-state="{{ $health['state'] }}">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('backups.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('backups.subtitle') }}</p>
            </div>
            <div class="admin-toolbar__actions">
                <button type="button" wire:click="openSettings" class="admin-icon-button" title="{{ __('backups.actions.edit_settings') }}" aria-label="{{ __('backups.actions.edit_settings') }}" data-backup-settings-action>
                    <x-admin-action-icon name="gear" />
                </button>
            </div>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold text-neutral-400">{{ __('backups.stats.latest_verified') }}</div>
                <div class="mt-2 text-sm font-semibold text-white">
                    @if ($health['latest_verified'])
                        <bdi dir="ltr">{{ $health['latest_verified']->verified_at->timezone($backupTimezone)->format('d-m-Y H:i') }}</bdi>
                    @else
                        {{ __('backups.stats.not_available') }}
                    @endif
                </div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold text-neutral-400">{{ __('backups.stats.next_scheduled') }}</div>
                <div class="mt-2 text-sm font-semibold text-white">
                    @if ($nextScheduledAt)
                        <bdi dir="ltr">{{ $nextScheduledAt->format('d-m-Y H:i') }}</bdi>
                    @else
                        {{ __('backups.stats.schedule_disabled') }}
                    @endif
                </div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold text-neutral-400">{{ __('backups.stats.stored_backups') }}</div>
                <div class="mt-2 text-sm font-semibold text-white">{{ number_format($health['backup_count']) }}</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold text-neutral-400">{{ __('backups.stats.storage_used') }}</div>
                <div class="mt-2 text-sm font-semibold text-white"><bdi dir="ltr">{{ $this->formatBytes($health['total_bytes']) }}</bdi></div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold text-neutral-400">{{ __('backups.stats.free_space') }}</div>
                <div class="mt-2 text-sm font-semibold text-white"><bdi dir="ltr">{{ $health['free_bytes'] === null ? __('backups.stats.not_available') : $this->formatBytes($health['free_bytes']) }}</bdi></div>
            </div>
        </div>

    </section>

    <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" data-settings-table data-backup-history-table>
        <div class="admin-grid-meta items-center">
            <div>
                <div class="admin-grid-meta__title">{{ __('backups.history.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('backups.history.summary', ['count' => number_format($backups->total())]) }}</div>
            </div>
            <div class="admin-toolbar__actions" data-backup-history-actions>
                <button type="button" wire:click="createBackup" wire:loading.attr="disabled" wire:target="createBackup" class="admin-icon-button admin-icon-button--accent disabled:cursor-wait disabled:opacity-50" title="{{ __('backups.actions.create') }}" aria-label="{{ __('backups.actions.create') }}" data-backup-create-action>
                    <x-admin-action-icon name="backup-upload" />
                </button>
                <button type="button" wire:click="openFileRestore" wire:confirm="{{ __('backups.confirmations.open_file_restore') }}" class="admin-icon-button admin-icon-button--danger" title="{{ __('backups.actions.restore_from_file') }}" aria-label="{{ __('backups.actions.restore_from_file') }}" data-backup-file-restore-action>
                    <x-admin-action-icon name="cloud-upload" />
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.created_at') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.trigger') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.contents') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.size') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('backups.table.verification') }}</th>
                        <th class="admin-actions-column px-5 py-3 text-center font-medium">{{ __('backups.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($backups as $backup)
                        <tr wire:key="system-backup-{{ $backup->id }}">
                            <td class="px-5 py-3">
                                <div class="font-medium text-white"><bdi dir="ltr">{{ $backup->created_at->timezone($backupTimezone)->format('d-m-Y H:i') }}</bdi></div>
                                @if ($backup->creator)
                                    <div class="mt-1 text-xs text-neutral-500">{{ $backup->creator->name }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-3">{{ __('backups.triggers.'.$backup->trigger) }}</td>
                            <td class="px-5 py-3">
                                <div>{{ $backup->includes_files ? __('backups.table.database_and_files') : __('backups.table.database_only') }}</div>
                                @if ($backup->includes_files)
                                    <div class="mt-1 text-xs text-neutral-500">{{ __('backups.table.files_count', ['count' => number_format((int) data_get($backup->manifest_summary, 'files_count', 0))]) }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-3"><bdi dir="ltr">{{ $this->formatBytes($backup->size_bytes) }}</bdi></td>
                            <td class="px-5 py-3">
                                @if ($backup->verified_at)
                                    <div class="text-xs text-neutral-500" data-backup-verification-details><span>{{ __('backups.table.verified_at') }}</span> <bdi dir="ltr">{{ $backup->verified_at->timezone($backupTimezone)->format('d-m-Y H:i') }}</bdi></div>
                                @elseif ($backup->error_message)
                                    <div class="max-w-xs text-xs text-red-300" title="{{ $backup->error_message }}" data-backup-verification-details>{{ __('backups.table.not_verified') }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="admin-action-cluster admin-action-cluster--end">
                                    @if ($backup->isUsable())
                                        <a href="{{ route('settings.backups.download', $backup) }}" class="admin-icon-button" title="{{ __('backups.actions.download') }}" aria-label="{{ __('backups.actions.download') }}" data-backup-download-action>
                                            <x-admin-action-icon name="download" />
                                        </a>
                                        <button type="button" wire:click="openRestore({{ $backup->id }})" class="admin-icon-button admin-icon-button--danger" title="{{ __('backups.actions.restore') }}" aria-label="{{ __('backups.actions.restore') }}" data-backup-restore-action>
                                            <x-admin-action-icon name="restore-point" />
                                        </button>
                                    @endif
                                    <x-delete-action-button wire:click="deleteBackup({{ $backup->id }})" wire:confirm="{{ __('backups.confirmations.delete') }}" :label="__('backups.actions.delete')" data-backup-delete-action />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="admin-empty-state">{{ __('backups.history.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($backups->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">{{ $backups->links() }}</div>
        @endif
    </section>

    <x-admin.modal :show="$showSettingsModal" :title="__('backups.settings.title')" close-method="closeSettings" :dismissible="false" max-width="2xl">
        <x-slot:header-actions>
            <button type="button" wire:click="openAppKeyInfo" class="admin-modal__close" title="{{ __('backups.actions.show_app_key') }}" aria-label="{{ __('backups.actions.show_app_key') }}" data-backup-app-key-action>
                <x-admin-action-icon name="info" class="size-5" />
            </button>
            <button type="submit" form="backup-settings-form" class="admin-modal__close" title="{{ __('backups.actions.save_settings') }}" aria-label="{{ __('backups.actions.save_settings') }}" data-backup-settings-save-action>
                <x-admin-action-icon name="save" class="size-5" />
            </button>
        </x-slot:header-actions>
        <form id="backup-settings-form" wire:submit="saveSettings" class="space-y-4">
            <div @class([
                'grid gap-4',
                'md:grid-cols-3' => $frequency === 'weekly',
                'md:grid-cols-2' => $frequency !== 'weekly',
            ]) data-backup-schedule-row>
                <label class="block text-sm">{{ __('backups.settings.frequency') }}
                    <select wire:model.live="frequency" class="mt-1 w-full rounded-xl px-4 py-3" data-clearable="false" data-search-selection-required="true" data-backup-schedule-select>
                        @foreach (['disabled', 'daily', 'weekly'] as $option)
                            <option value="{{ $option }}">{{ __('backups.settings.frequencies.'.$option) }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($frequency === 'weekly')
                    <label class="block text-sm">{{ __('backups.settings.weekday') }}
                        <select wire:model="weekday" class="mt-1 w-full rounded-xl px-4 py-3" data-clearable="false" data-search-selection-required="true" data-backup-weekday-select>
                            @foreach (range(0, 6) as $day)
                                <option value="{{ $day }}">{{ __('backups.settings.weekdays.'.$day) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="block text-sm">{{ __('backups.settings.time') }}
                    <input wire:model="backupTime" type="time" dir="ltr" class="mt-1 w-full rounded-xl px-4 py-3" data-backup-time-input>
                </label>
            </div>
            <div class="grid gap-4 md:grid-cols-2" data-backup-retention-row>
                <label class="block text-sm">{{ __('backups.settings.retention_count') }}
                    <input wire:model="retentionCount" type="number" min="1" max="100" class="mt-1 w-full rounded-xl px-4 py-3">
                </label>
                <label class="block text-sm">{{ __('backups.settings.health_warning_hours') }}
                    <div class="saber-rule-input mt-1" data-backup-health-warning-input>
                        <input wire:model="healthWarningHours" type="number" min="1" max="720" class="saber-rule-input__control saber-rule-input__control--word w-full rounded-xl px-4 py-3">
                        <span class="saber-rule-input__suffix" aria-hidden="true" data-backup-health-warning-unit>{{ __('backups.settings.health_warning_unit') }}</span>
                    </div>
                </label>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showAppKeyModal" :title="__('backups.app_key.title')" close-method="closeAppKeyInfo" max-width="xl" compact>
        @if ($appKeyRevealed)
            <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3" data-backup-app-key-value>
                <code class="block select-all break-all text-left text-sm text-white" dir="ltr">{{ config('app.key') }}</code>
            </div>
        @else
            <form wire:submit="revealAppKey" data-backup-app-key-form>
                <label class="block min-w-0 text-sm">{{ __('backups.app_key.password') }}
                    <input wire:model="appKeyPassword" type="password" autocomplete="current-password" class="mt-1 w-full rounded-xl px-4 py-3">
                    @error('appKeyPassword')<span class="mt-1 block text-sm text-red-400">{{ $message }}</span>@enderror
                </label>
            </form>
        @endif
    </x-admin.modal>

    <x-admin.modal :show="$showRestoreModal" :title="__('backups.restore.title')" close-method="closeRestore" max-width="xl">
        <form wire:submit="restoreBackup" class="space-y-4">
            <div class="rounded-xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ __('backups.restore.warning') }}</div>
            @if ($selectedRestoreBackup)
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm"><bdi dir="ltr">{{ $selectedRestoreBackup->filename }}</bdi></div>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm">{{ __('backups.restore.password') }}
                        <input wire:model="restorePassword" type="password" autocomplete="current-password" class="mt-1 w-full rounded-xl px-4 py-3">
                    </label>
                    @error('restorePassword')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="block text-sm">{{ __('backups.restore.confirmation', ['phrase' => __('backups.restore.confirmation_phrase')]) }}
                        <input wire:model="restoreConfirmation" type="text" autocomplete="off" class="mt-1 w-full rounded-xl px-4 py-3">
                    </label>
                    @error('restoreConfirmation')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                </div>
            </div>
            @error('restore')<div class="text-sm text-red-400">{{ $message }}</div>@enderror
            <div class="flex justify-start">
                <button type="submit" wire:loading.attr="disabled" wire:target="restoreBackup" class="admin-icon-button admin-icon-button--accent admin-modal-action-button disabled:cursor-wait disabled:opacity-50" title="{{ __('backups.actions.confirm_restore') }}" aria-label="{{ __('backups.actions.confirm_restore') }}" data-backup-restore-confirm-action>
                    <x-admin-action-icon name="reactivate" class="admin-modal-action__icon" />
                </button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showFileRestoreModal" :title="__('backups.restore_file.title')" close-method="closeFileRestore" max-width="xl">
        <form wire:submit="restoreBackupFromFile" data-admin-confirm-message="{{ __('backups.confirmations.restore_from_file') }}" x-data="{ uploadingBackup: false }" x-on:livewire-upload-start="uploadingBackup = true" x-on:livewire-upload-finish="uploadingBackup = false" x-on:livewire-upload-error="uploadingBackup = false" x-on:livewire-upload-cancel="uploadingBackup = false" x-on:submit="if (uploadingBackup) $event.preventDefault()" class="space-y-4">
            <label class="block text-sm">{{ __('backups.restore_file.file') }}
                <input wire:model="restoreFile" type="file" accept=".alkhair-backup" class="mt-1 w-full rounded-xl px-4 py-3" data-backup-file-input>
                <span wire:loading wire:target="restoreFile" class="mt-2 inline-flex items-center gap-2 text-sm text-amber-300"><span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>{{ __('backups.restore_file.uploading') }}</span>
                @if ($restoreFile)
                    <span wire:loading.remove wire:target="restoreFile" class="mt-2 block text-sm text-emerald-300">{{ __('backups.restore_file.ready') }}</span>
                @endif
            </label>
            @error('restoreFile')<div class="text-sm text-red-400">{{ $message }}</div>@enderror
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm">{{ __('backups.restore.password') }}
                        <input wire:model="restorePassword" type="password" autocomplete="current-password" class="mt-1 w-full rounded-xl px-4 py-3">
                    </label>
                    @error('restorePassword')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="block text-sm">{{ __('backups.restore.confirmation', ['phrase' => __('backups.restore.confirmation_phrase')]) }}
                        <input wire:model="restoreConfirmation" type="text" autocomplete="off" class="mt-1 w-full rounded-xl px-4 py-3">
                    </label>
                    @error('restoreConfirmation')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                </div>
            </div>
            @error('restoreFileOperation')<div class="text-sm text-red-400">{{ $message }}</div>@enderror
            <div class="flex justify-start">
                <button type="submit" x-bind:disabled="uploadingBackup" wire:loading.attr="disabled" wire:target="restoreBackupFromFile,restoreFile" class="admin-icon-button admin-icon-button--danger admin-modal-action-button disabled:cursor-wait disabled:opacity-50" title="{{ __('backups.actions.confirm_restore_from_file') }}" aria-label="{{ __('backups.actions.confirm_restore_from_file') }}" data-backup-file-restore-confirm-action>
                    <x-admin-action-icon name="database-restore" class="admin-modal-action__icon" />
                </button>
            </div>
        </form>
    </x-admin.modal>
</div>
