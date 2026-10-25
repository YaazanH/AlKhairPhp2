<?php

namespace Tests\Feature;

use App\Exceptions\TenantImportMissingFilesException;
use App\Jobs\ProcessTenantImport;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantImport;
use App\Services\BackupEncryptionService;
use App\Services\Landlord\TenantImportDatabase;
use App\Services\Landlord\TenantImportFiles;
use App\Services\Landlord\TenantImportService;
use App\Services\StandaloneTransfer\TransferContract;
use App\Services\StandaloneTransfer\TransferPackage;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\StandaloneTransfer\SettingsBackupFixture;
use Tests\Fixtures\StandaloneTransfer\TransferFixture;
use Tests\TestCase;
use ZipArchive;

class TenantImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private PlatformAdministrator $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.landlord' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(LandlordCatalogSeeder::class);
        $this->actor = PlatformAdministrator::create(['uuid' => (string) Str::uuid(), 'name' => 'Import operator', 'email' => 'import@example.test', 'password' => 'operator-password', 'is_active' => true]);
        $this->actingAs($this->actor, 'platform');
        $this->directory = storage_path('framework/testing/tenant-import-'.Str::uuid());
        mkdir($this->directory, 0700, true);
        config(['standalone-import.directory' => $this->directory.'/staging', 'standalone-import.chunk_bytes' => 1024]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            app('files')->deleteDirectory($this->directory);
        }
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_large_upload_is_resumable_scoped_and_does_not_flash_the_transfer_code(): void
    {
        $secret = str_repeat('a', 43);
        $package = TransferFixture::package($this->directory, $secret);
        $bytes = file_get_contents($package);
        $response = $this->postJson(route('platform.tenants.imports.start'), ['filename' => 'source.alkhair-transfer', 'bytes' => strlen($bytes)])->assertCreated();
        $operation = TenantImport::sole();
        $this->assertSame(route('platform.tenants.imports.show', $operation), $response->json('url'));
        $this->sendChunk($operation, 0, substr($bytes, 0, 1024))->assertOk();
        $this->sendChunk($operation, 0, substr($bytes, 0, 1024))->assertOk();
        $this->sendChunk($operation, 0, str_repeat('x', 1024))->assertConflict();
        $this->assertSame(1024, $operation->fresh()->uploaded_bytes);
        for ($index = 1; $index < (int) ceil(strlen($bytes) / 1024); $index++) {
            $this->sendChunk($operation, $index, substr($bytes, $index * 1024, 1024))->assertOk();
        }
        $this->assertSame(hash_file('sha256', $package), hash_file('sha256', app(TenantImportFiles::class)->path($operation, 'upload.bin')));
        $this->post(route('platform.tenants.imports.validate', $operation), ['transfer_code' => 'short'])->assertSessionHasErrors('transfer_code')->assertSessionMissing('_old_input.transfer_code');
        Queue::fake();
        $this->post(route('platform.tenants.imports.validate', $operation), ['transfer_code' => str_repeat('b', 43)])->assertSessionHasErrors('transfer_code')->assertSessionMissing('_old_input.transfer_code');
        $this->assertSame('uploading', $operation->fresh()->status);
        $this->post(route('platform.tenants.imports.validate', $operation), ['transfer_code' => $secret])->assertRedirect();
        $this->assertNull($operation->fresh()->error_code);
        Queue::assertPushed(ProcessTenantImport::class, fn ($job) => $job->phase === 'validate' && $job->connection === 'tenant-imports' && ! str_contains(serialize($job), $secret));
        app(TenantImportService::class)->process($operation->fresh(), 'validate');
        $operation->refresh();
        $this->assertSame('preview', $operation->status, $operation->error_code ?? '');
        $this->assertSame('41', $operation->summary['owners'][0]['id']);
        $this->assertStringNotContainsString('source-issued-password', $operation->toJson());
        $this->assertFileDoesNotExist(app(TenantImportFiles::class)->path($operation, 'working.zip'));
        $this->get(route('platform.tenants.imports.show', $operation))->assertOk()->assertSee('مدرسة المصدر')->assertDontSee('tenant-import.', false);
    }

    public function test_settings_backup_upload_uses_the_source_key_without_persisting_it_and_preserves_exact_values(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        $name = "Backup school 'quoted' \\ newline\n";
        $backup = SettingsBackupFixture::package($this->directory, $key, [
            'app_settings' => [['id' => '1', 'group' => 'general', 'key' => 'school_name', 'value' => $name, 'type' => 'string']],
            'finance_transactions' => [['id' => '901', 'amount' => '9999999999999999.99']],
        ], files: [
            'public/logo.bin' => "public\0bytes",
            'private/document.bin' => 'private bytes',
            'legacy-access-export-live-memorization/courses.csv' => 'source CSV',
            'legacy-memorization-import-dry-run/imported_sessions.csv' => 'source report',
            'legacy-quran-ajza-live/imported_tests.csv' => 'test report',
            'legacy-quran-pages-live/imported_sessions.csv' => 'memorization report',
            'reset-backups/20260508-205118/database.sql' => 'DROP DATABASE example; -- historical file only',
        ]);
        $this->postJson(route('platform.tenants.imports.start'), ['filename' => 'school-full.alkhair-backup', 'bytes' => filesize($backup)])->assertCreated();
        $operation = TenantImport::sole();
        copy($backup, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
        $operation->update(['uploaded_bytes' => filesize($backup)]);
        $url = route('platform.tenants.imports.validate', $operation);
        $this->get(route('platform.tenants.imports.show', $operation))->assertOk()->assertSee('name="source_application_key"', false)->assertSee('name="source_url"', false)->assertDontSee('name="transfer_code"', false);
        $this->post($url, ['source_application_key' => $key])->assertSessionHasErrors('source_url')->assertSessionMissing('_old_input.source_application_key');
        Queue::fake();
        $this->post($url, ['source_application_key' => 'base64:'.base64_encode(random_bytes(32)), 'source_url' => 'https://v1.example.test'])->assertSessionHasErrors('source_application_key')->assertSessionMissing('_old_input.source_application_key');
        $this->post($url, ['source_application_key' => $key, 'source_url' => 'https://v1.example.test'])->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(ProcessTenantImport::class, fn ($job) => ! str_contains(serialize($job), $key));
        $service = app(TenantImportService::class);
        $service->process($operation->fresh(), 'validate');
        $operation->refresh();
        $this->assertSame('preview', $operation->status, $operation->error_code ?? '');
        $this->assertSame($name, $operation->summary['source_name']);
        $this->assertSame(7, $operation->summary['file_count']);
        $this->assertSame(5, $operation->summary['preserved_history_files']);
        $this->assertStringNotContainsString($key, $operation->toJson());
        $files = app(TenantImportFiles::class);
        $plain = $files->path($operation, 'working.zip');
        app(BackupEncryptionService::class)->decrypt($files->path($operation, 'package.enc'), $plain);
        $packages = app(TransferPackage::class);
        $zip = $packages->open($plain);
        $manifest = $packages->manifest($zip);
        $this->assertSame('source CSV', $zip->getFromName('files/private/standalone-history/legacy-access-export-live-memorization/courses.csv'));
        $this->assertSame('DROP DATABASE example; -- historical file only', $zip->getFromName('files/private/standalone-history/reset-backups/20260508-205118/database.sql'));
        $this->assertArrayNotHasKey('files/public/standalone-history/reset-backups/20260508-205118/database.sql', $manifest['files']);
        $this->assertSame('9999999999999999.99', iterator_to_array($packages->rows($zip, $manifest['tables']['finance_transactions']))[0]['amount']);
        $user = iterator_to_array($packages->rows($zip, $manifest['tables']['users']))[0];
        $this->assertSame('source-issued-password', $user['issued_password']);
        $this->assertNull($user['remember_token']);
        $zip->close();
        unlink($plain);
        $this->assertFileDoesNotExist($files->path($operation, 'converted.zip'));
        $this->assertDirectoryDoesNotExist($files->path($operation, 'converted.zip').'.work');
        $this->get(route('platform.tenants.imports.show', $operation))->assertOk()->assertSee(__('tenant-import.backup_review_help'));
    }

    public function test_settings_backup_accepts_the_rocksdb_dump_format_without_executing_setup_sql(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        $name = 'School PREPARE s; EXECUTE s; rocksdb_bulk_load';
        $backup = SettingsBackupFixture::package($this->directory, $key, [
            'app_settings' => [['id' => '1', 'group' => 'general', 'key' => 'school_name', 'value' => $name, 'type' => 'string']],
            'finance_transactions' => [['id' => '901', 'amount' => '9999999999999999.99']],
        ], extraSql: file_get_contents(base_path('tests/Fixtures/Backups/rocksdb-session-reset.sql')), preamble: file_get_contents(base_path('tests/Fixtures/Backups/rocksdb-session-setup.sql')));
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'mysql84.alkhair-backup', 'expected_bytes' => filesize($backup), 'uploaded_bytes' => filesize($backup), 'status' => 'uploading']);
        $files = app(TenantImportFiles::class);
        copy($backup, $files->path($operation, 'upload.bin'));
        Queue::fake();
        $this->post(route('platform.tenants.imports.validate', $operation), ['source_application_key' => $key, 'source_url' => 'https://source.example.test'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantImportService::class)->process($operation->fresh(), 'validate');
        $this->assertSame('preview', $operation->fresh()->status, $operation->fresh()->error_code ?? '');
        $this->assertSame($name, $operation->fresh()->summary['source_name']);
        $this->assertSame(0, Tenant::count());
        $plain = $files->path($operation, 'working.zip');
        app(BackupEncryptionService::class)->decrypt($files->path($operation, 'package.enc'), $plain);
        $packages = app(TransferPackage::class);
        $zip = $packages->open($plain);
        try {
            $manifest = $packages->manifest($zip);
            $this->assertSame('9999999999999999.99', iterator_to_array($packages->rows($zip, $manifest['tables']['finance_transactions']))[0]['amount']);
        } finally {
            $zip->close();
            unlink($plain);
        }
    }

    public function test_settings_backup_rejects_partial_backups_unsupported_sql_and_foreign_storage_without_creating_tenants(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        foreach ([
            ['manifest' => ['scope' => 'database'], 'sql' => '', 'files' => [], 'code' => 'full_backup_required'],
            ['manifest' => ['scope' => 'files', 'database' => null], 'sql' => '', 'files' => [], 'code' => 'full_backup_required'],
            ['manifest' => [], 'sql' => "DROP DATABASE landlord;\n", 'files' => [], 'code' => 'unsupported_sql_dump'],
            ['manifest' => [], 'sql' => "INSERT INTO `users` VALUES (LOAD_FILE('/etc/passwd'));\n", 'files' => [], 'code' => 'unsupported_sql_dump'],
            ['manifest' => [], 'sql' => '', 'files' => ['public/tenants/another-school/file.bin' => 'other tenant'], 'code' => 'unsupported_backup_roots'],
            ['manifest' => [], 'sql' => '', 'files' => ['public/../escape' => 'traversal'], 'code' => 'unsafe_path'],
            ['manifest' => [], 'sql' => '', 'files' => ['unknown-storage/file.bin' => 'unmapped'], 'code' => 'unsupported_backup_roots'],
            ['manifest' => [], 'sql' => '', 'files' => ['tenants/another-school/file.bin' => 'other tenant'], 'code' => 'unsupported_backup_roots'],
            ['manifest' => [], 'sql' => '', 'files' => ['legacy-access-export-live/report.csv' => 'source', 'private/standalone-history/legacy-access-export-live/report.csv' => 'collision'], 'code' => 'invalid_inventory'],
        ] as $case) {
            $backup = SettingsBackupFixture::package($this->directory, $key, manifestOverrides: $case['manifest'], extraSql: $case['sql'], files: $case['files']);
            $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'invalid.alkhair-backup', 'expected_bytes' => filesize($backup), 'uploaded_bytes' => filesize($backup), 'status' => 'uploading']);
            copy($backup, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
            $this->post(route('platform.tenants.imports.validate', $operation), ['source_application_key' => $key, 'source_url' => 'https://v1.example.test'])->assertSessionHasErrors('source_application_key')->assertSessionMissing('_old_input.source_application_key');
            $this->assertSame($case['code'], $operation->fresh()->error_code);
            $this->assertSame('uploading', $operation->fresh()->status);
            $this->assertFileDoesNotExist(app(TenantImportFiles::class)->path($operation, 'working.zip'));
            $this->assertFileDoesNotExist(app(TenantImportFiles::class)->path($operation, 'converted.zip'));
            $this->assertDirectoryDoesNotExist(app(TenantImportFiles::class)->path($operation, 'converted.zip').'.work');
        }
        $this->assertSame(0, Tenant::count());
    }

    public function test_interrupted_settings_backup_conversion_removes_its_plaintext_work_directory(): void
    {
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'stale.alkhair-backup', 'expected_bytes' => 1, 'status' => 'securing', 'heartbeat_at' => now()->subHours(2)]);
        $files = app(TenantImportFiles::class);
        $directory = $files->path($operation, 'converted.zip').'.work';
        mkdir($directory, 0700);
        file_put_contents($directory.'/database.sql', 'sensitive rows');
        file_put_contents($files->path($operation, 'converted.zip'), 'sensitive package');
        app(TenantImportService::class)->recover($operation);
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFileDoesNotExist($files->path($operation, 'converted.zip'));
    }

    public function test_settings_backup_checks_file_hashes_and_keeps_a_stable_identity_across_saved_backups(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        Queue::fake();
        $operations = [];
        foreach ([3, 2] as $version) {
            $backup = SettingsBackupFixture::package($this->directory, $key, manifestOverrides: ['version' => $version]);
            $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'snapshot.alkhair-backup', 'expected_bytes' => filesize($backup), 'uploaded_bytes' => filesize($backup), 'status' => 'uploading']);
            copy($backup, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
            app(TenantImportService::class)->secure($operation, $key, 'https://v1.example.test');
            app(TenantImportService::class)->process($operation->fresh(), 'validate');
            $operations[] = $operation->fresh();
        }
        $this->assertSame('preview', $operations[0]->status);
        $this->assertSame('failed', $operations[1]->status);
        $this->assertSame('duplicate_source', $operations[1]->error_code);
        $backup = SettingsBackupFixture::package($this->directory, $key, manifestOverrides: ['files' => [['entry' => 'files/storage/public/document.bin', 'size_bytes' => 4, 'sha256' => str_repeat('0', 64)]]], files: ['public/document.bin' => 'data']);
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'corrupt.alkhair-backup', 'expected_bytes' => filesize($backup), 'uploaded_bytes' => filesize($backup), 'status' => 'uploading']);
        copy($backup, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
        $this->post(route('platform.tenants.imports.validate', $operation), ['source_application_key' => $key, 'source_url' => 'https://v1.example.test'])->assertSessionHasErrors('source_application_key');
        $this->assertSame('checksum_mismatch', $operation->fresh()->error_code);
        $this->assertDirectoryDoesNotExist(app(TenantImportFiles::class)->path($operation, 'converted.zip').'.work');
    }

    public function test_separate_websites_with_a_shared_key_can_import_while_the_same_website_stays_deduplicated(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        Queue::fake();
        $operations = [];
        foreach (['https://first.example.test/', 'http://FIRST.EXAMPLE.TEST:80', 'https://second.example.test'] as $url) {
            $backup = SettingsBackupFixture::package($this->directory, $key);
            $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'school.alkhair-backup', 'expected_bytes' => filesize($backup), 'uploaded_bytes' => filesize($backup), 'status' => 'uploading']);
            copy($backup, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
            app(TenantImportService::class)->secure($operation, $key, $url);
            app(TenantImportService::class)->process($operation->fresh(), 'validate');
            $operations[] = $operation->fresh();
        }
        $this->assertSame('preview', $operations[0]->status);
        $this->assertSame('duplicate_source', $operations[1]->error_code);
        $this->assertSame('preview', $operations[2]->status, $operations[2]->error_code ?? '');
        $this->assertNotSame($operations[0]->source_id, $operations[2]->source_id);
        $this->assertSame($operations[0]->summary['source_identity']['legacy_source_id'], $operations[2]->summary['source_identity']['legacy_source_id']);
        $this->assertSame('https://second.example.test', $operations[2]->summary['source_url']);
        $this->assertSame(0, Tenant::count());
    }

    public function test_stable_source_id_takes_precedence_over_key_and_website_changes(): void
    {
        Queue::fake();
        $sourceId = (string) Str::uuid();
        foreach (['https://old.example.test', 'https://new.example.test'] as $index => $url) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $backup = SettingsBackupFixture::package($this->directory, $key, files: ['tenant-transfer-source-id' => $sourceId]);
            $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'school.alkhair-backup', 'expected_bytes' => filesize($backup), 'uploaded_bytes' => filesize($backup), 'status' => 'uploading']);
            copy($backup, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
            app(TenantImportService::class)->secure($operation, $key, $url);
            app(TenantImportService::class)->process($operation->fresh(), 'validate');
            if ($index === 0) {
                $this->assertSame('preview', $operation->fresh()->status);
                $this->assertSame($sourceId, $operation->fresh()->source_id);
            } else {
                $this->assertSame('duplicate_source', $operation->fresh()->error_code);
            }
        }
    }

    public function test_sodium_backup_without_the_required_runtime_reports_the_missing_extension(): void
    {
        if (function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')) {
            $this->markTestSkipped('This check exercises a PHP runtime without Sodium.');
        }
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'source.alkhair-backup', 'expected_bytes' => 15, 'uploaded_bytes' => 15, 'status' => 'uploading']);
        $files = app(TenantImportFiles::class);
        file_put_contents($files->path($operation, 'upload.bin'), "ALKHAIR-BACKUP\x01");
        $key = 'base64:'.base64_encode(random_bytes(32));
        $this->post(route('platform.tenants.imports.validate', $operation), ['source_application_key' => $key, 'source_url' => 'https://v1.example.test'])
            ->assertSessionHasErrors(['source_application_key' => __('tenant-import.errors.backup_sodium_unavailable')])
            ->assertSessionMissing('_old_input.source_application_key');
        $this->assertSame('backup_sodium_unavailable', $operation->fresh()->error_code);
        $this->assertSame('uploading', $operation->fresh()->status);
        $this->assertFileDoesNotExist($files->path($operation, 'working.zip'));
    }

    public function test_missing_source_files_are_listed_for_review_without_creating_a_tenant(): void
    {
        $secret = str_repeat('a', 43);
        $package = TransferFixture::package($this->directory, $secret, ['curriculum_resources' => [['id' => '13', 'pdf_path' => 'curriculum/books/missing.pdf', 'is_active' => '1']]]);
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'missing.alkhair-transfer', 'expected_bytes' => filesize($package), 'uploaded_bytes' => filesize($package), 'status' => 'uploading']);
        copy($package, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
        Queue::fake();
        $service = app(TenantImportService::class);
        $service->secure($operation, $secret);
        $service->process($operation->fresh(), 'validate');
        $operation->refresh();
        $this->assertSame('failed', $operation->status);
        $this->assertSame('missing_file', $operation->error_code);
        $this->assertSame(['files/private/curriculum/books/missing.pdf'], $operation->report['missing_files']);
        $this->assertSame(1, $operation->report['missing_file_count']);
        $this->assertSame(0, Tenant::count());
        $this->get(route('platform.tenants.imports.show', $operation))->assertOk()->assertSee('files/private/curriculum/books/missing.pdf');
        $url = route('platform.tenants.imports.allow-missing-files', $operation);
        $signature = $service->missingFilesSignature($operation);
        $this->post($url, ['signature' => $signature])->assertSessionHasErrors('confirm');
        $this->post($url, ['signature' => str_repeat('0', 64), 'confirm' => 1])->assertSessionHasErrors('import');
        $this->post($url, ['signature' => $signature, 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('validating', $operation->fresh()->status);
        $service->process($operation->fresh(), 'validate');
        $operation->refresh();
        $this->assertSame('preview', $operation->status);
        $this->assertSame(['files/private/curriculum/books/missing.pdf'], $operation->summary['missing_files']);
        $this->assertSame($operation->archive_sha256, $operation->report['missing_files_archive_sha256']);
        $this->assertSame($this->actor->id, $operation->report['missing_files_approved_by']);
        $this->assertSame(0, Tenant::count());
        $this->get(route('platform.tenants.imports.show', $operation))->assertOk()->assertSee(__('tenant-import.allowed_missing_title', ['count' => 1]));

        $second = TransferFixture::package($this->directory, $secret, ['curriculum_resources' => [['id' => '14', 'pdf_path' => 'curriculum/books/another.pdf', 'is_active' => '1']]]);
        $plain = $this->directory.'/another-missing.zip';
        app(BackupEncryptionService::class)->decrypt($second, $plain, $secret);
        try {
            app(TransferPackage::class)->inspect($plain, allowedMissingFiles: $operation->report['allowed_missing_files']);
            $this->fail('An unrelated missing file must remain blocked.');
        } catch (TenantImportMissingFilesException $exception) {
            $this->assertSame(['files/private/curriculum/books/another.pdf'], $exception->files);
        }
    }

    public function test_feature_coverage_and_changed_package_block_preparation_without_creating_a_tenant(): void
    {
        $operation = $this->validatedOperation(['students' => [['id' => '501', 'first_name' => 'Student', 'last_name' => 'Source', 'birth_date' => '2015-01-01', 'status' => 'active']]]);
        $plan = Plan::create(['code' => 'empty-import', 'name' => 'Foundation', 'is_active' => true]);
        $selection = $this->selection($plan);
        $service = app(TenantImportService::class);
        $service->select($operation, $selection);
        $this->assertSame(['students'], $service->coverage($operation->fresh(), $selection)['missing']);
        $this->post(route('platform.tenants.imports.prepare', $operation), ['signature' => $operation->fresh()->preview_signature, 'confirm' => 1])->assertSessionHasErrors('import');
        $this->assertSame(0, Tenant::count());
        $selection['extras'] = ['students'];
        $service->select($operation->fresh(), $selection);
        $signature = $operation->fresh()->preview_signature;
        $plan->update(['price_syp' => 100]);
        $this->post(route('platform.tenants.imports.prepare', $operation), ['signature' => $signature, 'confirm' => 1])->assertSessionHasErrors('import');
        $this->assertSame('preview', $operation->fresh()->status);
        $this->assertSame(0, Tenant::count());
    }

    public function test_downloaded_v1_export_kit_contains_the_matching_contract_and_no_platform_secrets(): void
    {
        $response = $this->get(route('platform.tenants.imports.exporter'))->assertOk()->assertDownload('alkhair-v1-export-tool.zip');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        try {
            $this->assertSame(8, $zip->numFiles);
            $this->assertNotFalse($zip->locateName('tools/export-standalone.php'));
            $this->assertFalse($zip->locateName('.env'));
            $this->assertFalse($zip->locateName('app/Models/Landlord/Tenant.php'));
            $this->assertSame(app(TransferContract::class)->schema(), json_decode($zip->getFromName('resources/standalone-transfer/v1-schema.json'), true));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_saved_import_result_remains_readable_after_its_completed_tenant_is_removed(): void
    {
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'completed.alkhair-transfer', 'expected_bytes' => 1, 'status' => 'completed']);
        $this->get(route('platform.tenants.imports.show', $operation))->assertOk()->assertSee(__('tenant-import.tenant_removed'));
    }

    public function test_import_pages_and_chunks_require_authority_and_operation_ownership(): void
    {
        $operation = $this->validatedOperation();
        $manager = PlatformAdministrator::create(['uuid' => (string) Str::uuid(), 'name' => 'Other operator', 'email' => 'other@example.test', 'password' => 'operator-password', 'is_active' => true]);
        $role = PlatformRole::create(['name' => 'Tenant manager']);
        $role->permissions()->attach(PlatformPermission::where('code', 'manage.tenants')->sole());
        $manager->roles()->attach($role);
        $this->actingAs($manager, 'platform')->get(route('platform.tenants.imports.show', $operation))->assertNotFound();
        $this->getJson(route('platform.tenants.imports.status', $operation))->assertNotFound();
        $this->sendChunk($operation, 0, 'fake')->assertNotFound();
        $manager->roles()->detach();
        $this->get(route('platform.tenants.imports.index'))->assertForbidden();
    }

    public function test_inactive_import_cannot_be_activated_or_deleted_through_regular_tenant_controls(): void
    {
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'name' => 'Pending import', 'slug' => 'pending-import', 'status' => 'draft']);
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'tenant_id' => $tenant->id, 'filename' => 'source.alkhair-transfer', 'expected_bytes' => 1, 'status' => 'importing']);
        $this->patch(route('platform.tenants.status', $tenant), ['status' => 'active', 'confirm_slug' => $tenant->slug])->assertUnprocessable();
        $this->put(route('platform.tenants.subscription.update', $tenant), ['plan' => 'core'])->assertUnprocessable();
        $this->delete(route('platform.tenants.destroy', $tenant), ['confirm_slug' => $tenant->slug])->assertUnprocessable();
        $this->assertSame('draft', $tenant->fresh()->status);
        $this->assertNotNull($operation->fresh());
    }

    public function test_archive_traversal_is_rejected_before_any_tenant_resources_exist(): void
    {
        $package = TransferFixture::package($this->directory, str_repeat('a', 43));
        $plain = $this->directory.'/invalid.zip';
        app(BackupEncryptionService::class)->decrypt($package, $plain, str_repeat('a', 43));
        $zip = new ZipArchive;
        $zip->open($plain);
        $zip->addFromString('../outside.txt', 'unsafe');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        app(TransferPackage::class)->inspect($plain);
    }

    public function test_transfer_paths_remain_inside_storage_on_windows_and_linux(): void
    {
        $contract = app(TransferContract::class);
        foreach (['../outside', '/outside', 'a/.. /outside', 'a/file.', 'a\\outside', 'C:/outside', 'a/NUL.txt'] as $path) {
            try {
                $contract->path($path);
                $this->fail('Unsafe transfer path accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('unsafe_path', $exception->getMessage());
            }
        }
        $this->assertSame('students/record.pdf', $contract->path('students/record.pdf'));
    }

    public function test_unsupported_versions_corrupt_rows_and_symlinks_fail_validation(): void
    {
        foreach (['version', 'checksum', 'symlink'] as $case) {
            $package = TransferFixture::package($this->directory, str_repeat('a', 43));
            $plain = $this->directory.'/'.$case.'.zip';
            app(BackupEncryptionService::class)->decrypt($package, $plain, str_repeat('a', 43));
            $zip = new ZipArchive;
            $zip->open($plain);
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            if ($case === 'version') {
                $manifest['version'] = 99;
            } elseif ($case === 'checksum') {
                $zip->addFromString('tables/users.jsonl', str_repeat('x', $manifest['tables']['users']['bytes']));
            } else {
                $entry = 'files/public/linked.txt';
                $zip->addFromString($entry, '/outside');
                $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0120777 << 16);
                $manifest['files'][$entry] = ['bytes' => 8, 'sha256' => hash('sha256', '/outside')];
            }
            $zip->addFromString('manifest.json', json_encode($manifest));
            $zip->close();
            try {
                app(TransferPackage::class)->inspect($plain);
                $this->fail('Unsafe package accepted: '.$case);
            } catch (\RuntimeException $exception) {
                $this->assertContains($exception->getMessage(), ['unsupported_package', 'checksum_mismatch', 'invalid_inventory']);
            }
        }
        $this->assertSame(0, Tenant::count());
    }

    public function test_interrupted_worker_recovery_respects_the_operation_lock_and_removes_plaintext(): void
    {
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'source.alkhair-transfer', 'expected_bytes' => 1, 'status' => 'queued', 'heartbeat_at' => now()->subHours(2)]);
        $plain = app(TenantImportFiles::class)->path($operation, 'working.zip');
        file_put_contents($plain, 'private scratch');
        $lock = Cache::store('file')->lock('tenant-import:'.$operation->uuid, 60);
        $this->assertTrue($lock->get());
        app(TenantImportService::class)->recover($operation);
        $this->assertSame('queued', $operation->fresh()->status);
        $lock->release();
        app(TenantImportService::class)->recover($operation);
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertSame('worker_interrupted', $operation->fresh()->error_code);
        $this->assertFileDoesNotExist($plain);
    }

    public function test_uncertain_database_allocation_never_deletes_an_existing_database(): void
    {
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'name' => 'Uncertain allocation', 'slug' => 'uncertain', 'status' => 'provisioning']);
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'tenant_id' => $tenant->id, 'filename' => 'source.alkhair-transfer', 'expected_bytes' => 1, 'status' => 'importing', 'database_allocation_started' => true, 'database_owned' => false]);
        $database = \Mockery::mock(TenantImportDatabase::class);
        $database->shouldNotReceive('delete');
        app()->instance(TenantImportDatabase::class, $database);
        app(TenantImportService::class)->fail($operation, 'worker_interrupted');
        $this->assertSame('review_required', $operation->fresh()->cleanup_status);
        $this->assertSame('provisioning', $tenant->fresh()->status);
    }

    public function test_portable_encryption_uses_the_transfer_code_and_keeps_existing_app_key_backups_compatible(): void
    {
        $plain = $this->directory.'/plain';
        file_put_contents($plain, random_bytes(128 * 1024));
        $encryption = app(BackupEncryptionService::class);
        $encryption->encrypt($plain, $this->directory.'/portable', str_repeat('a', 43), portable: true);
        $stream = fopen($this->directory.'/portable', 'rb');
        $this->assertSame("ALKHAIR-BACKUP\x02", fread($stream, 15));
        fclose($stream);
        $encryption->decrypt($this->directory.'/portable', $this->directory.'/portable-restored', str_repeat('a', 43));
        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->directory.'/portable-restored'));
        $encryption->encrypt($plain, $this->directory.'/normal');
        $encryption->decrypt($this->directory.'/normal', $this->directory.'/normal-restored');
        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->directory.'/normal-restored'));
    }

    public function test_duplicate_source_is_blocked_and_cancel_allows_a_clean_new_attempt(): void
    {
        $first = $this->validatedOperation();
        $second = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'retry.alkhair-transfer', 'expected_bytes' => 1, 'status' => 'validating', 'archive_sha256' => $first->archive_sha256]);
        copy(app(TenantImportFiles::class)->path($first, 'package.enc'), app(TenantImportFiles::class)->path($second, 'package.enc'));
        app(TenantImportService::class)->process($second, 'validate');
        $this->assertSame('duplicate_source', $second->fresh()->error_code);
        app(TenantImportService::class)->cancel($first);
        $this->assertNull($first->fresh()->source_id);
        $this->assertSame('cancelled', $first->fresh()->status);
    }

    private function validatedOperation(array $overrides = []): TenantImport
    {
        $secret = str_repeat('a', 43);
        $package = TransferFixture::package($this->directory, $secret, $overrides);
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'source.alkhair-transfer', 'expected_bytes' => filesize($package), 'uploaded_bytes' => filesize($package), 'status' => 'uploading']);
        copy($package, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
        Queue::fake();
        app(TenantImportService::class)->secure($operation, $secret);
        app(TenantImportService::class)->process($operation->fresh(), 'validate');
        $this->assertSame('preview', $operation->fresh()->status, $operation->fresh()->error_code ?? '');

        return $operation->fresh();
    }

    private function selection(Plan $plan): array
    {
        return ['name' => 'Imported school', 'slug' => 'imported-school', 'locale' => 'ar', 'timezone' => 'Asia/Damascus', 'owner_id' => '41', 'plan_id' => $plan->id, 'extras' => [], 'storage_limit_bytes' => 1024 * 1024 * 1024];
    }

    private function sendChunk(TenantImport $operation, int $index, string $bytes): TestResponse
    {
        return $this->call('PUT', route('platform.tenants.imports.chunk', [$operation, $index]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => strlen($bytes), 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }
}
