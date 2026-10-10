<?php

namespace Tests\Feature;

use App\Exceptions\BackupDecryptionException;
use App\Models\AppSetting;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupEncryptionService;
use App\Services\SystemBackupService;
use App\Support\ApplicationTimezone;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class SystemBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_centre_is_limited_to_administrators(): void
    {
        $this->assertSame('النسخ الاحتياطي', trans('backups.navigation_title', locale: 'ar'));

        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->assertTrue($admin->can('backups.manage'));
        $this->assertFalse($manager->can('backups.manage'));

        $this->get(route('settings.backups'))->assertRedirect(route('login'));

        $this->actingAs($manager)
            ->get(route('settings.backups'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('settings.backups'))
            ->assertRedirect(route('settings.system-backups'));

        $this->actingAs($admin)
            ->get(route('settings.system-backups'))
            ->assertOk()
            ->assertSee('data-backup-recovery-page', false)
            ->assertSee(__('backups.title'));
    }

    public function test_backup_centre_saves_settings_guards_restoration_and_downloads_encrypted_files(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $appKey = (string) config('app.key');

        $backup = $this->usableBackup($admin);

        $backupView = file_get_contents(resource_path('views/livewire/settings/backups.blade.php'));
        $this->assertSame(0, substr_count($backupView, "__('backups.settings.encryption_notice')"));
        $this->assertStringNotContainsString('data-backup-health-callout', $backupView);
        $this->assertStringContainsString("__('backups.table.trigger')", $backupView);
        $this->assertStringContainsString("__('backups.triggers.'.\$backup->trigger)", $backupView);
        $this->assertStringContainsString('colspan="7"', $backupView);
        $this->assertStringNotContainsString('status-chip backup-status-chip', $backupView);
        $this->assertStringNotContainsString("__('backups.statuses.'.\$backup->status)", $backupView);
        $this->assertStringContainsString('data-backup-verification-details', $backupView);
        $this->assertSame(1, substr_count($backupView, "__('backups.restore.warning')"));
        $this->assertStringContainsString("wire:confirm=\"{{ __('backups.confirmations.open_file_restore') }}\"", $backupView);
        $this->assertStringContainsString("data-admin-confirm-message=\"{{ __('backups.confirmations.restore_from_file') }}\"", $backupView);
        $this->assertStringNotContainsString('<form wire:submit="restoreBackupFromFile" wire:confirm=', $backupView);
        $this->assertSame(3, substr_count($backupView, 'max-width="xl"'));
        $this->assertSame(2, substr_count($backupView, 'class="grid gap-4 sm:grid-cols-2"'));
        $this->assertSame(4, substr_count($backupView, "format('d-m-Y H:i')"));
        $this->assertStringNotContainsString("format('m-d-Y H:i')", $backupView);
        $this->assertStringContainsString('<bdi dir="ltr">{{ \App\Support\DateDisplay::html($backup->verified_at', $backupView);
        $this->assertStringContainsString(':dismissible="false" max-width="2xl"', $backupView);
        $this->assertStringContainsString('<x-slot:header-actions>', $backupView);
        $this->assertStringContainsString('form="backup-settings-form"', $backupView);
        $this->assertStringContainsString('<form id="backup-settings-form" wire:submit="saveSettings"', $backupView);
        $this->assertStringContainsString("'md:grid-cols-3' => \$frequency === 'weekly'", $backupView);
        $this->assertStringContainsString("'md:grid-cols-2' => \$frequency !== 'weekly'", $backupView);
        $this->assertStringContainsString('data-backup-schedule-row', $backupView);
        $this->assertStringContainsString('data-backup-retention-row', $backupView);
        $schedulePosition = strpos($backupView, 'data-backup-schedule-select');
        $weekdayPosition = strpos($backupView, 'data-backup-weekday-select');
        $timePosition = strpos($backupView, 'data-backup-time-input');
        $this->assertIsInt($schedulePosition);
        $this->assertIsInt($weekdayPosition);
        $this->assertIsInt($timePosition);
        $this->assertTrue($schedulePosition < $weekdayPosition && $weekdayPosition < $timePosition);
        $this->assertSame(1, substr_count($backupView, 'data-backup-settings-save-action'));
        $this->assertSame(1, substr_count($backupView, 'data-backup-app-key-action'));
        $this->assertStringContainsString('wire:submit="revealAppKey"', $backupView);
        $this->assertStringNotContainsString('data-backup-app-key-reveal-action', $backupView);
        $this->assertStringNotContainsString('wire:model="includeFiles"', $backupView);
        $this->assertStringContainsString("{{ config('app.key') }}", $backupView);
        $this->assertStringContainsString('type="time" dir="ltr" class="mt-1 w-full rounded-xl px-4 py-3" data-backup-time-input', $backupView);
        $this->assertStringContainsString('data-backup-schedule-select', $backupView);
        $this->assertStringContainsString('class="saber-rule-input mt-1" data-backup-health-warning-input', $backupView);
        $this->assertStringContainsString('class="saber-rule-input__suffix" aria-hidden="true" data-backup-health-warning-unit', $backupView);
        $this->assertSame('التنبيه على آخر نسخة احتياطية بعد مرور', trans('backups.settings.health_warning_hours', locale: 'ar'));
        $this->assertSame('ساعة', trans('backups.settings.health_warning_unit', locale: 'ar'));
        $this->assertSame('عرض مفتاح التطبيق', trans('backups.actions.show_app_key', locale: 'ar'));
        $this->assertSame('مفتاح التطبيق', trans('backups.app_key.title', locale: 'ar'));
        $this->assertSame('اكتب ":phrase" للمتابعة', trans('backups.restore.confirmation', locale: 'ar'));
        $this->assertStringContainsString("\n\nهل تريد المتابعة لاختيار ملف النسخة؟", trans('backups.confirmations.open_file_restore', locale: 'ar'));
        $this->assertStringContainsString("\n\nهل تريد استعادة الملف المحدد الآن؟", trans('backups.confirmations.restore_from_file', locale: 'ar'));

        $iconSource = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $this->assertStringContainsString("'backup-upload' => '0 0 570.36 639.17'", $iconSource);
        $this->assertStringContainsString("'database-restore' => '-12 -12 649.64 643.81'", $iconSource);
        $this->assertStringContainsString('data-supplied-backup-database="asset-10"', $iconSource);
        $this->assertStringContainsString('data-supplied-backup-asterisk="asset-10"', $iconSource);
        $this->assertStringContainsString('data-supplied-backup-restore="asset-1"', $iconSource);
        $this->assertStringContainsString("'restore-point' => '0 0 734.23 688.56'", $iconSource);
        $this->assertStringContainsString('data-restore-point-history-icon="supplied-circular-clock"', $iconSource);
        $this->assertStringContainsString('data-supplied-restore-point="asset-1"', $iconSource);
        $this->assertStringContainsString('M705.2,207.16', $iconSource);
        $this->assertStringContainsString('M352.76,343.28', $iconSource);
        $this->assertStringNotContainsString('backupCreateMaskId', $iconSource);
        $this->assertStringNotContainsString('backupCreateAsteriskPath', $iconSource);
        $this->assertStringNotContainsString('data-backup-create-asterisk-clearance', $iconSource);
        $this->assertStringContainsString("@case('info')", $iconSource);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringNotContainsString("svg[data-icon-name='backup-upload']", $styles);
        $this->assertStringContainsString("svg[data-icon-name='database-restore']", $styles);
        $this->assertStringContainsString('transform: translateX(0.13rem);', $styles);
        $this->assertStringNotContainsString('.backup-status-chip::before', $styles);
        $this->assertStringContainsString("html[dir='rtl'] input[type='time'] {", $styles);
        $this->assertStringContainsString("html[dir='rtl'] input[type='time']::-webkit-calendar-picker-indicator {", $styles);
        $this->assertStringContainsString("html[dir='rtl'] input[type='time']::-webkit-datetime-edit {", $styles);
        $this->assertStringContainsString('justify-content: flex-end;', $styles);
        $this->assertStringContainsString("[data-backup-schedule-select] + .searchable-select,\n[data-backup-weekday-select] + .searchable-select {", $styles);
        $this->assertStringContainsString("[data-backup-schedule-select] + .searchable-select .searchable-select__search--trigger,\n[data-backup-weekday-select] + .searchable-select .searchable-select__search--trigger,\n[data-backup-time-input]", $styles);

        Volt::test('settings.backups')
            ->assertSee('data-settings-dark-surface="backup-health"', false)
            ->assertSee('data-backup-history-table', false)
            ->assertSee('class="admin-grid-meta items-center"', false)
            ->assertSee('data-backup-history-title-action-row', false)
            ->assertSee('data-backup-history-actions', false)
            ->assertSee('data-backup-settings-action', false)
            ->assertSee('data-icon-name="gear"', false)
            ->assertSee('data-backup-create-action', false)
            ->assertSee('data-icon-name="backup-upload"', false)
            ->assertSee('data-backup-new-database', false)
            ->assertSee('data-supplied-backup-database="asset-10"', false)
            ->assertSee('data-supplied-backup-asterisk="asset-10"', false)
            ->assertSee('data-backup-new-asterisk', false)
            ->assertDontSee('data-backup-create-asterisk-clearance', false)
            ->assertSee('data-backup-download-action', false)
            ->assertSee('data-icon-name="download"', false)
            ->assertSee('data-download-icon="outlined-arrow-tray"', false)
            ->assertSee('data-backup-restore-action', false)
            ->assertSee('wire:click="openRestore('.$backup->id.')" class="admin-icon-button admin-icon-button--danger"', false)
            ->assertSee('data-icon-name="restore-point"', false)
            ->assertSee('data-restore-point-history-icon="supplied-circular-clock"', false)
            ->assertSee('title="'.__('backups.actions.create').'"', false)
            ->assertSee('data-backup-file-restore-action', false)
            ->assertSee('admin-icon-button admin-icon-button--danger', false)
            ->assertSee('data-icon-name="cloud-upload"', false)
            ->assertSee('data-backup-file-upload-icon="cloud-arrow-up"', false)
            ->assertSee('title="'.__('backups.actions.restore_from_file').'"', false)
            ->call('openSettings')
            ->assertSee('id="backup-settings-form"', false)
            ->assertSee('form="backup-settings-form"', false)
            ->assertSee('data-backup-settings-save-action', false)
            ->assertSee('data-backup-app-key-action', false)
            ->assertSee('data-icon-name="info"', false)
            ->assertDontSee('aria-label="'.__('crud.common.actions.close').'"', false)
            ->call('openAppKeyInfo')
            ->assertSet('showSettingsModal', false)
            ->assertSet('showAppKeyModal', true)
            ->assertSet('appKeyRevealed', false)
            ->assertSee('data-backup-app-key-form', false)
            ->assertDontSee($appKey)
            ->set('appKeyPassword', 'incorrect-password')
            ->call('revealAppKey')
            ->assertHasErrors('appKeyPassword')
            ->assertSet('appKeyRevealed', false)
            ->set('appKeyPassword', 'password')
            ->call('revealAppKey')
            ->assertHasNoErrors('appKeyPassword')
            ->assertSet('appKeyRevealed', true)
            ->assertSet('appKeyPassword', '')
            ->assertSee('data-backup-app-key-value', false)
            ->assertSee($appKey)
            ->call('closeAppKeyInfo')
            ->assertSet('showSettingsModal', false)
            ->assertSet('showAppKeyModal', false)
            ->assertSet('appKeyRevealed', false)
            ->call('openSettings')
            ->assertSet('showSettingsModal', true)
            ->set('frequency', 'weekly')
            ->assertSee('data-backup-schedule-row', false)
            ->assertSee('class="grid gap-4 md:grid-cols-3"', false)
            ->assertSee('data-backup-weekday-select', false)
            ->set('backupTime', '03:15')
            ->set('weekday', '4')
            ->set('retentionCount', '9')
            ->set('healthWarningHours', '72')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('showSettingsModal', false)
            ->call('openRestore', $backup->id)
            ->set('restorePassword', 'password')
            ->set('restoreConfirmation', 'wrong phrase')
            ->call('restoreBackup')
            ->assertHasErrors('restoreConfirmation')
            ->set('restorePassword', 'incorrect-password')
            ->set('restoreConfirmation', __('backups.restore.confirmation_phrase'))
            ->call('restoreBackup')
            ->assertHasErrors('restorePassword')
            ->assertSet('showRestoreModal', true)
            ->call('closeRestore')
            ->call('openFileRestore')
            ->assertSet('showFileRestoreModal', true)
            ->assertSee('data-backup-file-input', false)
            ->assertSee('data-backup-file-restore-confirm-action', false)
            ->set('restoreFile', UploadedFile::fake()->create('not-a-backup.zip', 4, 'application/zip'))
            ->set('restorePassword', 'password')
            ->set('restoreConfirmation', __('backups.restore.confirmation_phrase'))
            ->call('restoreBackupFromFile')
            ->assertHasErrors('restoreFile')
            ->assertSet('showFileRestoreModal', true);

        $settings = AppSetting::groupValues('backups');
        $this->assertSame('weekly', $settings->get('frequency'));
        $this->assertSame('03:15', $settings->get('time'));
        $this->assertSame(4, $settings->get('weekday'));
        $this->assertSame(9, $settings->get('retention_count'));
        $this->assertSame(72, $settings->get('health_warning_hours'));
        $this->assertFalse($settings->get('include_files'));

        $this->get(route('settings.backups.download', $backup))
            ->assertOk()
            ->assertDownload($backup->filename)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_backup_encryption_round_trips_large_files_and_rejects_tampering(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('backups.encryption_chunk_size', 64 * 1024);

        $directory = storage_path('framework/testing/backup-encryption-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $source = $directory.'/source.bin';
        $encrypted = $directory.'/encrypted.alkhair-backup';
        $decrypted = $directory.'/decrypted.bin';
        $tamperedOutput = $directory.'/tampered-output.bin';

        try {
            file_put_contents($source, random_bytes((64 * 1024 * 2) + 37));

            $metadata = app(BackupEncryptionService::class)->encrypt($source, $encrypted);
            app(BackupEncryptionService::class)->decrypt($encrypted, $decrypted);

            $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $decrypted));
            $this->assertSame(hash_file('sha256', $encrypted), $metadata['sha256']);
            $this->assertSame(filesize($encrypted), $metadata['size_bytes']);

            $stream = fopen($encrypted, 'r+b');
            fseek($stream, -8, SEEK_END);
            fwrite($stream, random_bytes(1));
            fclose($stream);

            $this->expectException(RuntimeException::class);
            app(BackupEncryptionService::class)->decrypt($encrypted, $tamperedOutput);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_source_keys_decrypt_both_backup_formats_without_changing_the_current_key(): void
    {
        $directory = storage_path('framework/testing/backup-source-key-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $sourceKey = 'base64:'.base64_encode(random_bytes(32));
        $currentKey = 'base64:'.base64_encode(random_bytes(32));
        $encryption = app(BackupEncryptionService::class);

        try {
            File::put($directory.'/source', random_bytes(140000));
            config()->set('backups.encryption_chunk_size', 64 * 1024);

            foreach (['encrypt', 'encryptWithOpenSsl'] as $method) {
                config()->set('app.key', $sourceKey);
                $encrypted = $directory.'/'.$method.'.alkhair-backup';
                (new \ReflectionMethod($encryption, $method))->invoke($encryption, $directory.'/source', $encrypted);
                config()->set('app.key', $currentKey);

                foreach ([null, 'base64:invalid!', 'wrong-key'] as $index => $wrongKey) {
                    try {
                        $encryption->decrypt($encrypted, $directory.'/'.$method.'-wrong-'.$index, $wrongKey);
                        $this->fail('A backup must reject an incorrect source key.');
                    } catch (BackupDecryptionException $exception) {
                        $this->assertStringNotContainsString($sourceKey, (string) $exception);
                    }
                }

                $output = $directory.'/'.$method.'-output';
                $encryption->decrypt($encrypted, $output, $sourceKey);
                $this->assertSame(hash_file('sha256', $directory.'/source'), hash_file('sha256', $output));
                $this->assertSame($currentKey, config('app.key'));

                $bytes = File::get($encrypted);
                $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
                File::put($encrypted, $bytes);
                try {
                    $encryption->decrypt($encrypted, $output.'-tampered', $sourceKey);
                    $this->fail('The source key must not bypass backup authentication.');
                } catch (BackupDecryptionException) {
                    $this->assertSame($currentKey, config('app.key'));
                }
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_unsupported_mysql_conversion_reports_a_clear_error_without_restoring(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $directory = storage_path('framework/testing/backup-engine-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $sql = "CREATE TABLE `marker` (`id` int NOT NULL);\nCREATE VIEW `unsupported` AS SELECT * FROM `marker`;\n";
        $manifest = [
            'version' => 3,
            'scope' => SystemBackup::SCOPE_DATABASE,
            'data_roots' => [],
            'files' => [],
            'database' => [
                'driver' => 'mysql', 'entry' => 'database/database.sql',
                'size_bytes' => strlen($sql), 'sha256' => hash('sha256', $sql),
                'table_count' => 1, 'tables' => ['marker'],
            ],
        ];

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($directory.'/mysql.zip', ZipArchive::CREATE));
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $zip->addFromString('database/database.sql', $sql);
            $zip->close();
            app(BackupEncryptionService::class)->encrypt($directory.'/mysql.zip', $directory.'/mysql.alkhair-backup');
            $message = __('backups.errors.database_conversion_failed');

            Volt::test('settings.backups')->call('openFileRestore')
                ->set('restoreFile', UploadedFile::fake()->createWithContent('mysql.alkhair-backup', file_get_contents($directory.'/mysql.alkhair-backup')))
                ->set('restorePassword', 'password')
                ->set('restoreConfirmation', __('backups.restore.confirmation_phrase'))
                ->call('restoreBackupFromFile')
                ->assertHasErrors('restoreFileOperation')->assertSee($message)
                ->assertSet('needsRestoreAppKey', false);
            $backup = SystemBackup::query()->where('trigger', SystemBackup::TRIGGER_IMPORTED)->firstOrFail();
            Volt::test('settings.backups')->call('openRestore', $backup->id)
                ->set('restorePassword', 'password')
                ->set('restoreConfirmation', __('backups.restore.confirmation_phrase'))
                ->call('restoreBackup')->assertHasErrors('restore')->assertSee($message);

            $this->assertSame(0, SystemBackup::query()->where('trigger', SystemBackup::TRIGGER_PRE_RESTORE)->count());
            $this->assertNull($backup->fresh()->restored_at);
            $this->assertTrue(User::query()->whereKey($admin->id)->exists());
            $this->assertFalse(app()->isDownForMaintenance());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_manual_restore_requests_a_source_key_only_after_decryption_failure_and_clears_it(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $backup = $this->usableBackup($admin);
        $sourceKey = 'base64:'.base64_encode(random_bytes(32));
        $service = \Mockery::mock(SystemBackupService::class, [app(BackupEncryptionService::class)])->makePartial();
        $service->shouldReceive('import')->andReturnUsing(function ($path, $filename, $creator, $key) use ($sourceKey, $backup) {
            if ($key !== $sourceKey) {
                throw new BackupDecryptionException('Backup authentication failed.');
            }

            return $backup;
        });
        $service->shouldReceive('restore')->once()->with($backup, \Mockery::on(fn ($actor) => $actor->is($admin)));
        $this->app->instance(SystemBackupService::class, $service);

        $component = Volt::test('settings.backups')
            ->call('openFileRestore')
            ->assertDontSee('data-backup-source-app-key', false)
            ->set('restoreFile', UploadedFile::fake()->create('foreign.alkhair-backup', 4))
            ->set('restorePassword', 'password')
            ->set('restoreConfirmation', __('backups.restore.confirmation_phrase'))
            ->call('restoreBackupFromFile')
            ->assertHasErrors('restoreAppKey')
            ->assertSet('needsRestoreAppKey', true)
            ->assertSee('data-backup-source-app-key', false);

        $submit = [['method' => 'restoreBackupFromFile', 'params' => [], 'path' => '']];
        $component->update(calls: $submit, updates: ['restoreAppKey' => 'wrong-key'])
            ->assertHasErrors('restoreAppKey')
            ->assertSet('restoreAppKey', '')
            ->assertSet('showFileRestoreModal', true);

        $component->update(calls: $submit, updates: ['restoreAppKey' => $sourceKey, 'restorePassword' => 'wrong-password'])
            ->assertHasErrors('restorePassword')
            ->assertSet('restoreAppKey', '');
        $this->assertStringNotContainsString($sourceKey, $component->html());

        $component->set('restoreFile', UploadedFile::fake()->create('another.alkhair-backup', 4))
            ->assertSet('needsRestoreAppKey', false)
            ->assertDontSee('data-backup-source-app-key', false)
            ->set('restorePassword', 'password')
            ->call('restoreBackupFromFile')
            ->assertSet('needsRestoreAppKey', true);

        $component->update(calls: $submit, updates: ['restoreAppKey' => $sourceKey])
            ->assertHasNoErrors()
            ->assertSet('restoreAppKey', '')
            ->assertSet('needsRestoreAppKey', false)
            ->assertSet('showFileRestoreModal', false);
        $this->assertStringNotContainsString($sourceKey, $component->html());
    }

    public function test_every_database_table_and_persistent_application_file_is_captured(): void
    {
        Storage::fake('local');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $directory = storage_path('framework/testing/full-backup-'.Str::uuid());
        $dataRoot = $directory.'/application-data';
        $databasePath = $directory.'/application.sqlite';
        $decryptedArchive = $directory.'/decrypted.zip';
        $extractedDatabaseDirectory = $directory.'/extracted';
        $originalDefaultConnection = config('database.default');

        File::ensureDirectoryExists($dataRoot.'/private/curriculum');
        File::ensureDirectoryExists($dataRoot.'/public/students');
        File::ensureDirectoryExists($dataRoot.'/legacy-import');
        File::ensureDirectoryExists($dataRoot.'/backup-tmp');
        File::ensureDirectoryExists($dataRoot.'/mpdf');
        file_put_contents($dataRoot.'/private/curriculum/book.pdf', 'private curriculum document');
        file_put_contents($dataRoot.'/public/students/photo.jpg', 'public student photo');
        file_put_contents($dataRoot.'/legacy-import/report.json', '{"imported":true}');
        file_put_contents($dataRoot.'/backup-tmp/transient.tmp', 'temporary backup work');
        file_put_contents($dataRoot.'/mpdf/transient.tmp', 'temporary PDF work');

        config()->set('backups.data_roots', ['storage' => $dataRoot]);
        config()->set('backups.excluded_data_directories', ['backup-tmp', 'mpdf']);
        config()->set('backups.temporary_directory', $directory.'/temporary-work');
        File::put($databasePath, '');
        config()->set('database.connections.backup_full_test', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ]);
        config()->set('database.default', 'backup_full_test');
        Artisan::call('migrate', [
            '--database' => 'backup_full_test',
            '--force' => true,
        ]);

        DB::statement('CREATE TABLE future_backup_records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        DB::table('future_backup_records')->insert(['id' => 1, 'value' => 'future table data']);
        AppSetting::storeValue('backups', 'include_files', false, 'boolean');

        try {
            $service = app(SystemBackupService::class);
            $this->assertFalse($service->settings()['include_files']);

            $backup = $service->create(scope: SystemBackup::SCOPE_FULL);
            $this->assertTrue($backup->includes_files);
            $this->assertTrue($backup->isUsable());

            app(BackupEncryptionService::class)->decrypt(
                Storage::disk($backup->disk)->path($backup->file_path),
                $decryptedArchive,
            );

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($decryptedArchive));

            try {
                $manifest = json_decode(
                    $zip->getFromName('manifest.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                $databaseTables = DB::connection()
                    ->getSchemaBuilder()
                    ->getTableListing(schemaQualified: false);
                sort($databaseTables, SORT_STRING);

                $this->assertSame(SystemBackupService::MANIFEST_VERSION, $manifest['version']);
                $this->assertSame(['storage'], $manifest['data_roots']);
                $this->assertSame($databaseTables, $manifest['database']['tables']);
                $this->assertSame(count($databaseTables), $manifest['database']['table_count']);
                $this->assertContains('future_backup_records', $manifest['database']['tables']);

                $fileEntries = collect($manifest['files'])->pluck('entry')->sort()->values()->all();
                $this->assertSame([
                    'files/storage/legacy-import/report.json',
                    'files/storage/private/curriculum/book.pdf',
                    'files/storage/public/students/photo.jpg',
                ], $fileEntries);
                $this->assertSame(3, $backup->manifest_summary['files_count']);

                File::ensureDirectoryExists($extractedDatabaseDirectory);
                $this->assertTrue($zip->extractTo(
                    $extractedDatabaseDirectory,
                    [$manifest['database']['entry']],
                ));
                $database = new PDO('sqlite:'.$extractedDatabaseDirectory.'/'.$manifest['database']['entry']);
                $this->assertSame(
                    'future table data',
                    $database->query('SELECT value FROM future_backup_records WHERE id = 1')->fetchColumn(),
                );
            } finally {
                $zip->close();
            }

            $databaseBackup = $service->create();
            $filesBackup = $service->create(scope: SystemBackup::SCOPE_FILES);
            $this->assertSame(SystemBackup::SCOPE_DATABASE, $databaseBackup->scope);
            $this->assertFalse($databaseBackup->includes_files);
            $this->assertSame(0, $databaseBackup->manifest_summary['files_count']);
            $this->assertSame(SystemBackup::SCOPE_FILES, $filesBackup->scope);
            $this->assertNull($filesBackup->manifest_summary['database_driver']);
            $this->assertSame(0, $filesBackup->manifest_summary['database_size_bytes']);
            $this->assertSame(3, $filesBackup->manifest_summary['files_count']);

            DB::table('future_backup_records')->where('id', 1)->update(['value' => 'new database data']);
            File::put($dataRoot.'/private/curriculum/book.pdf', 'new document');
            $service->restore($filesBackup);
            $this->assertSame('private curriculum document', File::get($dataRoot.'/private/curriculum/book.pdf'));
            $this->assertSame('new database data', DB::table('future_backup_records')->where('id', 1)->value('value'));

            File::put($dataRoot.'/private/curriculum/book.pdf', 'keep this document');
            $service->restore($databaseBackup);
            $this->assertSame('future table data', DB::table('future_backup_records')->where('id', 1)->value('value'));
            $this->assertSame('keep this document', File::get($dataRoot.'/private/curriculum/book.pdf'));

            AppSetting::storeValue('backups', 'retention_count', 1, 'integer');
            $service->runScheduled();
            $this->assertTrue(SystemBackup::query()->whereKey($filesBackup->id)->exists());
            $scheduled = SystemBackup::query()->where('trigger', SystemBackup::TRIGGER_SCHEDULED)->latest('id')->firstOrFail();
            $this->assertSame(SystemBackup::SCOPE_DATABASE, $scheduled->scope);
            $this->assertFalse($scheduled->includes_files);
            $this->assertNull($service->runScheduled());
        } finally {
            DB::purge('backup_full_test');
            config()->set('database.default', $originalDefaultConnection);
            config()->set('database.connections.backup_full_test', null);
            File::deleteDirectory($directory);
        }
    }

    public function test_an_encrypted_archive_is_only_verified_after_database_and_file_integrity_checks(): void
    {
        Storage::fake('local');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $directory = storage_path('framework/testing/backup-verification-'.Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $databasePath = $directory.'/database.sqlite';
            $database = new PDO('sqlite:'.$databasePath);
            $database->exec('CREATE TABLE verification (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $database->exec("INSERT INTO verification (value) VALUES ('recoverable')");
            unset($database);

            $documentPath = $directory.'/document.txt';
            file_put_contents($documentPath, 'verified document');

            $manifest = [
                'version' => 2,
                'application' => 'Alkhair',
                'created_at' => now()->utc()->toIso8601String(),
                'data_roots' => ['storage'],
                'database' => [
                    'driver' => 'sqlite',
                    'entry' => 'database/database.sqlite',
                    'size_bytes' => filesize($databasePath),
                    'sha256' => hash_file('sha256', $databasePath),
                    'table_count' => 1,
                    'tables' => ['verification'],
                ],
                'files' => [[
                    'entry' => 'files/storage/document.txt',
                    'size_bytes' => filesize($documentPath),
                    'sha256' => hash_file('sha256', $documentPath),
                ]],
            ];

            $archivePath = $directory.'/backup.zip';
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $this->assertTrue($zip->addFile($databasePath, 'database/database.sqlite'));
            $this->assertTrue($zip->addFile($documentPath, 'files/storage/document.txt'));
            $this->assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
            $this->assertTrue($zip->close());

            $encryptedPath = $directory.'/verification.alkhair-backup';
            $encrypted = app(BackupEncryptionService::class)->encrypt(
                $archivePath,
                $encryptedPath,
            );

            $backup = app(SystemBackupService::class)->import(
                $encryptedPath,
                basename($encryptedPath),
            );
            $summary = $backup->manifest_summary;

            $this->assertSame('sqlite', $summary['database_driver']);
            $this->assertSame(1, $summary['files_count']);
            $this->assertSame(SystemBackup::TRIGGER_IMPORTED, $backup->trigger);
            $this->assertTrue($backup->includes_files);
            $this->assertSame($encrypted['size_bytes'], $backup->size_bytes);
            $this->assertSame($encrypted['sha256'], $backup->sha256);
            $this->assertTrue($backup->fresh()->isUsable());
            $this->assertNotNull($backup->fresh()->verified_at);

            // The same database archive must remain usable after importing with a foreign key.
            $sourceKey = (string) config('app.key');
            $currentKey = 'base64:'.base64_encode(random_bytes(32));
            config()->set('app.key', $currentKey);
            $service = app(SystemBackupService::class);
            try {
                $service->import($encryptedPath, basename($encryptedPath));
                $this->fail('Import without the source key must fail.');
            } catch (BackupDecryptionException) {
                $this->assertSame(1, SystemBackup::where('status', SystemBackup::STATUS_COMPLETED)->count());
            }
            $imported = $service->import($encryptedPath, basename($encryptedPath), applicationKey: $sourceKey);
            $this->assertTrue($imported->isUsable());
            $this->assertSame($summary, $service->verify($imported));
            $this->assertNotSame($encrypted['sha256'], $imported->sha256);
            $this->assertSame($encrypted['sha256'], hash_file('sha256', $encryptedPath));
            $this->assertSame($currentKey, config('app.key'));
            $this->assertStringNotContainsString($sourceKey, $imported->toJson());
            $localArchive = $directory.'/local-key.zip';
            app(BackupEncryptionService::class)->decrypt(Storage::disk($imported->disk)->path($imported->file_path), $localArchive);
            $this->assertSame(hash_file('sha256', $archivePath), hash_file('sha256', $localArchive));

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($archivePath));
            $zip->addFromString('files/storage/document.txt', 'does not match the manifest');
            $zip->close();
            config()->set('app.key', $sourceKey);
            $damagedPath = $directory.'/damaged.alkhair-backup';
            app(BackupEncryptionService::class)->encrypt($archivePath, $damagedPath);
            config()->set('app.key', $currentKey);
            $filesBefore = Storage::disk('local')->allFiles('backups');
            $temporaryBefore = File::directories(config('backups.temporary_directory'));
            try {
                $service->import($damagedPath, basename($damagedPath), applicationKey: $sourceKey);
                $this->fail('The source key must not bypass archive integrity checks.');
            } catch (RuntimeException $exception) {
                $this->assertNotInstanceOf(BackupDecryptionException::class, $exception);
                $this->assertSame(2, SystemBackup::where('status', SystemBackup::STATUS_COMPLETED)->count());
                $this->assertSame($filesBefore, Storage::disk('local')->allFiles('backups'));
                $this->assertSame($temporaryBefore, File::directories(config('backups.temporary_directory')));
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_backup_health_and_schedule_reflect_verified_recovery_points(): void
    {
        AppSetting::storeValue('backups', 'frequency', 'daily');
        AppSetting::storeValue('backups', 'time', '02:00');
        AppSetting::storeValue('backups', 'health_warning_hours', 48, 'integer');
        AppSetting::storeValue('general', 'school_timezone', 'UTC');

        $service = app(SystemBackupService::class);
        $now = CarbonImmutable::parse('2026-09-05 03:00:00', 'UTC');

        $this->assertTrue($service->scheduledBackupIsDue($now));
        $this->assertContains('missing', $service->health()['warnings']);

        SystemBackup::query()->create([
            'uuid' => (string) Str::uuid(),
            'disk' => 'local',
            'file_path' => 'backups/scheduled.alkhair-backup',
            'filename' => 'scheduled.alkhair-backup',
            'trigger' => SystemBackup::TRIGGER_SCHEDULED,
            'status' => SystemBackup::STATUS_COMPLETED,
            'includes_files' => true,
            'encrypted' => true,
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'verified_at' => $now->subMinutes(10),
            'created_at' => $now->subMinutes(10),
            'updated_at' => $now->subMinutes(10),
        ]);

        $this->assertFalse($service->scheduledBackupIsDue($now));
        $this->assertSame('06-09-2026 02:00', $service->nextScheduledAt($now)?->format('d-m-Y H:i'));
    }

    public static function upcomingBackupSchedules(): array
    {
        return [
            'daily before scheduled time' => ['daily', '02:00', 'UTC', '2026-09-06 01:00:00', '2026-09-06 02:00:00'],
            'daily at scheduled time' => ['daily', '02:00', 'UTC', '2026-09-06 02:00:00', '2026-09-07 02:00:00'],
            'daily after missed schedule' => ['daily', '02:00', 'UTC', '2026-09-06 03:00:00', '2026-09-07 02:00:00'],
            'daily across local midnight' => ['daily', '02:00', 'Asia/Damascus', '2026-09-05 22:00:00', '2026-09-06 02:00:00'],
            'weekly before scheduled day' => ['weekly', '00:00', 'UTC', '2026-09-03 12:00:00', '2026-09-04 00:00:00'],
            'weekly at scheduled time' => ['weekly', '00:00', 'UTC', '2026-09-04 00:00:00', '2026-09-11 00:00:00'],
            'weekly after missed schedule' => ['weekly', '00:00', 'Asia/Damascus', '2026-09-06 09:00:00', '2026-09-11 00:00:00'],
            'weekly across year boundary' => ['weekly', '00:00', 'UTC', '2026-12-31 12:00:00', '2027-01-01 00:00:00'],
            'daily after daylight saving transition' => ['daily', '02:30', 'Europe/Berlin', '2026-03-29 02:00:00', '2026-03-30 02:30:00'],
        ];
    }

    #[DataProvider('upcomingBackupSchedules')]
    public function test_next_backup_is_a_future_slot_in_the_configured_timezone(string $frequency, string $time, string $timezone, string $referenceTime, string $expected): void
    {
        AppSetting::storeValue('backups', 'frequency', $frequency);
        AppSetting::storeValue('backups', 'time', $time);
        AppSetting::storeValue('backups', 'weekday', 5, 'integer');
        AppSetting::storeValue('general', 'school_timezone', $timezone);

        $now = CarbonImmutable::parse($referenceTime, 'UTC');
        $next = app(SystemBackupService::class)->nextScheduledAt($now);

        $this->assertNotNull($next);
        $this->assertTrue($next->gt($now));
        $this->assertSame($timezone, $next->timezoneName);
        $this->assertSame($expected, $next->format('Y-m-d H:i:s'));
    }

    public function test_manual_backup_does_not_make_the_next_schedule_display_a_missed_slot(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        AppSetting::storeValue('backups', 'frequency', 'weekly');
        AppSetting::storeValue('backups', 'time', '00:00');
        AppSetting::storeValue('backups', 'weekday', 5, 'integer');
        AppSetting::storeValue('general', 'school_timezone', 'Asia/Damascus');
        app(ApplicationTimezone::class)->applyConfigured();

        $this->travelTo(CarbonImmutable::parse('2026-09-05 09:12:00', 'UTC'));
        $this->usableBackup($admin);
        $this->travelTo(CarbonImmutable::parse('2026-09-06 09:00:00', 'UTC'));

        $this->actingAs($admin)->get(route('settings.system-backups'))
            ->assertOk()
            ->assertSee('05-09-2026 12:12')
            ->assertSee('11-09-2026 00:00')
            ->assertDontSee('04-09-2026 00:00');

        // Displaying the future slot must not suppress the scheduler's catch-up run.
        $this->assertTrue(app(SystemBackupService::class)->scheduledBackupIsDue());
    }

    public function test_disabled_backup_scheduling_has_no_next_slot_or_due_run(): void
    {
        AppSetting::storeValue('backups', 'frequency', 'disabled');

        $service = app(SystemBackupService::class);
        $this->assertNull($service->nextScheduledAt());
        $this->assertFalse($service->scheduledBackupIsDue());
    }

    public function test_sqlite_restoration_atomically_activates_the_verified_database_artifact(): void
    {
        $directory = storage_path('framework/testing/backup-restore-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $livePath = $directory.'/live.sqlite';
        $artifactPath = $directory.'/artifact.sqlite';

        $this->createMarkerDatabase($livePath, 'current state');
        $this->createMarkerDatabase($artifactPath, 'recovered state');

        $originalDefault = config('database.default');
        config()->set('database.connections.backup_restore_test', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $livePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ]);
        config()->set('database.default', 'backup_restore_test');

        try {
            $method = new \ReflectionMethod(SystemBackupService::class, 'restoreSqliteDatabase');
            $method->invoke(app(SystemBackupService::class), $artifactPath);

            $this->assertSame(
                'recovered state',
                DB::connection('backup_restore_test')->table('marker')->value('value'),
            );
            $this->assertSame('ok', DB::connection('backup_restore_test')->getPdo()->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], glob($livePath.'.before-restore*') ?: []);
            $this->assertSame([], glob($livePath.'.incoming*') ?: []);
        } finally {
            DB::purge('backup_restore_test');
            config()->set('database.default', $originalDefault);
            config()->set('database.connections.backup_restore_test', null);
            File::deleteDirectory($directory);
        }
    }

    private function usableBackup(User $creator): SystemBackup
    {
        $filePath = 'backups/test.alkhair-backup';
        Storage::disk('local')->put($filePath, 'encrypted backup fixture');

        return SystemBackup::query()->create([
            'uuid' => (string) Str::uuid(),
            'disk' => 'local',
            'file_path' => $filePath,
            'filename' => basename($filePath),
            'trigger' => SystemBackup::TRIGGER_MANUAL,
            'status' => SystemBackup::STATUS_COMPLETED,
            'includes_files' => true,
            'encrypted' => true,
            'size_bytes' => Storage::disk('local')->size($filePath),
            'sha256' => hash_file('sha256', Storage::disk('local')->path($filePath)),
            'manifest_summary' => ['files_count' => 2],
            'created_by' => $creator->id,
            'verified_at' => now(),
        ]);
    }

    private function createMarkerDatabase(string $path, string $value): void
    {
        $database = new PDO('sqlite:'.$path);
        $database->exec('CREATE TABLE marker (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $statement = $database->prepare('INSERT INTO marker (value) VALUES (:value)');
        $statement->execute(['value' => $value]);
        unset($database);
    }
}
