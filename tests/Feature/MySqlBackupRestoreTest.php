<?php

namespace Tests\Feature;

use App\Models\SystemBackup;
use App\Services\BackupEncryptionService;
use App\Services\MySqlToSqliteBackupConverter;
use App\Services\SystemBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class MySqlBackupRestoreTest extends TestCase
{
    #[DataProvider('driverProvider')]
    public function test_foreign_key_import_converts_and_restores_with_a_recoverable_safety_copy(string $driver): void
    {
        Storage::fake('local');
        $directory = storage_path('framework/testing/mysql-restore-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $originalConnection = config('database.default');
        $localKey = config('app.key');
        $sourceKey = 'base64:'.base64_encode(random_bytes(32));
        $sourceSql = <<<'SQL'
CREATE TABLE `users` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`));
CREATE TABLE `app_settings` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `group` varchar(100), `key` varchar(100), `value` text, `type` varchar(20), PRIMARY KEY (`id`));
CREATE TABLE `marker` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `value` text, PRIMARY KEY (`id`));
CREATE TABLE `system_backups` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `uuid` varchar(36) NOT NULL,
 `disk` varchar(255) NOT NULL DEFAULT 'local',
 `file_path` varchar(255) NOT NULL,
 `filename` varchar(255) NOT NULL,
 `trigger` varchar(255) NOT NULL,
 `scope` varchar(20) NOT NULL DEFAULT 'full',
 `status` varchar(255) NOT NULL,
 `includes_files` tinyint(1) NOT NULL DEFAULT '1',
 `encrypted` tinyint(1) NOT NULL DEFAULT '1',
 `size_bytes` bigint unsigned DEFAULT NULL,
 `sha256` char(64) DEFAULT NULL,
 `manifest_summary` json DEFAULT NULL,
 `created_by` bigint unsigned DEFAULT NULL,
 `verified_at` timestamp NULL DEFAULT NULL,
 `restored_at` timestamp NULL DEFAULT NULL,
 `restore_count` int unsigned NOT NULL DEFAULT '0',
 `error_message` text,
 `created_at` timestamp NULL DEFAULT NULL,
 `updated_at` timestamp NULL DEFAULT NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `system_backups_uuid_unique` (`uuid`),
 UNIQUE KEY `system_backups_file_path_unique` (`file_path`),
 CONSTRAINT `system_backups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `marker` VALUES (1,'restored from MySQL: حضور متأخر');
INSERT INTO `system_backups` (`id`,`uuid`,`file_path`,`filename`,`trigger`,`status`) VALUES (17,'foreign-backup-history','foreign.alkhair-backup','foreign.alkhair-backup','manual','completed');
SQL;
        try {
            File::put($directory.'/source.sql', $sourceSql);
            app(MySqlToSqliteBackupConverter::class)->convert($directory.'/source.sql', $directory.'/local.sqlite');
            config()->set('database.connections.mysql_restore_test', [
                'driver' => 'sqlite', 'database' => $directory.'/local.sqlite', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            DB::setDefaultConnection('mysql_restore_test');
            DB::table('marker')->where('id', 1)->update(['value' => 'local value before restore']);
            DB::table('system_backups')->delete();
            DB::statement("UPDATE sqlite_sequence SET seq=0 WHERE name='system_backups'");
            $manifest = [
                'version' => 3, 'scope' => 'database', 'files' => [], 'data_roots' => [],
                'database' => [
                    'driver' => $driver, 'entry' => 'database/database.sql',
                    'size_bytes' => strlen($sourceSql), 'sha256' => hash('sha256', $sourceSql),
                    'tables' => ['users', 'app_settings', 'marker', 'system_backups'], 'table_count' => 4,
                ],
            ];
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($directory.'/source.zip', ZipArchive::CREATE));
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $zip->addFromString('database/database.sql', $sourceSql);
            $zip->close();
            config()->set('app.key', $sourceKey);
            app(BackupEncryptionService::class)->encrypt($directory.'/source.zip', $directory.'/source.alkhair-backup');
            config()->set('app.key', $localKey);

            // Exercise the real import, conversion, safety backup, atomic swap, and history restoration.
            // Maintenance mode is mocked so the developer's running application is never taken offline.
            Artisan::shouldReceive('call')->once()->with('down')->andReturn(0);
            Artisan::shouldReceive('call')->once()->with('up')->andReturn(0);
            $service = app(SystemBackupService::class);
            $backup = $service->import($directory.'/source.alkhair-backup', 'source.alkhair-backup', applicationKey: $sourceKey);
            $this->assertSame($driver, $backup->manifest_summary['database_driver']);
            $service->restore($backup);

            $this->assertSame('restored from MySQL: حضور متأخر', DB::table('marker')->value('value'));
            $this->assertSame('sqlite', DB::connection()->getDriverName());
            $this->assertSame($localKey, config('app.key'));
            $restored = SystemBackup::where('uuid', $backup->uuid)->firstOrFail();
            $this->assertNotSame($backup->id, $restored->id);
            $this->assertNotNull($restored->restored_at);
            $this->assertSame(1, $restored->restore_count);
            $safety = SystemBackup::where('trigger', SystemBackup::TRIGGER_PRE_RESTORE)->firstOrFail();
            $this->assertTrue($safety->isUsable());
            $this->assertSame('sqlite', $safety->manifest_summary['database_driver']);
            app(BackupEncryptionService::class)->decrypt(Storage::disk($safety->disk)->path($safety->file_path), $directory.'/safety.zip');
            $this->assertTrue($zip->open($directory.'/safety.zip'));
            File::put($directory.'/safety.sqlite', $zip->getFromName('database/database.sqlite'));
            $zip->close();
            $safetyDatabase = new PDO('sqlite:'.$directory.'/safety.sqlite');
            $this->assertSame('local value before restore', $safetyDatabase->query('SELECT value FROM marker')->fetchColumn());
            $this->assertSame('ok', DB::connection()->getPdo()->query('PRAGMA integrity_check')->fetchColumn());
            $this->assertSame([], glob($directory.'/local.sqlite.incoming*') ?: []);
            $this->assertSame([], glob($directory.'/local.sqlite.before-restore*') ?: []);
        } finally {
            config()->set('app.key', $localKey);
            DB::purge('mysql_restore_test');
            DB::setDefaultConnection($originalConnection);
            File::deleteDirectory($directory);
        }
    }

    public static function driverProvider(): array
    {
        return [['mysql'], ['mariadb']];
    }
}
