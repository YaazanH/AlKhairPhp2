<?php

namespace Tests\Feature;

use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantImport;
use App\Models\SystemBackup;
use App\Services\Landlord\TenantImportFiles;
use App\Services\Landlord\TenantImportService;
use App\Services\Landlord\TenantScopedContext;
use App\Services\StandaloneTransfer\TransferContract;
use App\Services\StandaloneTransfer\TransferExporter;
use App\Services\SystemBackupService;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\StandaloneTransfer\TransferFixture;
use Tests\TestCase;

class MySqlTenantImportTest extends TestCase
{
    private array $databases = [];

    private string $directory;

    private string $source;

    private string $platformKey;

    private array $roots;

    private PlatformAdministrator $actor;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_TENANT_IMPORT_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_TENANT_IMPORT_MYSQL_TESTS=1 for disposable loopback MySQL import tests.');
        }
        $template = config('database.connections.tenant');
        if (! in_array($template['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('Import acceptance tests require loopback MySQL.');
        }
        $template['database'] = null;
        $template['url'] = null;
        config(['database.connections.import_admin' => $template]);
        DB::purge('import_admin');
        $suffix = bin2hex(random_bytes(8));
        $this->source = 'alkhair_import_test_'.$suffix.'_source';
        $landlord = 'alkhair_import_test_'.$suffix.'_landlord';
        foreach ([$this->source, $landlord] as $database) {
            DB::connection('import_admin')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $this->databases[] = $database;
        }
        config(['database.connections.source' => array_replace($template, ['database' => $this->source]), 'database.connections.landlord' => array_replace($template, ['database' => $landlord])]);
        DB::purge('source');
        DB::purge('landlord');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $known = array_map(fn ($name) => database_path('migrations/'.$name.'.php'), array_keys(app(TransferContract::class)->schema()['migrations']));
        Artisan::call('migrate', ['--database' => 'source', '--path' => $known, '--realpath' => true, '--force' => true]);
        $this->seed(LandlordCatalogSeeder::class);
        $this->actor = PlatformAdministrator::create(['uuid' => (string) Str::uuid(), 'name' => 'Import owner', 'email' => 'import-owner@example.test', 'password' => 'operator-password', 'is_active' => true]);
        $this->actingAs($this->actor, 'platform');
        $this->directory = storage_path('framework/testing/mysql-import-'.$suffix);
        mkdir($this->directory, 0700, true);
        $this->app->useStoragePath($this->directory);
        $this->roots = ['public' => $this->directory.'/app/public', 'local' => $this->directory.'/app/private'];
        foreach ($this->roots as $disk => $root) {
            mkdir($root, 0700, true);
            config(['filesystems.disks.'.$disk.'.root' => $root]);
            app('filesystem')->forgetDisk($disk);
        }
        config(['standalone-import.directory' => $this->directory.'/staging', 'standalone-import.source_identity_file' => $this->directory.'/source-id']);
        $this->platformKey = config('app.key');
    }

    protected function tearDown(): void
    {
        if ($this->databases) {
            try {
                foreach (TenantImport::with('tenant')->get() as $operation) {
                    if ($operation->database_owned && $operation->tenant?->database_name) {
                        $expected = 'alkhair_tenant_'.str_replace('-', '', $operation->tenant->uuid);
                        if ($operation->tenant->database_name === $expected) {
                            $this->databases[] = $expected;
                        }
                    }
                }
            } finally {
                foreach (['tenant', 'source', 'landlord'] as $connection) {
                    DB::purge($connection);
                }
                foreach (array_unique($this->databases) as $database) {
                    if (! preg_match('/^(?:alkhair_import_test_[a-f0-9]{16}_(?:source|landlord)|alkhair_tenant_[a-f0-9]{32})$/D', $database)) {
                        throw new \RuntimeException('Refusing to clean an unrecorded test database.');
                    }
                    DB::connection('import_admin')->statement('DROP DATABASE `'.$database.'`');
                }
                DB::purge('import_admin');
            }
        }
        if (isset($this->directory)) {
            app('files')->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_real_v1_export_upload_preparation_and_activation_preserve_data_and_isolation(): void
    {
        $this->assertSourceImport(false);
    }

    public function test_settings_full_backup_import_preserves_data_files_and_tenant_activation(): void
    {
        $this->assertSourceImport(true);
    }

    public function test_approved_missing_document_keeps_its_reference_and_warning_through_preparation_and_activation(): void
    {
        $this->assertSourceImport(true, true);
    }

    private function assertSourceImport(bool $settingsBackup, bool $allowMissingDocument = false): void
    {
        $source = DB::connection('source');
        $actualTables = $source->getSchemaBuilder()->getTableListing($this->source, false);
        sort($actualTables);
        $this->assertSame(array_keys(app(TransferContract::class)->schema()['tables']), $actualTables);
        $source->table('users')->insert(['id' => 41, 'name' => 'مسؤول المصدر', 'username' => 'source-admin', 'email' => 'source@example.test', 'password' => Hash::make('source-login-password'), 'is_active' => true, 'remember_token' => 'discard-this-token']);
        $source->table('roles')->insert(['id' => 91, 'name' => 'admin', 'guard_name' => 'web']);
        $source->table('model_has_roles')->insert(['role_id' => 91, 'model_type' => 'App\\Models\\User', 'model_id' => 41]);
        $source->table('permissions')->insertOrIgnore(['name' => 'students.view', 'guard_name' => 'web']);
        $source->table('role_has_permissions')->insert(['role_id' => 91, 'permission_id' => $source->table('permissions')->where('name', 'students.view')->value('id')]);
        foreach (['school_name' => 'مدرسة الاستيراد', 'school_timezone' => 'Asia/Damascus', 'default_locale' => 'ar'] as $key => $value) {
            $source->table('app_settings')->updateOrInsert(['group' => 'general', 'key' => $key], ['value' => $value, 'type' => 'string']);
        }
        $source->table('students')->insert(['id' => 501, 'first_name' => 'طالب', 'last_name' => 'المصدر', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $source->table('student_files')->insert(['id' => 601, 'student_id' => 501, 'file_type' => 'document', 'file_path' => 'students/source-document.bin', 'original_name' => 'source-document.bin', 'uploaded_by' => 41]);
        $source->table('finance_currencies')->insert(['id' => 701, 'code' => 'SYPTEST', 'name' => 'Test SYP', 'rate_to_base' => '1.000000000000', 'is_active' => true]);
        $source->table('finance_cash_boxes')->insert(['id' => 801, 'name' => 'Source cash box', 'code' => 'SOURCE']);
        $source->table('finance_transactions')->insert(['id' => 901, 'transaction_no' => 'SOURCE-901', 'cash_box_id' => 801, 'currency_id' => 701, 'type' => 'income', 'direction' => 'in', 'amount' => '1234567890.25', 'signed_amount' => '1234567890.25', 'rate_to_base' => '1.000000000000', 'base_amount' => '1234567890.25', 'local_amount' => '1234567890.25', 'transaction_date' => '2026-10-24', 'entered_by' => 41]);
        $source->table('sessions')->insert(['id' => 'source-session', 'payload' => 'runtime', 'last_activity' => time()]);
        if ($allowMissingDocument) {
            $source->table('curriculum_subject_definitions')->insert(['id' => 1001, 'name' => 'Source curriculum subject', 'is_active' => true]);
            $source->table('curriculum_resources')->insert(['id' => 1002, 'subject_definition_id' => 1001, 'book_name' => 'Source missing document', 'pdf_path' => 'curriculum/books/missing.pdf', 'is_active' => true]);
        }
        $sourceRoot = $this->directory.'/source-storage/public';
        mkdir($sourceRoot.'/students', 0700, true);
        mkdir($this->directory.'/source-storage/private', 0700, true);
        $stream = fopen($sourceRoot.'/students/source-document.bin', 'xb');
        $block = random_bytes(64 * 1024);
        $megabytes = max(1, (int) (getenv('TENANT_IMPORT_LARGE_FIXTURE_MB') ?: 1));
        for ($index = 0; $index < $megabytes * 16; $index++) {
            fwrite($stream, $block);
        }
        fclose($stream);
        $sourceFileHash = hash_file('sha256', $sourceRoot.'/students/source-document.bin');
        $originalPassword = $source->table('users')->where('id', 41)->value('password');
        $sourceKey = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => $sourceKey]);
        app()->forgetInstance('encrypter');
        $sourceCiphertext = Crypt::encryptString('source-issued-password');
        $source->table('users')->where('id', 41)->update(['issued_password' => $sourceCiphertext]);
        DB::setDefaultConnection('source');
        config(['filesystems.disks.public.root' => $sourceRoot, 'filesystems.disks.local.root' => $this->directory.'/source-storage/private']);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $package = $this->directory.'/source.alkhair-transfer';
        if ($settingsBackup) {
            config(['backups.data_roots' => ['storage' => $this->directory.'/source-storage'], 'backups.temporary_directory' => $this->directory.'/backup-tmp']);
            app('filesystem')->forgetDisk('local');
            $backup = app(SystemBackupService::class)->create(scope: SystemBackup::SCOPE_FULL, applyRetention: false);
            $package = Storage::disk($backup->disk)->path($backup->file_path);
            $secret = $sourceKey;
        } else {
            app(TransferExporter::class)->export($package, $secret);
        }
        config(['app.key' => $this->platformKey]);
        app()->forgetInstance('encrypter');
        DB::setDefaultConnection('sqlite');
        foreach ($this->roots as $disk => $root) {
            config(['filesystems.disks.'.$disk.'.root' => $root]);
            app('filesystem')->forgetDisk($disk);
        }
        DB::table('app_settings')->updateOrInsert(['group' => 'test', 'key' => 'sentinel'], ['value' => 'root-unchanged', 'type' => 'string']);
        $this->postJson(route('platform.tenants.imports.start'), ['filename' => $settingsBackup ? 'source.alkhair-backup' : 'source.alkhair-transfer', 'bytes' => filesize($package)])->assertCreated();
        $operation = TenantImport::sole();
        $stream = fopen($package, 'rb');
        $index = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, config('standalone-import.chunk_bytes'));
            if ($chunk === '') {
                break;
            }
            $this->call('PUT', route('platform.tenants.imports.chunk', [$operation, $index++]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => strlen($chunk), 'HTTP_ACCEPT' => 'application/json'], $chunk)->assertOk();
        }
        fclose($stream);
        $this->post(route('platform.tenants.imports.validate', $operation), $settingsBackup ? ['source_application_key' => $secret, 'source_url' => 'https://source.example.test'] : ['transfer_code' => $secret])->assertRedirect()->assertSessionHasNoErrors();
        $imports = app(TenantImportService::class);
        $payload = DB::connection('landlord')->table('tenant_import_jobs')->sole()->payload;
        $this->assertStringNotContainsString($secret, $payload);
        $this->assertStringNotContainsString($sourceKey, $payload);
        $this->assertSame(0, DB::table('jobs')->count());
        $job = Queue::connection('tenant-imports')->pop('tenant-imports');
        $this->assertNotNull($job);
        $job->fire();
        if ($allowMissingDocument) {
            $this->assertSame('missing_file', $operation->fresh()->error_code);
            $this->assertSame(0, Tenant::count());
            $this->post(route('platform.tenants.imports.allow-missing-files', $operation), ['signature' => $imports->missingFilesSignature($operation->fresh()), 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
            $job = Queue::connection('tenant-imports')->pop('tenant-imports');
            $this->assertNotNull($job);
            $job->fire();
        }
        $this->assertSame('preview', $operation->fresh()->status, $operation->fresh()->error_code ?? '');
        $plan = Plan::where('code', 'core_finance')->sole();
        $plan->update(['price_syp' => 1000]);
        $selection = ['name' => 'Imported school', 'slug' => 'mysql-import-school', 'locale' => 'ar', 'timezone' => 'Asia/Damascus', 'owner_id' => '41', 'plan_id' => $plan->id, 'extras' => $allowMissingDocument ? ['curriculum'] : [], 'storage_limit_bytes' => 2 * 1024 * 1024 * 1024];
        $imports->select($operation->fresh(), $selection);
        $imports->enqueue($operation->fresh(), $operation->fresh()->preview_signature);
        $job = Queue::connection('tenant-imports')->pop('tenant-imports');
        $this->assertNotNull($job);
        $job->fire();
        $operation->refresh();
        $this->assertSame('ready', $operation->status, $operation->error_code ?? '');
        $this->get(route('platform.tenants.imports.show', $operation))->assertOk();
        $tenant = $operation->tenant;
        $this->assertSame('draft', $tenant->status);
        if ($allowMissingDocument) {
            $this->assertSame(['files/private/curriculum/books/missing.pdf'], $operation->report['allowed_missing_files']);
            $this->assertSame($operation->archive_sha256, $operation->report['missing_files_archive_sha256']);
            $this->assertSame(['files/private/curriculum/books/missing.pdf'], $operation->summary['missing_files']);
            app(TenantScopedContext::class)->run($tenant, function (): void {
                $this->assertSame('curriculum/books/missing.pdf', DB::table('curriculum_resources')->where('id', 1002)->value('pdf_path'));
                $this->assertSame(1, DB::table('curriculum_resources')->where('id', 1002)->value('is_active'));
                $this->assertFalse(is_file(config('filesystems.disks.local.root').'/curriculum/books/missing.pdf'));
            });
        }
        $this->assertSame('root-unchanged', DB::table('app_settings')->where('group', 'test')->value('value'));
        $this->assertSame('sqlite', DB::getDefaultConnection());
        app(TenantScopedContext::class)->run($tenant, function () use ($originalPassword, $sourceCiphertext, $sourceFileHash): void {
            $this->assertSame(501, DB::table('students')->sole()->id);
            $user = DB::table('users')->where('id', 41)->sole();
            $this->assertSame($originalPassword, $user->password);
            $this->assertTrue(Hash::check('source-login-password', $user->password));
            $this->assertNotSame($sourceCiphertext, $user->issued_password);
            $this->assertSame('source-issued-password', Crypt::decryptString($user->issued_password));
            $this->assertNull($user->remember_token);
            $this->assertSame(0, DB::table('sessions')->count());
            $this->assertSame(0, DB::table('tenant_platform_administrator_links')->count());
            $this->assertSame('1234567890.25', DB::table('finance_transactions')->where('id', 901)->value('amount'));
            $this->assertSame(1, DB::table('role_has_permissions')->count());
            $this->assertSame($sourceFileHash, hash_file('sha256', config('filesystems.disks.public.root').'/students/source-document.bin'));
        });
        $signature = $operation->preview_signature;
        $activation = ['signature' => $signature, 'confirm_slug' => $tenant->slug, 'reviewed' => 1, 'starts_at' => '2026-10-24', 'subscription_status' => 'active'];
        $this->post(route('platform.tenants.imports.activate', $operation), $activation)->assertSessionHasErrors('subscription');
        $this->assertSame('ready', $operation->fresh()->status);
        $this->assertSame('draft', $tenant->fresh()->status);
        $this->post(route('platform.tenants.subscription.payments.store', $tenant), ['amount_syp' => 1000, 'payment_method' => 'cash', 'paid_at' => '2026-10-24', 'reference' => 'IMPORT-CREDIT'])->assertRedirect();
        $this->post(route('platform.tenants.imports.activate', $operation), $activation)->assertRedirect();
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame('active', $tenant->fresh()->status);
        if ($allowMissingDocument) {
            $this->assertSame(['files/private/curriculum/books/missing.pdf'], $operation->fresh()->summary['missing_files']);
            $this->assertSame($this->actor->id, $operation->fresh()->report['missing_files_approved_by']);
        }
        app(TenantScopedContext::class)->run($tenant, function (): void {
            $settings = DB::table('app_settings')->where('group', 'general')->pluck('value', 'key');
            $this->assertSame('Imported school', $settings['school_name']);
            $this->assertSame('Asia/Damascus', $settings['school_timezone']);
            $this->assertSame('ar', $settings['default_locale']);
        });
        $this->assertSame(1, DB::connection('landlord')->table('platform_subscription_ledger_entries')->where('type', 'activation')->count());
        $this->post(route('platform.tenants.imports.activate', $operation), $activation)->assertConflict();
        $this->assertSame(1, DB::connection('landlord')->table('platform_subscription_ledger_entries')->where('type', 'activation')->count());
        $this->assertSame(1, $source->table('sessions')->count());
        $this->assertSame('discard-this-token', $source->table('users')->where('id', 41)->value('remember_token'));
        $this->assertSame($sourceCiphertext, $source->table('users')->where('id', 41)->value('issued_password'));
        $this->assertSame('root-unchanged', DB::table('app_settings')->where('group', 'test')->value('value'));
    }

    public function test_failed_record_copy_removes_only_the_database_owned_by_that_attempt(): void
    {
        $package = TransferFixture::package($this->directory, str_repeat('a', 43), [
            'students' => [['id' => '501', 'first_name' => null, 'last_name' => 'Invalid fixture', 'birth_date' => '2015-01-01', 'status' => 'active']],
        ]);
        $operation = TenantImport::create(['uuid' => (string) Str::uuid(), 'platform_administrator_id' => $this->actor->id, 'filename' => 'invalid-record.alkhair-transfer', 'expected_bytes' => filesize($package), 'uploaded_bytes' => filesize($package), 'status' => 'uploading']);
        copy($package, app(TenantImportFiles::class)->path($operation, 'upload.bin'));
        $imports = app(TenantImportService::class);
        $imports->secure($operation, str_repeat('a', 43));
        $imports->process($operation->fresh(), 'validate');
        $this->assertSame('preview', $operation->fresh()->status);
        $imports->select($operation->fresh(), ['name' => 'Failed fixture', 'slug' => 'failed-fixture', 'locale' => 'ar', 'timezone' => 'Asia/Damascus', 'owner_id' => '41', 'plan_id' => Plan::where('code', 'core')->sole()->id, 'extras' => [], 'storage_limit_bytes' => 1024 * 1024 * 1024]);
        $imports->enqueue($operation->fresh(), $operation->fresh()->preview_signature);
        $imports->process($operation->fresh(), 'prepare');
        $operation->refresh();
        $this->assertSame('failed', $operation->status);
        $this->assertSame('completed', $operation->cleanup_status);
        $this->assertFalse($operation->database_owned);
        $this->assertNull($operation->tenant->database_name);
        $database = 'alkhair_tenant_'.str_replace('-', '', $operation->tenant->uuid);
        $this->assertSame(0, (int) DB::connection('import_admin')->selectOne('SELECT COUNT(*) AS total FROM information_schema.schemata WHERE schema_name = ?', [$database])->total);
        $this->assertSame(1, (int) DB::connection('import_admin')->selectOne('SELECT COUNT(*) AS total FROM information_schema.schemata WHERE schema_name = ?', [$this->source])->total);
        $this->assertFileDoesNotExist(app(TenantImportFiles::class)->path($operation, 'working.zip'));
    }
}
