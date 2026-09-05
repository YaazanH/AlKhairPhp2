<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\BackupEncryptionService;
use App\Services\SystemBackupService;
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
        $this->assertStringContainsString('colspan="6"', $backupView);
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
        $this->assertStringContainsString('<bdi dir="ltr">{{ $backup->verified_at', $backupView);
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
        $this->assertTrue($settings->get('include_files'));

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
            $this->assertTrue($service->settings()['include_files']);

            $backup = $service->create();
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
                'version' => SystemBackupService::MANIFEST_VERSION,
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
