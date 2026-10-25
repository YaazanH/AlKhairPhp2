<?php

namespace App\Services\Landlord;

use App\Exceptions\TenantImportMissingFilesException;
use App\Jobs\ProcessTenantImport;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Models\Landlord\TenantFeatureOverride;
use App\Models\Landlord\TenantImport;
use App\Services\BackupEncryptionService;
use App\Services\StandaloneTransfer\SettingsBackupTransfer;
use App\Services\StandaloneTransfer\TransferContract;
use App\Services\StandaloneTransfer\TransferPackage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantImportService
{
    public function __construct(
        private TenantImportFiles $files,
        private BackupEncryptionService $encryption,
        private TransferPackage $packages,
        private TransferContract $contract,
        private ModuleRegistry $modules,
        private TenantImportDatabase $databases,
        private TenantScopedContext $context,
    ) {}

    public function secure(TenantImport $operation, #[SensitiveParameter] string $secret, ?string $sourceUrl = null): void
    {
        $this->files->locked($operation, function () use ($operation, $secret, $sourceUrl): void {
            $operation->refresh();
            abort_unless($operation->status === 'uploading' && $operation->uploaded_bytes === $operation->expected_bytes, 409);
            ignore_user_abort(true);
            set_time_limit(config('standalone-import.timeout'));
            if (disk_free_space(dirname($this->files->path($operation, 'upload.bin'))) < $operation->expected_bytes * 2 + 256 * 1024 * 1024) {
                throw ValidationException::withMessages(['import' => __('tenant-import.errors.insufficient_scratch_space')]);
            }
            $operation->update(['status' => 'securing', 'phase' => 'securing', 'error_code' => null, 'heartbeat_at' => now()]);
            $plain = $this->files->path($operation, 'working.zip');
            $chunkSize = config('backups.encryption_chunk_size');
            config(['backups.encryption_chunk_size' => 1024 * 1024]);
            $unlocked = false;
            try {
                $this->encryption->decrypt($this->files->path($operation, 'upload.bin'), $plain, $secret);
                $unlocked = true;
                chmod($plain, 0600);
                $normalized = $plain;
                if ($operation->isSettingsBackup()) {
                    if (! filter_var($sourceUrl, FILTER_VALIDATE_URL) || ! in_array(parse_url($sourceUrl, PHP_URL_SCHEME), ['http', 'https'], true)
                        || parse_url($sourceUrl, PHP_URL_USER) !== null || parse_url($sourceUrl, PHP_URL_PASS) !== null) {
                        throw new RuntimeException('invalid_source_url');
                    }
                    $normalized = $this->files->path($operation, 'converted.zip');
                    app(SettingsBackupTransfer::class)->convert($plain, $normalized, $secret, $sourceUrl, function () use ($operation): void {
                        $operation->update(['heartbeat_at' => now()]);
                    });
                }
                $encrypted = $this->encryption->encrypt($normalized, $this->files->path($operation, 'package.enc'));
                $operation->update(['status' => 'validating', 'phase' => 'inventory', 'archive_sha256' => $encrypted['sha256'], 'heartbeat_at' => now()]);
                ProcessTenantImport::dispatch($operation->uuid, 'validate')->onConnection('tenant-imports')->onQueue('tenant-imports');
                unlink($this->files->path($operation, 'upload.bin'));
            } catch (\Throwable $exception) {
                // No exception text, transfer code, row, or SQL is persisted or logged.
                $code = match (true) {
                    $exception->getMessage() === 'backup_sodium_unavailable' => 'backup_sodium_unavailable',
                    $unlocked && $operation->isSettingsBackup() => $this->safeCode($exception),
                    $operation->isSettingsBackup() => 'cannot_unlock_backup',
                    default => 'cannot_unlock',
                };
                $operation->update(['status' => 'uploading', 'phase' => null, 'error_code' => $code]);
                $encrypted = $this->files->path($operation, 'package.enc');
                if (is_file($encrypted)) {
                    unlink($encrypted);
                }
                throw ValidationException::withMessages([$operation->isSettingsBackup() ? 'source_application_key' : 'transfer_code' => __('tenant-import.errors.'.$code)]);
            } finally {
                config(['backups.encryption_chunk_size' => $chunkSize]);
                $this->files->clearPlaintext($operation);
            }
        });
    }

    public function process(TenantImport $operation, string $phase): void
    {
        $this->files->locked($operation, function () use ($operation, $phase): void {
            $operation->refresh();
            if (! in_array($operation->status, $phase === 'validate' ? ['validating'] : ['queued'], true)) {
                return;
            }
            $actor = PlatformAdministrator::find($operation->platform_administrator_id);
            if (! $actor?->is_active || ! $actor->hasPlatformPermission('manage.tenants')) {
                $operation->update(['status' => 'failed', 'error_code' => 'permission_revoked']);

                return;
            }
            $path = $this->files->path($operation, 'working.zip');
            $chunkSize = config('backups.encryption_chunk_size');
            config(['backups.encryption_chunk_size' => 1024 * 1024]);
            try {
                $encrypted = $this->files->path($operation, 'package.enc');
                if (! is_file($encrypted) || ! hash_equals($operation->archive_sha256, hash_file('sha256', $encrypted))) {
                    throw new RuntimeException('checksum_mismatch');
                }
                $this->encryption->decrypt($encrypted, $path);
                chmod($path, 0600);
                if ($phase === 'validate') {
                    $summary = $this->packages->inspect($path, function ($done, $total) use ($operation): void {
                        if ($done === $total || $done % 20 === 0) {
                            $operation->update(['progress' => (int) floor(100 * $done / max(1, $total)), 'heartbeat_at' => now()]);
                        }
                    }, hash_equals($operation->archive_sha256, $operation->report['missing_files_archive_sha256'] ?? '') ? ($operation->report['allowed_missing_files'] ?? []) : []);
                    if (TenantImport::where('source_id', $summary['source_id'])->whereKeyNot($operation->id)->exists()
                        || TenantImport::where('export_id', $summary['export_id'])->whereKeyNot($operation->id)->exists()) {
                        throw new RuntimeException('duplicate_source');
                    }
                    $operation->update(['source_id' => $summary['source_id'], 'export_id' => $summary['export_id'], 'summary' => $summary, 'status' => 'preview', 'phase' => 'validated', 'progress' => 100, 'error_code' => null]);
                    $this->audit($operation, 'tenant_import_validated');
                } else {
                    $this->prepare($operation, $path);
                }
            } catch (\Throwable $exception) {
                if ($exception instanceof TenantImportMissingFilesException) {
                    $operation->update(['report' => ['missing_files' => $exception->files, 'missing_file_count' => $exception->total]]);
                }
                $this->fail($operation, $this->safeCode($exception));
            } finally {
                config(['backups.encryption_chunk_size' => $chunkSize]);
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });
    }

    public function coverage(TenantImport $operation, array $selection): array
    {
        $plan = Plan::with('features')->where('is_active', true)->find($selection['plan_id'] ?? null);
        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => __('tenant-import.errors.package_unavailable')]);
        }
        $package = $plan->features->pluck('code')->all();
        $extras = $selection['extras'] ?? [];
        $unavailable = Feature::where('is_active', false)->pluck('code')->all();
        try {
            $effective = array_keys($this->modules->resolve(
                $this->modules->expandLegacy($package, in_array('core', $package, true)),
                $extras, $this->modules->expandLegacy($unavailable, in_array('core', $package, true)),
            ));
        } catch (\DomainException) {
            throw ValidationException::withMessages(['extras' => __('tenant-import.errors.package_unavailable')]);
        }
        $required = array_keys($operation->summary['required_modules']);

        return ['required' => $required, 'effective' => $effective, 'missing' => array_values(array_diff($required, $effective)), 'plan' => $plan];
    }

    public function missingFilesSignature(TenantImport $operation): string
    {
        return hash('sha256', json_encode([$operation->uuid, $operation->archive_sha256, $operation->report['missing_files'] ?? [], $operation->report['missing_file_count'] ?? 0], JSON_THROW_ON_ERROR));
    }

    public function allowMissingFiles(TenantImport $operation, string $signature, PlatformAdministrator $actor): void
    {
        $this->files->locked($operation, function () use ($operation, $signature, $actor): void {
            $operation->refresh();
            abort_unless($actor->is_active && $actor->hasPlatformPermission('manage.tenants')
                && ($actor->isPlatformOwner() || $actor->id === $operation->platform_administrator_id), 403);
            abort_unless($operation->status === 'failed' && $operation->error_code === 'missing_file' && $operation->tenant_id === null, 409);
            $paths = $operation->report['missing_files'] ?? [];
            if ($paths === [] || count($paths) !== ($operation->report['missing_file_count'] ?? 0)
                || ! hash_equals($this->missingFilesSignature($operation), $signature)) {
                throw ValidationException::withMessages(['import' => __('tenant-import.errors.stale_preview')]);
            }
            $operation->update([
                'report' => array_merge($operation->report, [
                    'allowed_missing_files' => $paths, 'missing_files_archive_sha256' => $operation->archive_sha256,
                    'missing_files_approved_by' => $actor->id, 'missing_files_approved_at' => now()->toIso8601String(),
                ]),
                'status' => 'validating', 'phase' => 'inventory', 'progress' => 0, 'error_code' => null,
                'cleanup_status' => null, 'heartbeat_at' => now(),
            ]);
            $this->audit($operation, 'tenant_import_missing_files_accepted', ['file_count' => count($paths)]);
            ProcessTenantImport::dispatch($operation->uuid, 'validate')->onConnection('tenant-imports')->onQueue('tenant-imports');
        });
    }

    public function signature(TenantImport $operation, array $selection): string
    {
        $coverage = $this->coverage($operation, $selection);

        return hash('sha256', json_encode($this->canonical([
            $operation->archive_sha256, $selection, $coverage['effective'], $coverage['missing'],
            $operation->report['allowed_missing_files'] ?? [], $operation->report['missing_files_archive_sha256'] ?? null,
            $coverage['plan']->getAttributes(), $this->modules->definitions(),
            Feature::orderBy('code')->pluck('is_active', 'code')->all(),
            hash_file('sha256', __FILE__), $this->contract->schema(),
            $operation->tenant?->only(['uuid', 'name', 'slug', 'database_name', 'locale', 'timezone', 'learning_path_type', 'storage_limit_bytes']),
        ]), JSON_THROW_ON_ERROR));
    }

    private function canonical(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public function select(TenantImport $operation, array $selection): void
    {
        $this->files->locked($operation, function () use ($operation, $selection): void {
            $operation->refresh();
            abort_unless(in_array($operation->status, ['preview', 'ready'], true), 409);
            if ($operation->status === 'ready') {
                $selection = array_replace($operation->selection, array_intersect_key($selection, array_flip(['plan_id', 'extras', 'storage_limit_bytes'])));
            }
            $coverage = $this->coverage($operation, $selection);
            if (! in_array((string) $selection['owner_id'], array_column($operation->summary['owners'], 'id'), true)) {
                throw ValidationException::withMessages(['owner_id' => __('tenant-import.errors.no_eligible_owner')]);
            }
            if ($selection['storage_limit_bytes'] < $operation->summary['expanded_bytes']) {
                throw ValidationException::withMessages(['storage_limit_gb' => __('tenant-import.errors.storage_limit')]);
            }
            if ($operation->status === 'ready') {
                $operation->tenant->update(['storage_limit_bytes' => $selection['storage_limit_bytes']]);
            }
            $operation->update(['selection' => $selection, 'preview_signature' => $this->signature($operation, $selection)]);
        });
    }

    public function enqueue(TenantImport $operation, string $signature): void
    {
        $this->files->locked($operation, function () use ($operation, $signature): void {
            DB::connection('landlord')->transaction(function () use ($operation, $signature): void {
                $operation->refresh();
                abort_unless($operation->status === 'preview', 409);
                $this->assertPreview($operation, $signature);
                $operation->update(['status' => 'queued', 'phase' => 'waiting', 'progress' => 0, 'heartbeat_at' => now()]);
                ProcessTenantImport::dispatch($operation->uuid, 'prepare')->onConnection('tenant-imports')->onQueue('tenant-imports');
                $this->audit($operation, 'tenant_import_requested');
            });
        });
    }

    public function activate(TenantImport $operation, string $signature, PlatformAdministrator $actor, array $billing): void
    {
        $this->files->locked($operation, function () use ($operation, $signature, $actor, $billing): void {
            DB::connection('landlord')->transaction(function () use ($operation, $signature, $actor, $billing): void {
                $operation->refresh();
                abort_unless($operation->status === 'ready', 409);
                $this->assertPreview($operation, $signature);
                $tenant = Tenant::lockForUpdate()->findOrFail($operation->tenant_id);
                if ($tenant->status !== Tenant::STATUS_DRAFT || ! $tenant->domains()->where('host', $tenant->slug.'.'.config('tenancy.base_domain'))->exists()) {
                    throw ValidationException::withMessages(['import' => __('tenant-import.errors.stale_preview')]);
                }
                if (app(TenantStorageUsage::class)->for($tenant)['total'] > $tenant->storage_limit_bytes) {
                    throw ValidationException::withMessages(['import' => __('tenant-import.errors.storage_limit')]);
                }
                $plan = $this->coverage($operation, $operation->selection)['plan'];
                $this->extras($operation, $tenant);
                // This state and the billing/tenant change commit together; other activation paths are blocked.
                $operation->update(['status' => 'completed', 'phase' => 'completed']);
                app(SubscriptionBillingService::class)->activatePackage(
                    $tenant, $plan, null, Carbon::parse($billing['starts_at']), false, $actor, null,
                    $billing['subscription_status'] === 'trial', (int) ($billing['trial_days'] ?? 0),
                );
                $this->context->run($tenant, function () use ($tenant, $operation): void {
                    app(TenantSetupManager::class)->initialiseImportedTenant($tenant, array_keys($operation->summary['required_modules']));
                });
                $this->audit($operation, 'tenant_import_activated');
            });
            try {
                $this->files->clear($operation);
                $operation->update(['cleanup_status' => 'completed']);
            } catch (\Throwable) {
                $operation->update(['cleanup_status' => 'staging_pending']);
            }
        });
    }

    private function prepare(TenantImport $operation, string $path): void
    {
        $this->assertPreview($operation, $operation->preview_signature);
        $data = $operation->selection;
        $tenant = DB::connection('landlord')->transaction(function () use ($operation, $data): Tenant {
            $tenant = Tenant::create([
                'uuid' => (string) Str::uuid(), 'name' => $data['name'], 'slug' => $data['slug'],
                'status' => Tenant::STATUS_PROVISIONING, 'locale' => $data['locale'], 'timezone' => $data['timezone'],
                'learning_path_type' => $operation->summary['learning_path'], 'storage_limit_bytes' => $data['storage_limit_bytes'],
            ]);
            TenantDomain::create(['tenant_id' => $tenant->id, 'host' => $tenant->slug.'.'.config('tenancy.base_domain'), 'is_primary' => true]);
            $operation->update(['tenant_id' => $tenant->id, 'status' => 'importing', 'phase' => 'database', 'heartbeat_at' => now()]);

            return $tenant;
        });
        $operation->update(['database_allocation_started' => true]);
        $database = $this->databases->create($tenant);
        $operation->update(['database_owned' => true, 'heartbeat_at' => now()]);
        $tenant->update(['database_name' => $database]);
        $root = 'tenants/'.strtolower($tenant->uuid);
        foreach (['public' => 'public_storage_owned', 'local' => 'private_storage_owned'] as $disk => $flag) {
            if (Storage::disk($disk)->exists($root)) {
                throw new RuntimeException('resource_exists');
            }
            if (! Storage::disk($disk)->makeDirectory($root)) {
                throw new RuntimeException('staging_unavailable');
            }
            $operation->update([$flag => true]);
        }
        $this->context->run($tenant, function () use ($operation, $path): void {
            $previousKey = config('permission.cache.key');
            config(['permission.cache.key' => 'tenant-import.'.$operation->uuid]);
            app(PermissionRegistrar::class)->initializeCache();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            try {
                $this->restore($operation, $path);
            } finally {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                config(['permission.cache.key' => $previousKey]);
                app(PermissionRegistrar::class)->initializeCache();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
        $tenant->update(['status' => Tenant::STATUS_DRAFT]);
        $operation->unsetRelation('tenant');
        $operation->update(['status' => 'ready', 'phase' => 'review', 'progress' => 100, 'preview_signature' => $this->signature($operation, $data), 'heartbeat_at' => now()]);
        $this->audit($operation, 'tenant_import_prepared');
    }

    private function restore(TenantImport $operation, string $path): void
    {
        $known = array_map(fn ($name) => database_path('migrations/'.$name.'.php'), array_keys($this->contract->schema()['migrations']));
        foreach ($known as $file) {
            if (! is_file($file) || ! hash_equals($this->contract->schema()['migrations'][basename($file, '.php')], hash('sha256', str_replace("\r\n", "\n", file_get_contents($file))))) {
                throw new RuntimeException('unsupported_schema');
            }
        }
        if (Artisan::call('migrate', ['--database' => 'tenant', '--path' => $known, '--realpath' => true, '--force' => true]) !== 0) {
            throw new RuntimeException('migration_failed');
        }
        $zip = $this->packages->open($path);
        try {
            $manifest = $this->packages->manifest($zip);
            $db = DB::connection();
            $schema = $db->getSchemaBuilder();
            $counts = [];
            $schema->disableForeignKeyConstraints();
            try {
                foreach ($this->contract->tables() as $table => $columns) {
                    $db->table($table)->delete();
                }
                $total = count($manifest['tables']);
                $index = 0;
                foreach ($manifest['tables'] as $table => $metadata) {
                    $batch = [];
                    $bufferBytes = 0;
                    foreach ($this->packages->rows($zip, $metadata) as $row) {
                        foreach ($row as $column => &$value) {
                            if (is_string($value) && ! in_array($column, ['password', 'issued_password', 'username', 'email', 'remember_token'], true)) {
                                $prefix = rtrim($manifest['source_url'], '/').'/storage/';
                                $value = str_replace([$prefix, str_replace('/', '\\/', $prefix)], ['/storage/', '\\/storage\\/'], $value);
                            }
                        }
                        unset($value);
                        if ($table === 'users' && $row['issued_password'] !== null) {
                            $row['issued_password'] = Crypt::encryptString($row['issued_password']);
                        }
                        $batch[] = $row;
                        $bufferBytes = ($bufferBytes ?? 0) + array_sum(array_map(fn ($value) => strlen((string) $value), $row));
                        if (count($batch) >= 100 || $bufferBytes >= 4 * 1024 * 1024) {
                            $db->table($table)->insert($batch);
                            $batch = [];
                            $bufferBytes = 0;
                            $operation->update(['heartbeat_at' => now()]);
                        }
                    }
                    if ($batch) {
                        $db->table($table)->insert($batch);
                    }
                    $counts[$table] = $db->table($table)->count();
                    if ($counts[$table] !== $metadata['rows']) {
                        throw new RuntimeException('row_count_mismatch');
                    }
                    $operation->update(['phase' => 'records', 'progress' => (int) (70 * ++$index / $total), 'heartbeat_at' => now()]);
                }
            } finally {
                $schema->enableForeignKeyConstraints();
            }
            $this->assertRelations();
            $grants = $db->table('role_has_permissions')->get()->map(fn ($row) => (array) $row)->all();
            if (Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]) !== 0) {
                throw new RuntimeException('migration_failed');
            }
            // Infrastructure migrations may register permissions, but do not silently grant new powers to source roles.
            $db->table('role_has_permissions')->delete();
            foreach (array_chunk($grants, 100) as $batch) {
                $db->table('role_has_permissions')->insert($batch);
            }
            $db->table('users')->update(['is_tenant_administrator' => false]);
            $updated = $db->table('users')->where('id', $operation->selection['owner_id'])->where('is_active', true)->update(['is_tenant_administrator' => true]);
            if ($updated !== 1) {
                throw new RuntimeException('no_eligible_owner');
            }
            // Keep legacy invoice ownership open: never infer or rewrite a business owner during transfer.
            $ambiguous = $db->table('invoices')->whereNull('finance_request_id')->where('invoice_type', '!=', 'finance')->whereNull('student_id')->count();
            $fileCount = 0;
            foreach ($manifest['files'] as $entry => $metadata) {
                [, $scope, $relative] = explode('/', $entry, 3);
                $disk = $scope === 'public' ? 'public' : 'local';
                $stream = $zip->getStream($entry);
                if (! is_resource($stream)) {
                    throw new RuntimeException('missing_file');
                }
                try {
                    if (! Storage::disk($disk)->writeStream($relative, $stream)) {
                        throw new RuntimeException('staging_unavailable');
                    }
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($metadata['sha256'], hash_file('sha256', Storage::disk($disk)->path($relative)))) {
                    throw new RuntimeException('checksum_mismatch');
                }
                $fileCount++;
                if ($fileCount % 20 === 0) {
                    $operation->update(['phase' => 'files', 'progress' => 70 + (int) (25 * $fileCount / max(1, count($manifest['files']))), 'heartbeat_at' => now()]);
                }
            }
            $this->assertRelations();
            $operation->update(['report' => array_merge($operation->report ?? [], ['counts' => $counts, 'files_verified' => $fileCount, 'foreign_keys_verified' => true, 'credentials_reencrypted' => true, 'legacy_invoices_for_review' => $ambiguous])]);
        } finally {
            $zip->close();
        }
    }

    private function assertRelations(): void
    {
        $db = DB::connection();
        foreach ($db->getSchemaBuilder()->getTableListing($db->getDriverName() === 'sqlite' ? null : $db->getDatabaseName(), false) as $table) {
            foreach ($db->getSchemaBuilder()->getForeignKeys($table) as $foreign) {
                $query = $db->table($table.' as source')->leftJoin($foreign['foreign_table'].' as target', function ($join) use ($foreign): void {
                    foreach ($foreign['columns'] as $index => $column) {
                        $join->on('source.'.$column, '=', 'target.'.$foreign['foreign_columns'][$index]);
                    }
                })->whereNull('target.'.$foreign['foreign_columns'][0]);
                foreach ($foreign['columns'] as $column) {
                    $query->whereNotNull('source.'.$column);
                }
                if ($query->exists()) {
                    throw new RuntimeException('broken_relationships');
                }
            }
        }
    }

    private function assertPreview(TenantImport $operation, ?string $signature): void
    {
        if (! $operation->selection || ! $signature || ! hash_equals($operation->preview_signature ?? '', $signature)
            || ! hash_equals($signature, $this->signature($operation, $operation->selection))) {
            throw ValidationException::withMessages(['import' => __('tenant-import.errors.stale_preview')]);
        }
        if ($this->coverage($operation, $operation->selection)['missing']) {
            throw ValidationException::withMessages(['import' => __('tenant-import.errors.missing_modules')]);
        }
    }

    private function extras(TenantImport $operation, Tenant $tenant): void
    {
        foreach ($operation->selection['extras'] as $code) {
            $feature = Feature::where('code', $code)->where('is_active', true)->sole();
            TenantFeatureOverride::updateOrCreate(['tenant_id' => $tenant->id, 'feature_id' => $feature->id], ['is_enabled' => true, 'changed_by_platform_administrator_id' => $operation->platform_administrator_id]);
        }
    }

    public function fail(TenantImport $operation, string $code): void
    {
        $operation->refresh();
        if ($operation->status === 'completed') {
            return;
        }
        $cleanup = $this->cleanup($operation);
        $operation->update(['status' => 'failed', 'error_code' => $code, 'cleanup_status' => $cleanup, 'phase' => 'failed', 'heartbeat_at' => now()]);
        $this->audit($operation, 'tenant_import_failed', ['reason' => $code, 'cleanup' => $cleanup]);
    }

    public function recover(TenantImport $operation, bool $force = false): void
    {
        try {
            $this->files->locked($operation, function () use ($operation, $force): void {
                $operation->refresh();
                if (! $operation->isBusy() || (! $force && $operation->heartbeat_at?->gt(now()->subSeconds(config('standalone-import.timeout') + 120)))) {
                    return;
                }
                $this->fail($operation, 'worker_interrupted');
                $this->files->clearPlaintext($operation);
            });
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
        }
    }

    public function cancel(TenantImport $operation): void
    {
        $this->files->locked($operation, function () use ($operation): void {
            $operation->refresh();
            abort_unless(! $operation->isBusy() && $operation->status !== 'completed', 409);
            $cleanup = $this->cleanup($operation);
            if ($cleanup === 'completed') {
                try {
                    $this->files->clear($operation);
                    $operation->update(['source_id' => null, 'export_id' => null]);
                } catch (\Throwable) {
                    $cleanup = 'review_required';
                }
            }
            $operation->update(['status' => $cleanup === 'completed' ? 'cancelled' : 'failed', 'cleanup_status' => $cleanup, 'error_code' => $cleanup === 'completed' ? null : 'operation_failed']);
            $this->audit($operation, 'tenant_import_cancelled');
        });
    }

    private function cleanup(TenantImport $operation): string
    {
        if (! $operation->tenant_id) {
            return 'completed';
        }
        $tenant = $operation->tenant;
        try {
            if ($operation->database_owned) {
                $this->databases->delete($tenant);
                $operation->update(['database_owned' => false, 'database_allocation_started' => false]);
            } elseif ($operation->database_allocation_started || filled($tenant->database_name)) {
                return 'review_required';
            }
            foreach (['public' => 'public_storage_owned', 'local' => 'private_storage_owned'] as $disk => $flag) {
                if ($operation->$flag) {
                    if (! Storage::disk($disk)->deleteDirectory('tenants/'.strtolower($tenant->uuid))) {
                        return 'review_required';
                    }
                    $operation->update([$flag => false]);
                }
            }
            $tenant->update(['status' => Tenant::STATUS_PROVISIONING_FAILED, 'database_name' => null]);

            return 'completed';
        } catch (\Throwable) {
            return 'review_required';
        }
    }

    private function safeCode(\Throwable $exception): string
    {
        $codes = ['checksum_mismatch', 'invalid_package', 'unsupported_package', 'unsupported_schema', 'invalid_inventory', 'package_too_large', 'unsafe_path', 'invalid_row', 'row_count_mismatch', 'missing_file', 'no_eligible_owner', 'unsupported_learning_path', 'unsupported_password_hash', 'duplicate_source', 'resource_exists', 'unsupported_database', 'migration_failed', 'broken_relationships', 'invoice_ownership_review', 'full_backup_required', 'unsupported_backup_roots', 'unsupported_sql_dump', 'invalid_source_key', 'invalid_source_url', 'row_too_large', 'insufficient_scratch_space'];

        return in_array($exception->getMessage(), $codes, true) ? $exception->getMessage() : 'operation_failed';
    }

    private function audit(TenantImport $operation, string $event, array $properties = []): void
    {
        PlatformAuditEvent::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $operation->platform_administrator_id, 'tenant_id' => $operation->tenant_id, 'event' => $event, 'properties' => ['import_uuid' => $operation->uuid] + $properties]);
    }
}
