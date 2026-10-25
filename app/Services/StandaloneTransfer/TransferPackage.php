<?php

namespace App\Services\StandaloneTransfer;

use App\Exceptions\TenantImportMissingFilesException;
use Generator;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class TransferPackage
{
    public function __construct(private TransferContract $contract) {}

    public function inspect(string $path, ?callable $progress = null, array $allowedMissingFiles = []): array
    {
        $zip = $this->open($path);
        try {
            $manifest = $this->manifest($zip);
            $entries = ['manifest.json' => ['bytes' => $zip->statName('manifest.json')['size']]];
            foreach ($manifest['tables'] as $table) {
                $entries[$table['entry']] = $table;
            }
            $entries += $manifest['files'];
            if (count($entries) !== $zip->numFiles || count($entries) > config('standalone-import.maximum_entries')) {
                throw new RuntimeException('invalid_inventory');
            }
            $expanded = 0;
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $this->contract->path($stat['name']);
                $zip->getExternalAttributesIndex($index, $os, $attributes);
                if (isset($seen[$name]) || ! isset($entries[$name]) || (($attributes >> 16) & 0170000) === 0120000
                    || $stat['size'] !== $entries[$name]['bytes']) {
                    throw new RuntimeException('invalid_inventory');
                }
                $seen[$name] = true;
                $expanded += $stat['size'];
                if ($expanded > config('standalone-import.maximum_expanded_bytes')) {
                    throw new RuntimeException('package_too_large');
                }
                if ($name !== 'manifest.json') {
                    $stream = $zip->getStream($name);
                    if (! is_resource($stream)) {
                        throw new RuntimeException('invalid_inventory');
                    }
                    try {
                        $hash = hash_init('sha256');
                        $bytes = hash_update_stream($hash, $stream);
                        if ($bytes !== $stat['size'] || ! hash_equals($entries[$name]['sha256'], hash_final($hash))) {
                            throw new RuntimeException('checksum_mismatch');
                        }
                    } finally {
                        fclose($stream);
                    }
                }
                $progress && $progress($index + 1, $zip->numFiles);
            }
            $counts = [];
            $requiredPaths = [];
            foreach ($manifest['tables'] as $name => $metadata) {
                $count = 0;
                foreach ($this->rows($zip, $metadata) as $row) {
                    $count++;
                    foreach ($row as $column => $value) {
                        if (filled($value) && (str_ends_with($column, '_path') || in_array($column, ['background_image', 'logo_image'], true) || $name.'.'.$column === 'finance_request_attachments.path')) {
                            $scope = in_array($name.'.'.$column, ['curriculum_resources.pdf_path', 'finance_generated_reports.pdf_path'], true) ? 'private' : 'public';
                            $requiredPaths['files/'.$scope.'/'.$this->contract->path($value)] = true;
                        }
                    }
                }
                if ($count !== $metadata['rows']) {
                    throw new RuntimeException('row_count_mismatch');
                }
                $counts[$name] = $count;
            }
            $missing = array_values(array_diff(array_keys($requiredPaths), array_keys($manifest['files'])));
            if (array_diff($missing, $allowedMissingFiles) !== []) {
                throw new TenantImportMissingFilesException(array_slice($missing, 0, 100), count($missing));
            }
            $roles = [];
            $parentRoles = [];
            foreach ($this->rows($zip, $manifest['tables']['roles']) as $row) {
                if ($row['guard_name'] === 'web' && in_array($row['name'], ['admin', 'super_admin'], true)) {
                    $roles[] = $row['id'];
                }
                if ($row['guard_name'] === 'web' && $row['name'] === 'parent') {
                    $parentRoles[] = $row['id'];
                }
            }
            $ownerIds = [];
            $parentIds = [];
            foreach ($this->rows($zip, $manifest['tables']['model_has_roles']) as $row) {
                if ($row['model_type'] === 'App\\Models\\User' && in_array($row['role_id'], $roles, true)) {
                    $ownerIds[$row['model_id']] = true;
                }
                if ($row['model_type'] === 'App\\Models\\User' && in_array($row['role_id'], $parentRoles, true)) {
                    $parentIds[$row['model_id']] = true;
                }
            }
            $owners = [];
            $parentPortalUsed = false;
            foreach ($this->rows($zip, $manifest['tables']['users']) as $row) {
                $algorithm = password_get_info($row['password'])['algoName'];
                $supported = match (config('hashing.driver', 'bcrypt')) {
                    'bcrypt' => 'bcrypt', 'argon' => 'argon2i', 'argon2id' => 'argon2id', default => null,
                };
                if ($algorithm !== $supported || ! in_array($algorithm, password_algos(), true) && $algorithm !== 'bcrypt') {
                    throw new RuntimeException('unsupported_password_hash');
                }
                if (isset($ownerIds[$row['id']]) && $row['is_active'] === '1' && filled($row['username'])) {
                    $owners[] = array_intersect_key($row, array_flip(['id', 'name', 'username', 'email']));
                }
                $parentPortalUsed = $parentPortalUsed || (isset($parentIds[$row['id']]) && $row['is_active'] === '1');
                if (count($owners) > 1000) {
                    throw new RuntimeException('invalid_inventory');
                }
            }
            if (! $owners) {
                throw new RuntimeException('no_eligible_owner');
            }
            $required = $this->contract->requiredModules($counts);
            if ($parentPortalUsed) {
                $required['parent_portal'] = ['active_parent_login'];
            }
            $settings = [];
            foreach ($this->rows($zip, $manifest['tables']['app_settings']) as $row) {
                $settings[$row['group'].'.'.$row['key']] = $row['value'];
            }
            if (isset($settings['learning_progression.profile']) && $settings['learning_progression.profile'] !== 'quran') {
                throw new RuntimeException('unsupported_learning_path');
            }
            foreach (['general.memorization_saber_entries_enabled' => ['memorization', 'quran_tests'], 'general.activity_entries_enabled' => ['activities']] as $key => $modules) {
                if (filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOL)) {
                    foreach ($modules as $module) {
                        $required[$module][] = 'setting:'.$key;
                    }
                }
            }

            return [
                'source_id' => $manifest['source_id'], 'export_id' => $manifest['export_id'],
                'source_url' => $manifest['source_url'], 'source_identity' => $manifest['source_identity'] ?? null,
                'source_ref' => $manifest['source_ref'], 'snapshot_at' => $manifest['snapshot_at'],
                'source_name' => $settings['general.school_name'] ?? $settings['app.school_name'] ?? '',
                'locale' => $settings['general.default_locale'] ?? $manifest['locale'] ?? 'ar',
                'timezone' => $settings['general.school_timezone'] ?? $settings['app.timezone'] ?? $manifest['timezone'] ?? config('app.timezone'),
                'engine' => $manifest['engine'], 'engine_version' => $manifest['engine_version'],
                'learning_path' => 'quran', 'counts' => $counts, 'required_modules' => $required,
                'owners' => $owners, 'file_count' => count($manifest['files']),
                'file_bytes' => array_sum(array_column($manifest['files'], 'bytes')),
                'expanded_bytes' => $expanded,
                'preserved_history_files' => $manifest['preserved_history_files'] ?? 0,
                'missing_files' => $missing,
            ];
        } finally {
            $zip->close();
        }
    }

    public function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles > config('standalone-import.maximum_entries')) {
            throw new RuntimeException('invalid_package');
        }

        return $zip;
    }

    public function manifest(ZipArchive $zip): array
    {
        $stat = $zip->statName('manifest.json');
        if (! $stat || $stat['size'] > config('standalone-import.maximum_manifest_bytes')) {
            throw new RuntimeException('invalid_package');
        }
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 'alkhair-standalone-transfer' || ($manifest['version'] ?? null) !== 1
            || ($manifest['source_ref'] ?? null) !== $this->contract->schema()['source_ref']
            || ! Str::isUuid($manifest['source_id'] ?? '') || ! Str::isUuid($manifest['export_id'] ?? '')
            || ! in_array($manifest['engine'] ?? '', app()->environment('testing') ? ['mysql', 'mariadb', 'sqlite'] : ['mysql', 'mariadb'], true)
            || ! is_array($manifest['tables'] ?? null) || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('unsupported_package');
        }
        if (! is_string($manifest['source_url'] ?? null) || ! filter_var($manifest['source_url'], FILTER_VALIDATE_URL)
            || ! in_array(parse_url($manifest['source_url'], PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string($manifest['snapshot_at'] ?? null) || strtotime($manifest['snapshot_at']) === false
            || ! is_string($manifest['engine_version'] ?? null) || strlen($manifest['engine_version']) > 255) {
            throw new RuntimeException('invalid_inventory');
        }
        $tables = array_keys($manifest['tables']);
        sort($tables);
        if ($tables !== array_keys($this->contract->tables())) {
            throw new RuntimeException('unsupported_schema');
        }
        foreach ($this->contract->tables() as $name => $columns) {
            $table = $manifest['tables'][$name];
            if (($table['entry'] ?? null) !== 'tables/'.$name.'.jsonl' || ($table['columns'] ?? null) !== $columns
                || ! is_int($table['rows'] ?? null) || $table['rows'] < 0) {
                throw new RuntimeException('unsupported_schema');
            }
        }
        foreach (array_merge(array_values($manifest['tables']), array_values($manifest['files'])) as $entry) {
            if (! is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0 || ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')) {
                throw new RuntimeException('invalid_inventory');
            }
        }
        foreach (array_keys($manifest['files']) as $path) {
            $this->contract->path($path);
            if (! preg_match('#^files/(public|private)/.+#', $path)) {
                throw new RuntimeException('unsafe_path');
            }
        }
        if (isset($manifest['preserved_history_files']) && (! is_int($manifest['preserved_history_files'])
            || $manifest['preserved_history_files'] < 0 || $manifest['preserved_history_files'] > count($manifest['files']))) {
            throw new RuntimeException('invalid_inventory');
        }
        if (isset($manifest['source_identity'])) {
            $identity = $manifest['source_identity'];
            if (! is_array($identity) || ! in_array($identity['method'] ?? '', ['key-and-url-v2', 'source-id-file-v1'], true)) {
                throw new RuntimeException('invalid_inventory');
            }
            if ($identity['method'] === 'key-and-url-v2') {
                $identities = app(SettingsBackupIdentity::class);
                if (! Str::isUuid($identity['legacy_source_id'] ?? '') || ($identity['location'] ?? '') !== $identities->location($manifest['source_url'])
                    || $manifest['source_id'] !== $identities->sourceId($identity['legacy_source_id'], $manifest['source_url'])) {
                    throw new RuntimeException('invalid_inventory');
                }
            }
        }

        return $manifest;
    }

    public function rows(ZipArchive $zip, array $metadata): Generator
    {
        $stream = $zip->getStream($metadata['entry']);
        if (! is_resource($stream)) {
            throw new RuntimeException('invalid_inventory');
        }
        try {
            while (($line = fgets($stream, config('standalone-import.maximum_row_bytes') + 1)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('invalid_row');
                }
                yield $this->contract->decode($line, $metadata['columns']);
            }
            if (! feof($stream)) {
                throw new RuntimeException('invalid_row');
            }
        } finally {
            fclose($stream);
        }
    }
}
