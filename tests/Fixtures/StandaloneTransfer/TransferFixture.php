<?php

namespace Tests\Fixtures\StandaloneTransfer;

use App\Services\BackupEncryptionService;
use App\Services\StandaloneTransfer\TransferContract;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ZipArchive;

class TransferFixture
{
    public static function package(string $directory, string $secret, array $overrides = []): string
    {
        $contract = app(TransferContract::class);
        $zip = new ZipArchive;
        $plain = $directory.'/fixture-'.Str::uuid().'.zip';
        $encrypted = $plain.'.alkhair-transfer';
        $zip->open($plain, ZipArchive::CREATE | ZipArchive::EXCL);
        $manifest = [
            'format' => 'alkhair-standalone-transfer', 'version' => 1,
            'source_ref' => $contract->schema()['source_ref'], 'source_id' => (string) Str::uuid(),
            'export_id' => (string) Str::uuid(), 'engine' => 'mysql', 'engine_version' => '8.0.46',
            'snapshot_at' => '2026-10-24T12:00:00+03:00', 'source_url' => 'https://source.example.test',
            'tables' => [], 'files' => [], 'excluded_tables' => TransferContract::EXCLUDED,
        ];
        $data = [
            'users' => [['id' => '41', 'name' => 'Source Administrator', 'username' => 'source-admin', 'email' => 'source@example.test', 'is_active' => '1', 'password' => Hash::make('source-password'), 'issued_password' => 'source-issued-password']],
            'roles' => [['id' => '91', 'name' => 'admin', 'guard_name' => 'web', 'level' => '0']],
            'model_has_roles' => [['role_id' => '91', 'model_type' => 'App\\Models\\User', 'model_id' => '41']],
            'app_settings' => [['id' => '1', 'group' => 'general', 'key' => 'school_name', 'value' => 'مدرسة المصدر', 'type' => 'string']],
        ];
        $data = array_replace($data, $overrides);
        foreach ($contract->tables() as $table => $columns) {
            $lines = '';
            foreach ($data[$table] ?? [] as $row) {
                $lines .= $contract->encode(array_replace(array_fill_keys($columns, null), $row));
            }
            $entry = 'tables/'.$table.'.jsonl';
            $zip->addFromString($entry, $lines);
            $manifest['tables'][$table] = ['entry' => $entry, 'columns' => $columns, 'rows' => count($data[$table] ?? []), 'bytes' => strlen($lines), 'sha256' => hash('sha256', $lines)];
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
        app(BackupEncryptionService::class)->encrypt($plain, $encrypted, $secret);
        unlink($plain);

        return $encrypted;
    }
}
