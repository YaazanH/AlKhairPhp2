<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\SystemBackup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class SystemBackupService
{
    public const MANIFEST_VERSION = 2;

    private const SUPPORTED_MANIFEST_VERSIONS = [1, self::MANIFEST_VERSION];

    public function __construct(private readonly BackupEncryptionService $encryption) {}

    public function settings(): array
    {
        $values = AppSetting::groupValues('backups');

        return [
            'frequency' => in_array($values->get('frequency'), ['disabled', 'daily', 'weekly'], true)
                ? $values->get('frequency')
                : 'daily',
            'time' => preg_match('/^\d{2}:\d{2}$/', (string) $values->get('time'))
                ? (string) $values->get('time')
                : '02:00',
            'weekday' => min(6, max(0, (int) ($values->get('weekday') ?? 5))),
            'retention_count' => min(100, max(1, (int) ($values->get('retention_count') ?? 14))),
            'health_warning_hours' => min(720, max(1, (int) ($values->get('health_warning_hours') ?? 48))),
            // A recovery point is always complete. Keeping files optional can
            // produce a database that references documents which cannot be restored.
            'include_files' => true,
        ];
    }

    public function saveSettings(array $settings): array
    {
        AppSetting::storeValue('backups', 'frequency', $settings['frequency']);
        AppSetting::storeValue('backups', 'time', $settings['time']);
        AppSetting::storeValue('backups', 'weekday', (int) $settings['weekday'], 'integer');
        AppSetting::storeValue('backups', 'retention_count', (int) $settings['retention_count'], 'integer');
        AppSetting::storeValue('backups', 'health_warning_hours', (int) $settings['health_warning_hours'], 'integer');
        AppSetting::storeValue('backups', 'include_files', true, 'boolean');

        return $this->settings();
    }

    public function create(?User $creator = null, string $trigger = SystemBackup::TRIGGER_MANUAL): SystemBackup
    {
        if (! in_array($trigger, [SystemBackup::TRIGGER_MANUAL, SystemBackup::TRIGGER_SCHEDULED, SystemBackup::TRIGGER_PRE_RESTORE], true)) {
            throw new RuntimeException('Unsupported backup trigger.');
        }

        /** @var Lock $lock */
        $lock = Cache::lock('system-backup:operation', 7200);
        if (! $lock->get()) {
            throw new RuntimeException('Another backup or restoration operation is already running.');
        }

        try {
            return $this->createUnlocked($creator, $trigger);
        } finally {
            $lock->release();
        }
    }

    public function import(string $sourcePath, string $originalFilename, ?User $creator = null): SystemBackup
    {
        if (! File::isFile($sourcePath) || (filesize($sourcePath) ?: 0) === 0) {
            throw new RuntimeException('The imported backup file is empty or unavailable.');
        }

        if (! str_ends_with(Str::lower($originalFilename), '.alkhair-backup')) {
            throw new RuntimeException('The imported file does not use the AlKhair backup extension.');
        }

        $maximumBytes = max(1, (int) config('backups.max_upload_mb', 50)) * 1024 * 1024;
        if ((filesize($sourcePath) ?: 0) > $maximumBytes) {
            throw new RuntimeException('The imported backup exceeds the configured upload limit.');
        }

        /** @var Lock $lock */
        $lock = Cache::lock('system-backup:operation', 7200);
        if (! $lock->get()) {
            throw new RuntimeException('Another backup or restoration operation is already running.');
        }

        $backup = null;
        $storedDiskName = null;
        $storedFilePath = null;

        try {
            $uuid = (string) Str::uuid();
            $filename = 'alkhair-imported-'.now()->utc()->format('Ymd-His').'-'.substr($uuid, 0, 8).'.alkhair-backup';
            $directory = trim((string) config('backups.directory', 'backups'), '/');
            $filePath = $directory.'/'.$filename;
            $diskName = (string) config('backups.disk', 'local');
            $storedDiskName = $diskName;
            $storedFilePath = $filePath;
            $disk = Storage::disk($diskName);
            $disk->makeDirectory($directory);
            $absoluteDestination = $disk->path($filePath);

            if (File::exists($absoluteDestination)) {
                throw new RuntimeException('The generated imported backup filename already exists.');
            }

            if (! File::copy($sourcePath, $absoluteDestination)) {
                throw new RuntimeException('Unable to store the imported backup file.');
            }

            $backup = SystemBackup::query()->create([
                'uuid' => $uuid,
                'disk' => $diskName,
                'file_path' => $filePath,
                'filename' => $filename,
                'trigger' => SystemBackup::TRIGGER_IMPORTED,
                'status' => SystemBackup::STATUS_CREATING,
                'includes_files' => false,
                'encrypted' => true,
                'size_bytes' => filesize($absoluteDestination) ?: 0,
                'sha256' => hash_file('sha256', $absoluteDestination),
                'created_by' => $creator?->id,
            ]);

            $this->verify($backup);

            return $backup->fresh();
        } catch (Throwable $exception) {
            if ($backup) {
                if (Storage::disk($backup->disk)->exists($backup->file_path)) {
                    Storage::disk($backup->disk)->delete($backup->file_path);
                }

                if ($backup->status !== SystemBackup::STATUS_FAILED) {
                    $backup->forceFill([
                        'status' => SystemBackup::STATUS_FAILED,
                        'verified_at' => null,
                        'error_message' => Str::limit($exception->getMessage(), 1000),
                    ])->save();
                }
            } elseif ($storedDiskName && $storedFilePath && Storage::disk($storedDiskName)->exists($storedFilePath)) {
                Storage::disk($storedDiskName)->delete($storedFilePath);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function verify(SystemBackup $backup): array
    {
        try {
            $prepared = $this->prepareVerifiedArchive($backup);
            $summary = $this->manifestSummary($prepared['manifest']);

            $backup->forceFill([
                'status' => SystemBackup::STATUS_COMPLETED,
                'verified_at' => now(),
                'manifest_summary' => $summary,
                'includes_files' => $summary['files_count'] > 0,
                'error_message' => null,
            ])->save();

            return $summary;
        } catch (Throwable $exception) {
            $backup->forceFill([
                'status' => SystemBackup::STATUS_FAILED,
                'verified_at' => null,
                'error_message' => Str::limit($exception->getMessage(), 1000),
            ])->save();

            throw $exception;
        } finally {
            if (isset($prepared['zip']) && $prepared['zip'] instanceof ZipArchive) {
                $prepared['zip']->close();
            }
            if (isset($prepared['directory'])) {
                File::deleteDirectory($prepared['directory']);
            }
        }
    }

    public function delete(SystemBackup $backup): void
    {
        if ($backup->file_path !== '' && Storage::disk($backup->disk)->exists($backup->file_path)) {
            Storage::disk($backup->disk)->delete($backup->file_path);
        }

        $backup->delete();
    }

    public function restore(SystemBackup $backup, ?User $actor = null): void
    {
        if (! $backup->isUsable()) {
            throw new RuntimeException('Only completed and verified backups can be restored.');
        }

        /** @var Lock $lock */
        $lock = Cache::lock('system-backup:operation', 7200);
        if (! $lock->get()) {
            throw new RuntimeException('Another backup or restoration operation is already running.');
        }

        try {
            $prepared = $this->prepareVerifiedArchive($backup);
            $manifestDriver = (string) data_get($prepared['manifest'], 'database.driver');
            $currentDriver = (string) config('database.connections.'.config('database.default').'.driver');

            if ($manifestDriver !== $currentDriver) {
                throw new RuntimeException('The backup database driver does not match this installation.');
            }

            $this->assertRestoreIsSupported($currentDriver);
            // Do not prune retention while restoring: the selected recovery point
            // and its new safety copy must remain available throughout the operation.
            $safetyBackup = $this->createUnlocked($actor, SystemBackup::TRIGGER_PRE_RESTORE, false);
            $backupHistory = SystemBackup::query()->get()->map(fn (SystemBackup $item): array => $item->getAttributes())->all();

            Artisan::call('down');

            try {
                $this->restoreDatabase($prepared['database_path'], $currentDriver);
                $this->restoreFiles($prepared['zip'], $prepared['manifest']);
                $this->restoreBackupHistory($backupHistory);

                $restored = SystemBackup::query()->where('uuid', $backup->uuid)->firstOrFail();
                $restored->forceFill([
                    'status' => SystemBackup::STATUS_COMPLETED,
                    'verified_at' => now(),
                    'restored_at' => now(),
                    'restore_count' => $restored->restore_count + 1,
                    'error_message' => null,
                ])->save();

                SystemBackup::query()
                    ->where('uuid', $safetyBackup->uuid)
                    ->update(['error_message' => null]);
            } finally {
                Artisan::call('up');
            }
        } finally {
            if (isset($prepared['zip']) && $prepared['zip'] instanceof ZipArchive) {
                $prepared['zip']->close();
            }
            if (isset($prepared['directory'])) {
                File::deleteDirectory($prepared['directory']);
            }
            $lock->release();
        }
    }

    public function health(): array
    {
        $settings = $this->settings();
        $latestAttempt = SystemBackup::query()->latest()->first();
        $latestVerified = SystemBackup::query()
            ->where('status', SystemBackup::STATUS_COMPLETED)
            ->whereNotNull('verified_at')
            ->latest('verified_at')
            ->first();

        $warnings = [];
        if (! $latestVerified) {
            $warnings[] = 'missing';
        } elseif ($settings['frequency'] !== 'disabled'
            && $latestVerified->verified_at->lt(now()->subHours($settings['health_warning_hours']))) {
            $warnings[] = 'overdue';
        }

        if ($latestAttempt?->status === SystemBackup::STATUS_FAILED
            && (! $latestVerified || $latestAttempt->created_at->gt($latestVerified->verified_at))) {
            $warnings[] = 'failed';
        }

        $freeBytes = @disk_free_space(storage_path('app'));
        $minimumFreeBytes = max(0, (int) config('backups.minimum_free_space_mb', 256)) * 1024 * 1024;
        if (is_int($freeBytes) || is_float($freeBytes)) {
            if ($freeBytes < $minimumFreeBytes) {
                $warnings[] = 'low_space';
            }
        } else {
            $freeBytes = null;
        }

        return [
            'state' => in_array('failed', $warnings, true)
                ? 'danger'
                : ($warnings === [] ? 'healthy' : 'warning'),
            'warnings' => array_values(array_unique($warnings)),
            'latest_attempt' => $latestAttempt,
            'latest_verified' => $latestVerified,
            'free_bytes' => $freeBytes,
            'backup_count' => SystemBackup::query()->where('status', SystemBackup::STATUS_COMPLETED)->count(),
            'total_bytes' => (int) SystemBackup::query()->where('status', SystemBackup::STATUS_COMPLETED)->sum('size_bytes'),
        ];
    }

    public function scheduledBackupIsDue(?CarbonInterface $now = null): bool
    {
        $settings = $this->settings();
        if ($settings['frequency'] === 'disabled') {
            return false;
        }

        $candidate = $this->latestScheduleCandidate($settings, $now);
        if ($candidate->gt($this->localNow($now))) {
            return false;
        }

        return ! SystemBackup::query()
            ->where('trigger', SystemBackup::TRIGGER_SCHEDULED)
            ->where('created_at', '>=', $candidate->utc())
            ->exists();
    }

    public function nextScheduledAt(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $settings = $this->settings();
        if ($settings['frequency'] === 'disabled') {
            return null;
        }

        $localNow = $this->localNow($now);
        $candidate = $this->latestScheduleCandidate($settings, $localNow);
        $attemptExists = SystemBackup::query()
            ->where('trigger', SystemBackup::TRIGGER_SCHEDULED)
            ->where('created_at', '>=', $candidate->utc())
            ->exists();

        if ($candidate->isFuture() || ! $attemptExists) {
            return $candidate;
        }

        return $settings['frequency'] === 'weekly'
            ? $candidate->addWeek()
            : $candidate->addDay();
    }

    public function runScheduled(): ?SystemBackup
    {
        return $this->scheduledBackupIsDue()
            ? $this->create(null, SystemBackup::TRIGGER_SCHEDULED)
            : null;
    }

    public function timezone(): string
    {
        $timezone = (string) (AppSetting::groupValues('general')->get('school_timezone') ?: config('app.timezone', 'UTC'));

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    private function createUnlocked(?User $creator, string $trigger, bool $applyRetention = true): SystemBackup
    {
        $settings = $this->settings();
        $uuid = (string) Str::uuid();
        $filename = 'alkhair-'.now()->utc()->format('Ymd-His').'-'.substr($uuid, 0, 8).'.alkhair-backup';
        $directory = trim((string) config('backups.directory', 'backups'), '/');
        $filePath = $directory.'/'.$filename;

        $backup = SystemBackup::query()->create([
            'uuid' => $uuid,
            'disk' => (string) config('backups.disk', 'local'),
            'file_path' => $filePath,
            'filename' => $filename,
            'trigger' => $trigger,
            'status' => SystemBackup::STATUS_CREATING,
            'includes_files' => true,
            'encrypted' => true,
            'created_by' => $creator?->id,
        ]);

        $temporaryDirectory = $this->temporaryDirectory();

        try {
            $zipPath = $temporaryDirectory.'/backup.zip';
            $manifest = $this->buildArchive($zipPath);
            $disk = Storage::disk($backup->disk);
            $disk->makeDirectory($directory);
            $absoluteDestination = $disk->path($filePath);

            if (File::exists($absoluteDestination)) {
                throw new RuntimeException('The generated backup filename already exists.');
            }

            $encrypted = $this->encryption->encrypt($zipPath, $absoluteDestination);

            $backup->forceFill([
                'size_bytes' => $encrypted['size_bytes'],
                'sha256' => $encrypted['sha256'],
                'manifest_summary' => $this->manifestSummary($manifest),
            ])->save();

            $prepared = $this->prepareVerifiedArchive($backup);
            $prepared['zip']->close();
            File::deleteDirectory($prepared['directory']);

            $backup->forceFill([
                'status' => SystemBackup::STATUS_COMPLETED,
                'verified_at' => now(),
                'error_message' => null,
            ])->save();

            if ($applyRetention) {
                $this->enforceRetention($settings['retention_count']);
            }

            return $backup->fresh();
        } catch (Throwable $exception) {
            if (Storage::disk($backup->disk)->exists($backup->file_path)) {
                Storage::disk($backup->disk)->delete($backup->file_path);
            }

            $backup->forceFill([
                'status' => SystemBackup::STATUS_FAILED,
                'verified_at' => null,
                'error_message' => Str::limit($exception->getMessage(), 1000),
            ])->save();

            throw $exception;
        } finally {
            File::deleteDirectory($temporaryDirectory);
        }
    }

    private function buildArchive(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the backup archive.');
        }

        try {
            $database = $this->createDatabaseArtifact(dirname($zipPath));
            if (! $zip->addFile($database['path'], $database['entry'])) {
                throw new RuntimeException('Unable to add the database to the backup archive.');
            }

            $manifest = [
                'version' => self::MANIFEST_VERSION,
                'application' => (string) config('app.name'),
                'created_at' => now()->utc()->toIso8601String(),
                'data_roots' => array_keys($this->dataRoots()),
                'database' => [
                    'driver' => $database['driver'],
                    'entry' => $database['entry'],
                    'size_bytes' => filesize($database['path']) ?: 0,
                    'sha256' => hash_file('sha256', $database['path']),
                    'table_count' => count($database['tables']),
                    'tables' => $database['tables'],
                ],
                'files' => [],
            ];

            $this->addApplicationFiles($zip, $manifest);

            if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))) {
                throw new RuntimeException('Unable to add the backup manifest.');
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Unable to finalise the backup archive.');
            }
        }

        return $manifest;
    }

    private function createDatabaseArtifact(string $directory): array
    {
        $connectionName = (string) config('database.default');
        $connection = config('database.connections.'.$connectionName, []);
        $driver = (string) ($connection['driver'] ?? '');
        $tables = $this->databaseTableNames($connectionName);

        if ($tables === []) {
            throw new RuntimeException('The configured database does not contain any application tables.');
        }

        if ($driver === 'sqlite') {
            $path = $directory.'/database.sqlite';
            $quotedPath = str_replace("'", "''", $path);
            DB::connection($connectionName)->getPdo()->exec("VACUUM INTO '{$quotedPath}'");

            return ['driver' => $driver, 'path' => $path, 'entry' => 'database/database.sqlite', 'tables' => $tables];
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $path = $directory.'/database.sql';
            $binary = (string) config('backups.database_binaries.mysqldump', 'mysqldump');
            $process = new Process([
                $binary,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set='.(string) ($connection['charset'] ?? 'utf8mb4'),
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '3306'),
                '--user='.(string) ($connection['username'] ?? 'root'),
                '--result-file='.$path,
                (string) ($connection['database'] ?? ''),
            ], null, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
            $this->runProcess($process);

            return ['driver' => $driver, 'path' => $path, 'entry' => 'database/database.sql', 'tables' => $tables];
        }

        if ($driver === 'pgsql') {
            $path = $directory.'/database.dump';
            $binary = (string) config('backups.database_binaries.pg_dump', 'pg_dump');
            $process = new Process([
                $binary,
                '--format=custom',
                '--no-owner',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? 'root'),
                '--file='.$path,
                (string) ($connection['database'] ?? ''),
            ], null, ['PGPASSWORD' => (string) ($connection['password'] ?? '')]);
            $this->runProcess($process);

            return ['driver' => $driver, 'path' => $path, 'entry' => 'database/database.dump', 'tables' => $tables];
        }

        throw new RuntimeException("Database driver [{$driver}] is not supported by the backup centre.");
    }

    /**
     * @return list<string>
     */
    private function databaseTableNames(string $connectionName): array
    {
        $tables = DB::connection($connectionName)
            ->getSchemaBuilder()
            ->getTableListing(schemaQualified: false);

        sort($tables, SORT_STRING);

        return array_values(array_unique($tables));
    }

    private function addApplicationFiles(ZipArchive $zip, array &$manifest): void
    {
        foreach ($this->dataRoots() as $scope => $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            $excludedDirectories = $this->excludedDataDirectories($root);
            $finder = Finder::create()
                ->files()
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->sortByName()
                ->in($root);

            if ($excludedDirectories !== []) {
                $finder->exclude($excludedDirectories);
            }

            foreach ($finder as $file) {
                $relativePath = str_replace('\\', '/', $file->getRelativePathname());
                $entry = 'files/'.$scope.'/'.$relativePath;
                if (! $zip->addFile($file->getRealPath(), $entry)) {
                    throw new RuntimeException("Unable to add application file [{$relativePath}] to the backup archive.");
                }

                $manifest['files'][] = [
                    'entry' => $entry,
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                ];
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function dataRoots(): array
    {
        $roots = [];

        foreach ((array) config('backups.data_roots', ['storage' => storage_path('app')]) as $scope => $root) {
            $scope = trim((string) $scope);
            $root = rtrim((string) $root, DIRECTORY_SEPARATOR);

            if (! preg_match('/^[A-Za-z0-9_-]+$/', $scope) || $root === '') {
                throw new RuntimeException('A configured backup data root is invalid.');
            }

            $roots[$scope] = $root;
        }

        if ($roots === []) {
            throw new RuntimeException('At least one backup data root must be configured.');
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function excludedDataDirectories(string $root): array
    {
        $excluded = collect(config('backups.excluded_data_directories', []))
            ->map(fn (mixed $path): string => trim(str_replace('\\', '/', (string) $path), '/'))
            ->filter()
            ->values();

        $dynamicDirectories = [
            (string) config('backups.temporary_directory', storage_path('app/backup-tmp')),
        ];

        try {
            $dynamicDirectories[] = Storage::disk((string) config('backups.disk', 'local'))
                ->path(trim((string) config('backups.directory', 'backups'), '/'));
        } catch (Throwable) {
            // Remote disks are already outside a local data root.
        }

        foreach ($dynamicDirectories as $directory) {
            $relative = $this->relativePathWithinRoot($directory, $root);
            if ($relative !== null && $relative !== '') {
                $excluded->push($relative);
            }
        }

        return $excluded->unique()->sort()->values()->all();
    }

    private function relativePathWithinRoot(string $path, string $root): ?string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');

        if (! str_starts_with($normalizedPath.'/', $normalizedRoot.'/')) {
            return null;
        }

        return ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
    }

    private function prepareVerifiedArchive(SystemBackup $backup): array
    {
        if (! Storage::disk($backup->disk)->exists($backup->file_path)) {
            throw new RuntimeException('The encrypted backup file is missing.');
        }

        $encryptedPath = Storage::disk($backup->disk)->path($backup->file_path);
        if ($backup->sha256 && ! hash_equals($backup->sha256, hash_file('sha256', $encryptedPath))) {
            throw new RuntimeException('The encrypted backup checksum does not match.');
        }

        $directory = $this->temporaryDirectory();
        $zipPath = $directory.'/verified.zip';

        try {
            $this->encryption->decrypt($encryptedPath, $zipPath);
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('The decrypted backup archive cannot be opened.');
            }

            $manifestJson = $zip->getFromName('manifest.json');
            if (! is_string($manifestJson)) {
                throw new RuntimeException('The backup manifest is missing.');
            }

            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            $manifestVersion = (int) ($manifest['version'] ?? 0);
            if (! in_array($manifestVersion, self::SUPPORTED_MANIFEST_VERSIONS, true)) {
                throw new RuntimeException('The backup manifest version is not supported.');
            }

            $database = $manifest['database'] ?? null;
            if (! is_array($database) || blank($database['entry'] ?? null)) {
                throw new RuntimeException('The backup database entry is invalid.');
            }

            $databasePath = $directory.'/database-artifact';
            $this->copyAndVerifyEntry($zip, $database, $databasePath);

            foreach ($manifest['files'] ?? [] as $file) {
                if (! is_array($file)) {
                    throw new RuntimeException('The backup file manifest is invalid.');
                }
                $this->verifyEntry($zip, $file);
            }

            $expectedTables = array_values(array_filter(
                (array) ($database['tables'] ?? []),
                fn (mixed $table): bool => is_string($table) && $table !== '',
            ));
            sort($expectedTables, SORT_STRING);

            if ($manifestVersion >= 2) {
                if ($expectedTables === [] || (int) ($database['table_count'] ?? -1) !== count($expectedTables)) {
                    throw new RuntimeException('The backup database table inventory is incomplete.');
                }

                $configuredRoots = array_keys($this->dataRoots());
                $manifestRoots = array_values(array_filter(
                    (array) ($manifest['data_roots'] ?? []),
                    fn (mixed $scope): bool => is_string($scope) && $scope !== '',
                ));
                sort($configuredRoots, SORT_STRING);
                sort($manifestRoots, SORT_STRING);

                if ($configuredRoots !== $manifestRoots) {
                    throw new RuntimeException('The backup application data-root inventory is incomplete.');
                }
            }

            $this->testDatabaseArtifact((string) $database['driver'], $databasePath, $expectedTables);

            return [
                'directory' => $directory,
                'zip' => $zip,
                'manifest' => $manifest,
                'database_path' => $databasePath,
            ];
        } catch (Throwable $exception) {
            if (isset($zip) && $zip instanceof ZipArchive) {
                $zip->close();
            }
            File::deleteDirectory($directory);

            throw $exception;
        }
    }

    private function verifyEntry(ZipArchive $zip, array $entry): void
    {
        $stream = $zip->getStream((string) ($entry['entry'] ?? ''));
        if ($stream === false) {
            throw new RuntimeException('A file listed in the backup manifest is missing.');
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualHash = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (! hash_equals((string) ($entry['sha256'] ?? ''), $actualHash)) {
            throw new RuntimeException('A file in the backup archive failed its checksum test.');
        }
    }

    private function copyAndVerifyEntry(ZipArchive $zip, array $entry, string $destination): void
    {
        $source = $zip->getStream((string) ($entry['entry'] ?? ''));
        $target = fopen($destination, 'xb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw new RuntimeException('Unable to extract the database from the backup archive.');
        }

        $hash = hash_init('sha256');
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the database backup entry.');
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($hash, $chunk);
                $this->writeToStream($target, $chunk);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        if (! hash_equals((string) ($entry['sha256'] ?? ''), hash_final($hash))) {
            throw new RuntimeException('The database backup failed its checksum test.');
        }
    }

    /**
     * @param list<string> $expectedTables
     */
    private function testDatabaseArtifact(string $driver, string $path, array $expectedTables = []): void
    {
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:'.$path);
            $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($result !== 'ok') {
                throw new RuntimeException('The SQLite restoration test failed its integrity check.');
            }

            if ($expectedTables !== []) {
                $actualTables = $pdo
                    ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                    ->fetchAll(PDO::FETCH_COLUMN);
                $this->assertExpectedDatabaseTables($expectedTables, $actualTables);
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if ((filesize($path) ?: 0) === 0) {
                throw new RuntimeException('The SQL database backup is empty.');
            }

            if ($expectedTables !== []) {
                $actualTables = [];
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('The SQL database backup cannot be inspected.');
                }

                try {
                    while (($line = fgets($stream)) !== false) {
                        if (preg_match('/^\s*CREATE TABLE(?: IF NOT EXISTS)?\s+[\x60"]?([^\x60"\s(]+)[\x60"]?/i', $line, $matches)) {
                            $actualTables[] = $matches[1];
                        }
                    }
                } finally {
                    fclose($stream);
                }

                $this->assertExpectedDatabaseTables($expectedTables, $actualTables);
            }

            return;
        }

        if ($driver === 'pgsql') {
            $header = file_get_contents($path, false, null, 0, 5);
            if ($header !== 'PGDMP') {
                throw new RuntimeException('The PostgreSQL database backup header is invalid.');
            }

            if ($expectedTables !== []) {
                $process = new Process([
                    (string) config('backups.database_binaries.pg_restore', 'pg_restore'),
                    '--list',
                    $path,
                ]);
                $this->runProcess($process);
                $listing = $process->getOutput();
                $actualTables = [];

                foreach (preg_split('/\R/', $listing) ?: [] as $line) {
                    if (preg_match('/\bTABLE\s+\S+\s+(\S+)\s+\S+\s*$/', $line, $matches)) {
                        $actualTables[] = trim($matches[1], '"');
                    }
                }

                $this->assertExpectedDatabaseTables($expectedTables, $actualTables);
            }

            return;
        }

        throw new RuntimeException('The backup database driver is not supported.');
    }

    /**
     * @param list<string> $expectedTables
     * @param array<int, mixed> $actualTables
     */
    private function assertExpectedDatabaseTables(array $expectedTables, array $actualTables): void
    {
        $actualTables = array_values(array_unique(array_filter(
            $actualTables,
            fn (mixed $table): bool => is_string($table) && $table !== '',
        )));
        $missingTables = array_values(array_diff($expectedTables, $actualTables));

        if ($missingTables !== []) {
            throw new RuntimeException(
                'The database backup is missing tables: '.implode(', ', $missingTables).'.',
            );
        }
    }

    private function assertRestoreIsSupported(string $driver): void
    {
        if ($driver === 'sqlite') {
            $connection = config('database.connections.'.config('database.default'), []);
            if (($connection['database'] ?? null) === ':memory:') {
                throw new RuntimeException('An in-memory SQLite database cannot be restored.');
            }
        }

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException("Database driver [{$driver}] cannot be restored.");
        }
    }

    private function restoreDatabase(string $artifactPath, string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->restoreSqliteDatabase($artifactPath);

            return;
        }

        $connectionName = (string) config('database.default');
        $connection = config('database.connections.'.$connectionName, []);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $process = new Process([
                (string) config('backups.database_binaries.mysql', 'mysql'),
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '3306'),
                '--user='.(string) ($connection['username'] ?? 'root'),
                '--default-character-set='.(string) ($connection['charset'] ?? 'utf8mb4'),
                (string) ($connection['database'] ?? ''),
            ], null, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
            $process->setInput(fopen($artifactPath, 'rb'));
            $this->runProcess($process);
            DB::purge($connectionName);

            return;
        }

        if ($driver === 'pgsql') {
            $process = new Process([
                (string) config('backups.database_binaries.pg_restore', 'pg_restore'),
                '--clean',
                '--if-exists',
                '--no-owner',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? 'root'),
                '--dbname='.(string) ($connection['database'] ?? ''),
                $artifactPath,
            ], null, ['PGPASSWORD' => (string) ($connection['password'] ?? '')]);
            $this->runProcess($process);
            DB::purge($connectionName);
        }
    }

    private function restoreSqliteDatabase(string $artifactPath): void
    {
        $connectionName = (string) config('database.default');
        $configuredPath = (string) config('database.connections.'.$connectionName.'.database');
        $databasePath = str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            ? $configuredPath
            : base_path($configuredPath);
        $suffix = '.'.Str::uuid();
        $incomingPath = $databasePath.'.incoming'.$suffix;
        $rollbackPath = $databasePath.'.before-restore'.$suffix;

        File::copy($artifactPath, $incomingPath);

        try {
            DB::connection($connectionName)->getPdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (Throwable) {
            // The installation may not use WAL mode.
        }

        DB::purge($connectionName);

        if (! rename($databasePath, $rollbackPath)) {
            File::delete($incomingPath);
            throw new RuntimeException('Unable to preserve the current SQLite database before restoration.');
        }

        try {
            if (! rename($incomingPath, $databasePath)) {
                throw new RuntimeException('Unable to activate the restored SQLite database.');
            }

            File::delete([$databasePath.'-wal', $databasePath.'-shm']);
            DB::reconnect($connectionName);
            $result = DB::connection($connectionName)->getPdo()->query('PRAGMA integrity_check')->fetchColumn();
            if ($result !== 'ok') {
                throw new RuntimeException('The restored SQLite database failed its integrity check.');
            }

            File::delete($rollbackPath);
        } catch (Throwable $exception) {
            DB::purge($connectionName);
            if (File::exists($databasePath)) {
                File::delete($databasePath);
            }
            if (File::exists($rollbackPath)) {
                rename($rollbackPath, $databasePath);
            }
            File::delete($incomingPath);
            DB::reconnect($connectionName);

            throw $exception;
        }
    }

    private function restoreFiles(ZipArchive $zip, array $manifest): void
    {
        $roots = array_merge([
            'private' => storage_path('app/private'),
            'public' => storage_path('app/public'),
        ], $this->dataRoots());

        foreach ($manifest['files'] ?? [] as $file) {
            $entry = (string) ($file['entry'] ?? '');
            if (! preg_match('#^files/([A-Za-z0-9_-]+)/(.+)$#', $entry, $matches)) {
                throw new RuntimeException('A file restoration path is invalid.');
            }

            $scope = $matches[1];
            if (! isset($roots[$scope])) {
                throw new RuntimeException('A file restoration data root is not configured.');
            }

            $relative = str_replace('\\', '/', $matches[2]);
            if ($relative === '' || str_contains('/'.$relative.'/', '/../')) {
                throw new RuntimeException('A file restoration path is unsafe.');
            }

            $root = $roots[$scope];
            $destination = $root.'/'.$relative;
            File::ensureDirectoryExists(dirname($destination));
            $temporaryDestination = $destination.'.restore-'.Str::uuid();
            $this->copyAndVerifyEntry($zip, $file, $temporaryDestination);

            if (! rename($temporaryDestination, $destination)) {
                File::delete($temporaryDestination);
                throw new RuntimeException("Unable to restore uploaded file [{$relative}].");
            }
        }
    }

    private function restoreBackupHistory(array $backupHistory): void
    {
        $existingUserIds = User::query()->pluck('id')->flip();

        foreach ($backupHistory as $attributes) {
            unset($attributes['id']);
            if (! $existingUserIds->has($attributes['created_by'] ?? null)) {
                $attributes['created_by'] = null;
            }

            DB::table('system_backups')->updateOrInsert(
                ['uuid' => $attributes['uuid']],
                $attributes,
            );
        }
    }

    private function enforceRetention(int $retentionCount): void
    {
        SystemBackup::query()
            ->where('status', SystemBackup::STATUS_COMPLETED)
            ->latest('created_at')
            ->latest('id')
            ->skip(max(1, $retentionCount))
            ->take(PHP_INT_MAX)
            ->get()
            ->each(fn (SystemBackup $backup) => $this->delete($backup));
    }

    private function latestScheduleCandidate(array $settings, ?CarbonInterface $now = null): CarbonImmutable
    {
        $localNow = $this->localNow($now);
        [$hour, $minute] = array_map('intval', explode(':', $settings['time']));
        $candidate = $localNow->startOfDay()->setTime($hour, $minute);

        if ($settings['frequency'] === 'weekly') {
            $daysSinceScheduledWeekday = ($localNow->dayOfWeek - $settings['weekday'] + 7) % 7;
            $candidate = $candidate->subDays($daysSinceScheduledWeekday);
        }

        if ($candidate->gt($localNow)) {
            $candidate = $settings['frequency'] === 'weekly'
                ? $candidate->subWeek()
                : $candidate->subDay();
        }

        return $candidate;
    }

    private function localNow(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())->setTimezone($this->timezone());
    }

    private function manifestSummary(array $manifest): array
    {
        return [
            'version' => $manifest['version'] ?? null,
            'database_driver' => data_get($manifest, 'database.driver'),
            'database_size_bytes' => (int) data_get($manifest, 'database.size_bytes', 0),
            'files_count' => count($manifest['files'] ?? []),
            'files_size_bytes' => (int) collect($manifest['files'] ?? [])->sum('size_bytes'),
        ];
    }

    private function temporaryDirectory(): string
    {
        $root = (string) config('backups.temporary_directory', storage_path('app/backup-tmp'));
        $directory = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.Str::uuid();
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    private function runProcess(Process $process): void
    {
        $process->setTimeout(max(60, (int) config('backups.process_timeout_seconds', 3600)));
        $process->mustRun();
    }

    private function writeToStream(mixed $stream, string $contents): void
    {
        $written = 0;
        $length = strlen($contents);

        while ($written < $length) {
            $result = fwrite($stream, substr($contents, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Unable to extract the database backup entry.');
            }
            $written += $result;
        }
    }
}
