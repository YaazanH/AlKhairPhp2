<?php

namespace Tests\Fixtures\StandaloneTransfer;

use App\Services\BackupEncryptionService;
use App\Services\StandaloneTransfer\TransferContract;
use App\Services\StandaloneTransfer\TransferPackage;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use ZipArchive;

class SettingsBackupFixture
{
    public static function package(string $directory, string $key, array $rows = [], array $manifestOverrides = [], string $extraSql = '', array $files = [], string $preamble = ''): string
    {
        $contract = app(TransferContract::class);
        $transfers = app(TransferPackage::class);
        $transfer = TransferFixture::package($directory, $key, $rows);
        $prefix = $directory.'/settings-'.Str::uuid();
        app(BackupEncryptionService::class)->decrypt($transfer, $prefix.'-transfer.zip', $key);
        $input = $transfers->open($prefix.'-transfer.zip');
        $inventory = $transfers->manifest($input);
        $encrypter = new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC');
        $sql = fopen($prefix.'.sql', 'xb');
        fwrite($sql, $preamble);
        foreach ($contract->schema()['tables'] as $name => $columns) {
            fwrite($sql, 'DROP TABLE IF EXISTS `'.$name."`;\nCREATE TABLE `".$name.'` ('.implode(',', array_map(fn ($column) => '`'.$column.'` text', $columns)).");\n");
            if ($name === 'migrations') {
                $data = array_map(fn ($migration, $index) => ['id' => (string) ($index + 1), 'migration' => $migration, 'batch' => '1'], array_keys($contract->schema()['migrations']), array_keys(array_keys($contract->schema()['migrations'])));
            } elseif (isset($inventory['tables'][$name])) {
                $data = $transfers->rows($input, $inventory['tables'][$name]);
            } else {
                $data = [];
            }
            foreach ($data as $row) {
                if ($name === 'users') {
                    $row['remember_token'] = 'source-token-to-discard';
                    if (filled($row['issued_password'] ?? null)) {
                        $row['issued_password'] = $encrypter->encryptString($row['issued_password']);
                    }
                }
                $values = array_map(fn ($column) => self::literal($row[$column]), $columns);
                fwrite($sql, 'INSERT INTO `'.$name.'` VALUES ('.implode(',', $values).");\n");
            }
        }
        fwrite($sql, $extraSql);
        fclose($sql);
        $input->close();
        $zip = new ZipArchive;
        $zip->open($prefix.'.zip', ZipArchive::CREATE | ZipArchive::EXCL);
        $zip->addFile($prefix.'.sql', 'database/database.sql');
        $tables = array_keys($contract->schema()['tables']);
        $manifest = ['version' => 3, 'scope' => 'full', 'application' => 'AlKhair', 'created_at' => '2026-10-25T10:00:00+03:00', 'data_roots' => ['storage'], 'database' => ['driver' => 'mysql', 'entry' => 'database/database.sql', 'size_bytes' => filesize($prefix.'.sql'), 'sha256' => hash_file('sha256', $prefix.'.sql'), 'tables' => $tables, 'table_count' => count($tables)], 'files' => []];
        foreach ($files as $path => $contents) {
            $entry = 'files/storage/'.$path;
            $zip->addFromString($entry, $contents);
            $manifest['files'][] = ['entry' => $entry, 'size_bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        $zip->addFromString('manifest.json', json_encode(array_replace($manifest, $manifestOverrides), JSON_THROW_ON_ERROR));
        $zip->close();
        app(BackupEncryptionService::class)->encrypt($prefix.'.zip', $prefix.'.alkhair-backup', $key);

        return $prefix.'.alkhair-backup';
    }

    private static function literal(?string $value): string
    {
        return $value === null ? 'NULL' : "'".strtr($value, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z'])."'";
    }
}
