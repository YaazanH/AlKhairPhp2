<?php

namespace App\Services\StandaloneTransfer;

use App\Services\BackupEncryptionService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use ZipArchive;

class TransferExporter
{
    public function __construct(private TransferContract $contract, private BackupEncryptionService $encryption) {}

    public function export(string $destination, #[SensitiveParameter] string $secret): array
    {
        if (! app()->environment('testing') && ! app()->isDownForMaintenance()) {
            throw new RuntimeException('source_not_in_maintenance');
        }
        $db = DB::connection();
        if (! in_array($db->getDriverName(), app()->environment('testing') ? ['mysql', 'mariadb', 'sqlite'] : ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('unsupported_database');
        }
        $this->assertSchema($db);
        if (! app()->environment('testing')) {
            $this->assertFileRoots();
        }
        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret)) {
            throw new RuntimeException('invalid_transfer_code');
        }
        if (file_exists($destination)) {
            throw new RuntimeException('destination_exists');
        }
        $directory = storage_path('app/tenant-transfer-export/'.Str::uuid());
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('staging_unavailable');
        }
        $zip = new ZipArchive;
        $zipPath = $directory.'/package.zip';
        $files = [];
        $opened = false;
        $buffered = null;
        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('staging_unavailable');
            }
            $opened = true;
            if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $buffered = $db->getPdo()->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
                $db->getPdo()->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
            $db->beginTransaction();
            $manifest = [
                'format' => 'alkhair-standalone-transfer', 'version' => 1,
                'export_id' => (string) Str::uuid(), 'source_id' => $this->sourceId(),
                'source_ref' => $this->contract->schema()['source_ref'],
                'snapshot_at' => now()->toIso8601String(), 'engine' => $db->getDriverName(),
                'engine_version' => $db->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
                'source_url' => (string) config('app.url'),
                'locale' => (string) config('app.locale'), 'timezone' => (string) config('app.timezone'),
                'tables' => [], 'files' => [], 'excluded_tables' => TransferContract::EXCLUDED,
            ];
            foreach ($this->contract->tables() as $table => $columns) {
                $path = $directory.'/'.$table.'.jsonl';
                $stream = fopen($path, 'xb');
                $count = 0;
                try {
                    foreach ($db->table($table)->useWritePdo()->cursor() as $record) {
                        $row = (array) $record;
                        if ($table === 'users') {
                            $row['remember_token'] = null;
                            if (filled($row['issued_password'] ?? null)) {
                                $row['issued_password'] = Crypt::decryptString($row['issued_password']);
                            }
                        }
                        $line = $this->contract->encode($row);
                        if (strlen($line) > config('standalone-import.maximum_row_bytes')) {
                            throw new RuntimeException('row_too_large');
                        }
                        if (fwrite($stream, $line) !== strlen($line)) {
                            throw new RuntimeException('staging_unavailable');
                        }
                        $count++;
                    }
                } finally {
                    fclose($stream);
                }
                $entry = 'tables/'.$table.'.jsonl';
                $manifest['tables'][$table] = ['entry' => $entry, 'columns' => $columns, 'rows' => $count, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
                $zip->addFile($path, $entry);
            }
            foreach (['public' => 'public', 'local' => 'private'] as $disk => $scope) {
                $root = config('filesystems.disks.'.$disk.'.root');
                if (! is_dir($root)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1));
                    if ($file->isLink()) {
                        throw new RuntimeException('unsafe_path');
                    }
                    if (preg_match('#^tenants/#i', $relative)) {
                        throw new RuntimeException('unsupported_storage_root');
                    }
                    if (! $file->isFile() || $relative === '.gitignore' || preg_match('#^(?:livewire-tmp|backups|saas-backups|mpdf|framework)/#', $relative)) {
                        continue;
                    }
                    $entry = 'files/'.$scope.'/'.$this->contract->path($relative);
                    $manifest['files'][$entry] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
                    $files[$entry] = $file->getPathname();
                    $zip->addFile($file->getPathname(), $entry);
                    if (count($files) > config('standalone-import.maximum_entries')) {
                        throw new RuntimeException('package_too_large');
                    }
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (! $zip->close()) {
                throw new RuntimeException('staging_unavailable');
            }
            $opened = false;
            // A changed file means the operator's source write barrier was incomplete.
            foreach ($files as $entry => $path) {
                if (! hash_equals($manifest['files'][$entry]['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('source_changed_during_export');
                }
            }
            $db->rollBack();
            if (disk_free_space(dirname($destination)) < filesize($zipPath) * 2 + 256 * 1024 * 1024) {
                throw new RuntimeException('insufficient_scratch_space');
            }
            $previousChunkSize = config('backups.encryption_chunk_size');
            config(['backups.encryption_chunk_size' => 1024 * 1024]);
            try {
                $this->encryption->encrypt($zipPath, $destination, $secret, portable: true);
                $verifiedPath = $directory.'/verified.zip';
                $this->encryption->decrypt($destination, $verifiedPath, $secret);
            } finally {
                config(['backups.encryption_chunk_size' => $previousChunkSize]);
            }
            if (! hash_equals(hash_file('sha256', $zipPath), hash_file('sha256', $verifiedPath))) {
                throw new RuntimeException('checksum_mismatch');
            }

            return ['export_id' => $manifest['export_id'], 'source_id' => $manifest['source_id'], 'tables' => count($manifest['tables']), 'files' => count($files)];
        } catch (\Throwable $exception) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $exception;
        } finally {
            if ($buffered !== null) {
                $db->getPdo()->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
            if ($opened) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                    // Already closed.
                }
            }
            app('files')->deleteDirectory($directory);
        }
    }

    private function assertSchema(Connection $db): void
    {
        $expected = $this->contract->schema();
        $tables = $db->getSchemaBuilder()->getTableListing($db->getDriverName() === 'sqlite' ? null : $db->getDatabaseName(), false);
        sort($tables);
        if ($tables !== array_keys($expected['tables'])) {
            throw new RuntimeException('unsupported_schema');
        }
        foreach ($expected['tables'] as $table => $columns) {
            $actual = $db->getSchemaBuilder()->getColumnListing($table);
            sort($actual);
            if ($columns !== $actual) {
                throw new RuntimeException('unsupported_schema');
            }
        }
        $migrations = $db->table('migrations')->pluck('migration')->sort()->values()->all();
        $known = array_keys($expected['migrations']);
        sort($known);
        if ($migrations !== $known) {
            throw new RuntimeException('unsupported_schema');
        }
        foreach ($expected['migrations'] as $name => $checksum) {
            $file = database_path('migrations/'.$name.'.php');
            if (! is_file($file) || ! hash_equals($checksum, hash('sha256', str_replace("\r\n", "\n", file_get_contents($file))))) {
                throw new RuntimeException('unsupported_schema');
            }
        }
    }

    private function sourceId(): string
    {
        $path = config('standalone-import.source_identity_file', storage_path('app/tenant-transfer-source-id'));
        if (! is_file($path)) {
            $stream = @fopen($path, 'xb');
            if ($stream) {
                fwrite($stream, (string) Str::uuid());
                fclose($stream);
                chmod($path, 0600);
            }
        }
        $id = trim((string) @file_get_contents($path));
        if (! Str::isUuid($id)) {
            throw new RuntimeException('source_identity_unavailable');
        }

        return $id;
    }

    private function assertFileRoots(): void
    {
        $known = $this->contract->schema()['public_assets'];
        $root = public_path();
        if (file_exists($root.'/storage') && realpath($root.'/storage') !== realpath(config('filesystems.disks.public.root'))) {
            throw new RuntimeException('unsupported_public_assets');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            function ($file) use ($root): bool {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                return ! in_array($relative, ['storage', 'build', 'hot', '.htaccess', '.user.ini'], true);
            },
        ));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isLink() || ! isset($known[$relative])) {
                throw new RuntimeException('unsupported_public_assets');
            }
            $contents = file_get_contents($file->getPathname());
            if (preg_match('/\.(php|html|css|js|txt|svg|json)$/', $relative)) {
                $contents = str_replace("\r\n", "\n", $contents);
            }
            if (! hash_equals($known[$relative], hash('sha256', $contents))) {
                throw new RuntimeException('unsupported_public_assets');
            }
        }
        foreach (glob(storage_path('app/*')) as $path) {
            if (! in_array(basename($path), ['private', 'public', 'backup-tmp', 'backups', 'mpdf', 'tenant-transfer-source-id', 'tenant-transfer-export', 'tenant-transfer-exports', 'legacy-access-export', 'legacy-quran-import', 'codebase-search.sqlite'], true)) {
                throw new RuntimeException('unsupported_storage_root');
            }
        }
    }
}
