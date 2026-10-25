<?php

namespace App\Services\StandaloneTransfer;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use SensitiveParameter;
use ZipArchive;

class SettingsBackupTransfer
{
    public function __construct(private TransferContract $contract, private SettingsBackupSql $sql, private SettingsBackupIdentity $identity) {}

    public function convert(string $source, string $destination, #[SensitiveParameter] string $applicationKey, string $sourceUrl, ?callable $heartbeat = null): void
    {
        $input = new ZipArchive;
        $output = new ZipArchive;
        $directory = $destination.'.work';
        $streams = [];
        $inputOpen = false;
        $outputOpen = false;
        if (file_exists($destination) || file_exists($directory) || ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('staging_unavailable');
        }
        try {
            if ($input->open($source) !== true) {
                throw new RuntimeException('invalid_package');
            }
            $inputOpen = true;
            $backup = $this->inventory($input);
            $expanded = array_sum(array_column($backup['files'], 'size_bytes')) + $backup['database']['size_bytes'];
            if (disk_free_space($directory) < $expanded * 3 + 256 * 1024 * 1024) {
                throw new RuntimeException('insufficient_scratch_space');
            }
            $key = str_starts_with($applicationKey, 'base64:') ? base64_decode(substr($applicationKey, 7), true) : $applicationKey;
            if (! is_string($key) || ! Encrypter::supported($key, 'AES-256-CBC')) {
                throw new RuntimeException('invalid_source_key');
            }
            $encrypter = new Encrypter($key, 'AES-256-CBC');
            $legacyId = $this->identity->legacyId($key);
            $sourceId = $this->identity->sourceId($legacyId, $sourceUrl);
            $sourceIdentity = ['method' => 'key-and-url-v2', 'legacy_source_id' => $legacyId, 'location' => $this->identity->location($sourceUrl)];
            foreach ($backup['files'] as $file) {
                if ($file['entry'] === 'files/storage/tenant-transfer-source-id') {
                    if ($file['size_bytes'] > 128 || ! Str::isUuid(trim($input->getFromName($file['entry'])))) {
                        throw new RuntimeException('invalid_inventory');
                    }
                    $sourceId = trim($input->getFromName($file['entry']));
                    $sourceIdentity = ['method' => 'source-id-file-v1'];
                }
            }
            $database = $directory.'/database.sql';
            $this->copy($input, $backup['database'], $database);
            $manifest = [
                'format' => 'alkhair-standalone-transfer', 'version' => 1,
                'source_ref' => $this->contract->schema()['source_ref'], 'source_id' => $sourceId,
                'source_identity' => $sourceIdentity,
                'export_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'alkhair-settings-snapshot:'.hash_file('sha256', $source)),
                'snapshot_at' => $backup['created_at'], 'engine' => $backup['database']['driver'],
                'engine_version' => 'Settings backup', 'source_url' => $sourceUrl,
                'tables' => [], 'files' => [], 'excluded_tables' => TransferContract::EXCLUDED,
                'preserved_history_files' => 0,
            ];
            $counts = [];
            foreach ($this->contract->tables() as $table => $columns) {
                $streams[$table] = fopen($directory.'/'.$table.'.jsonl', 'xb');
                if (! is_resource($streams[$table])) {
                    throw new RuntimeException('staging_unavailable');
                }
                $counts[$table] = 0;
            }
            $migrations = [];
            $bytes = 0;
            $rows = 0;
            foreach ($this->sql->rows($database, fn () => $heartbeat && $heartbeat()) as [$table, $row]) {
                if ($table === 'migrations') {
                    $migrations[] = $row['migration'];
                    if (count($migrations) > count($this->contract->schema()['migrations'])) {
                        throw new RuntimeException('unsupported_schema');
                    }
                }
                if (! isset($streams[$table])) {
                    continue;
                }
                if ($table === 'users') {
                    $row['remember_token'] = null;
                    if (filled($row['issued_password'] ?? null)) {
                        try {
                            $row['issued_password'] = $encrypter->decryptString($row['issued_password']);
                        } catch (\Throwable) {
                            throw new RuntimeException('invalid_source_key');
                        }
                    }
                }
                $line = $this->contract->encode($row);
                if (strlen($line) > config('standalone-import.maximum_row_bytes')) {
                    throw new RuntimeException('row_too_large');
                }
                $bytes += strlen($line);
                if ($bytes + $expanded > config('standalone-import.maximum_expanded_bytes')) {
                    throw new RuntimeException('package_too_large');
                }
                if (fwrite($streams[$table], $line) !== strlen($line)) {
                    throw new RuntimeException('staging_unavailable');
                }
                $counts[$table]++;
                if (++$rows % 1000 === 0) {
                    $heartbeat && $heartbeat();
                }
            }
            sort($migrations, SORT_STRING);
            $known = array_keys($this->contract->schema()['migrations']);
            sort($known, SORT_STRING);
            if ($migrations !== $known) {
                throw new RuntimeException('unsupported_schema');
            }
            foreach ($streams as $stream) {
                fclose($stream);
            }
            $streams = [];
            if ($output->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('staging_unavailable');
            }
            $outputOpen = true;
            foreach ($this->contract->tables() as $table => $columns) {
                $path = $directory.'/'.$table.'.jsonl';
                $entry = 'tables/'.$table.'.jsonl';
                $manifest['tables'][$table] = ['entry' => $entry, 'columns' => $columns, 'rows' => $counts[$table], 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
                if (! $output->addFile($path, $entry)) {
                    throw new RuntimeException('staging_unavailable');
                }
            }
            foreach ($backup['files'] as $index => $file) {
                $relative = substr($file['entry'], strlen('files/storage/'));
                $mapped = $this->filePath($relative);
                // Even deliberately excluded runtime files must pass checksum verification.
                $path = $directory.'/file-'.$index;
                $this->copy($input, $file, $path);
                if ($mapped !== null) {
                    if (str_starts_with($mapped, 'files/private/standalone-history/')) {
                        $manifest['preserved_history_files']++;
                    }
                    $manifest['files'][$mapped] = ['bytes' => $file['size_bytes'], 'sha256' => $file['sha256']];
                    if (! $output->addFile($path, $mapped)) {
                        throw new RuntimeException('staging_unavailable');
                    }
                } else {
                    unlink($path);
                }
                $heartbeat && $heartbeat();
            }
            if (! $output->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) || ! $output->close()) {
                throw new RuntimeException('staging_unavailable');
            }
            $outputOpen = false;
            chmod($destination, 0600);
        } catch (\Throwable $exception) {
            if ($outputOpen) {
                $output->close();
                $outputOpen = false;
            }
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $exception;
        } finally {
            foreach ($streams as $stream) {
                fclose($stream);
            }
            if ($inputOpen) {
                $input->close();
            }
            File::deleteDirectory($directory);
        }
    }

    private function inventory(ZipArchive $zip): array
    {
        $stat = $zip->statName('manifest.json');
        if (! $stat || $stat['size'] > config('standalone-import.maximum_manifest_bytes')) {
            throw new RuntimeException('invalid_package');
        }
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $version = $manifest['version'] ?? 0;
        if (! in_array($version, [1, 2, 3], true) || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('unsupported_package');
        }
        if ($version === 3 && ($manifest['scope'] ?? '') !== 'full') {
            throw new RuntimeException('full_backup_required');
        }
        if (! is_array($manifest['database'] ?? null)) {
            throw new RuntimeException('invalid_inventory');
        }
        if ($version >= 2 && ($manifest['data_roots'] ?? []) !== ['storage']) {
            throw new RuntimeException('unsupported_backup_roots');
        }
        if (! in_array($manifest['database']['driver'] ?? '', ['mysql', 'mariadb'], true) || ($manifest['database']['entry'] ?? '') !== 'database/database.sql') {
            throw new RuntimeException('unsupported_database');
        }
        if (! is_string($manifest['created_at'] ?? null) || strtotime($manifest['created_at']) === false) {
            throw new RuntimeException('invalid_inventory');
        }
        if ($version >= 2) {
            $tables = $manifest['database']['tables'] ?? [];
            if (! is_array($tables) || count($tables) !== ($manifest['database']['table_count'] ?? -1)) {
                throw new RuntimeException('unsupported_schema');
            }
            sort($tables, SORT_STRING);
            if ($tables !== array_keys($this->contract->schema()['tables'])) {
                throw new RuntimeException('unsupported_schema');
            }
        }
        $entries = ['manifest.json' => ['size_bytes' => $stat['size']], 'database/database.sql' => $manifest['database']];
        $destinations = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['entry'] ?? null)) {
                throw new RuntimeException('invalid_inventory');
            }
            $entry = $this->contract->path($file['entry']);
            if (! str_starts_with($entry, 'files/storage/') || isset($entries[$entry])) {
                throw new RuntimeException('unsupported_backup_roots');
            }
            $destination = $this->filePath(substr($entry, strlen('files/storage/')));
            if ($destination !== null) {
                if (isset($destinations[$destination])) {
                    throw new RuntimeException('invalid_inventory');
                }
                $destinations[$destination] = true;
            }
            $entries[$entry] = $file;
        }
        if (count($entries) !== $zip->numFiles || count($entries) > config('standalone-import.maximum_entries')) {
            throw new RuntimeException('invalid_inventory');
        }
        $seen = [];
        $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $this->contract->path($stat['name']);
            $entry = $entries[$name] ?? null;
            $zip->getExternalAttributesIndex($index, $os, $attributes);
            if (isset($seen[$name]) || ! $entry || ! is_int($entry['size_bytes'] ?? null) || $entry['size_bytes'] < 0
                || $stat['size'] !== $entry['size_bytes'] || (($attributes >> 16) & 0170000) === 0120000
                || ($name !== 'manifest.json' && ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? ''))) {
                throw new RuntimeException('invalid_inventory');
            }
            $seen[$name] = true;
            $expanded += $stat['size'];
            if ($expanded > config('standalone-import.maximum_expanded_bytes')) {
                throw new RuntimeException('package_too_large');
            }
        }

        return $manifest;
    }

    private function filePath(string $relative): ?string
    {
        $this->contract->path($relative);
        if (preg_match('#^(public|private)/(.+)$#', $relative, $match)) {
            if (preg_match('#^(?:tenants)(?:/|$)#i', $match[2])) {
                throw new RuntimeException('unsupported_backup_roots');
            }
            if ($match[2] === '.gitignore' || preg_match('#^(?:livewire-tmp|backups|saas-backups|mpdf|framework)(?:/|$)#', $match[2])) {
                return null;
            }

            return 'files/'.$relative;
        }
        // V1 tools save import/export reports and pre-reset snapshots beside the disks.
        // Keep these as private historical files; never execute or publish them.
        if (preg_match('#^(?:legacy-access-export(?:-[a-z0-9_-]+)?|legacy-memorization-import(?:-[a-z0-9_-]+)?|legacy-quran-(?:ajza(?:-import)?|pages|import)(?:-[a-z0-9_-]+)?|reset-backups)/.+$#D', $relative)) {
            return 'files/private/standalone-history/'.$relative;
        }
        if (in_array($relative, ['.gitignore', 'tenant-transfer-source-id', 'codebase-search.sqlite'], true)
            || preg_match('#^(?:backup-tmp|backups|mpdf|tenant-transfer-export|tenant-transfer-exports)(?:/|$)#', $relative)) {
            return null;
        }
        throw new RuntimeException('unsupported_backup_roots');
    }

    private function copy(ZipArchive $zip, array $entry, string $destination): void
    {
        $input = $zip->getStream($entry['entry']);
        $output = fopen($destination, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('invalid_inventory');
        }
        try {
            $bytes = stream_copy_to_stream($input, $output, $entry['size_bytes'] + 1);
        } finally {
            fclose($input);
            fclose($output);
        }
        chmod($destination, 0600);
        if ($bytes !== $entry['size_bytes'] || ! hash_equals($entry['sha256'], hash_file('sha256', $destination))) {
            throw new RuntimeException('checksum_mismatch');
        }
    }
}
