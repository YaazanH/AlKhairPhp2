<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenantFromHost;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantDomain;
use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantPermissionCache;
use App\Services\Landlord\TenantScopedContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantPermissionIsolationTest extends TestCase
{
    private array $paths = [];

    private array $tenants;

    private string $baseKey;

    protected function setUp(): void
    {
        parent::setUp();
        $sqlite = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        foreach (['sqlite', 'landlord', 'tenant', 'permission_fixture'] as $connection) {
            config()->set('database.connections.'.$connection, $sqlite);
            DB::purge($connection);
        }
        DB::setDefaultConnection('sqlite');
        $this->baseKey = 'permission-test.base.'.Str::uuid();
        config(['tenancy.base_domain' => 'example.test', 'permission.cache.store' => 'array']);
        app(TenantPermissionCache::class)->useKey($this->baseKey);
        $this->mock(CurrentModuleAccess::class)->shouldReceive('permissionAvailable')->andReturnTrue();

        $landlord = DB::connection('landlord')->getSchemaBuilder();
        $landlord->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->string('name');
            $table->string('slug');
            $table->string('status');
            $table->string('database_name');
            $table->string('learning_path_type');
            $table->timestamps();
        });
        $landlord->create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('host');
            $table->boolean('is_primary');
            $table->timestamps();
        });

        $this->permissionSchema('sqlite', imported: false, base: true);
        foreach (['normal', 'imported'] as $slug) {
            $path = tempnam(sys_get_temp_dir(), 'alkhair-permission-');
            $this->paths[] = $path;
            config()->set('database.connections.permission_fixture.database', $path);
            DB::purge('permission_fixture');
            $this->permissionSchema('permission_fixture', imported: $slug === 'imported');
            $tenant = Tenant::create([
                'uuid' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug,
                'status' => Tenant::STATUS_ACTIVE, 'database_name' => $path,
            ]);
            TenantDomain::create(['tenant_id' => $tenant->id, 'host' => $slug.'.example.test', 'is_primary' => true]);
            $this->tenants[$slug] = $tenant;
        }
        DB::purge('permission_fixture');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        foreach (['tenant', 'permission_fixture', 'landlord', 'sqlite'] as $connection) {
            DB::purge($connection);
        }
        foreach ($this->paths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    #[DataProvider('entryPointsAndOrders')]
    public function test_permission_grants_and_denials_remain_isolated_in_both_tenant_orders(string $entryPoint, bool $importedFirst): void
    {
        $this->assertBaseAccess();
        $order = $importedFirst ? ['imported', 'normal', 'imported'] : ['normal', 'imported', 'normal'];
        foreach ($order as $slug) {
            $tenant = $this->tenants[$slug];
            $this->within($entryPoint, $tenant, function () use ($tenant, $slug): void {
                $this->assertSame(app(TenantPermissionCache::class)->keyFor($tenant), config('permission.cache.key'));
                $teacher = User::findOrFail(1);
                $this->assertTrue($teacher->can('students.view'));
                $this->assertFalse($teacher->can('memorization.view'));
                if ($slug === 'imported') {
                    // Role ID 4 exists in both tenants but grants different powers.
                    $other = User::findOrFail(2);
                    $this->assertFalse($other->can('students.view'));
                    $this->assertTrue($other->can('memorization.view'));
                }
            });
            $this->assertBaseAccess();
        }
        $this->assertTrue(Cache::store('array')->has($this->baseKey));
        foreach ($this->tenants as $tenant) {
            $this->assertTrue(Cache::store('array')->has(app(TenantPermissionCache::class)->keyFor($tenant)));
        }
    }

    public static function entryPointsAndOrders(): array
    {
        return [
            'web-normal-first' => ['web', false],
            'web-imported-first' => ['web', true],
            'job-normal-first' => ['job', false],
            'job-imported-first' => ['job', true],
        ];
    }

    #[DataProvider('entryPoints')]
    public function test_exceptions_restore_the_previous_database_and_permission_cache(string $entryPoint): void
    {
        $this->assertBaseAccess();
        $previousDatabase = config('database.connections.tenant.database');
        try {
            $this->within($entryPoint, $this->tenants['imported'], function (): void {
                $this->assertTrue(User::findOrFail(1)->can('students.view'));
                throw new RuntimeException('expected permission-scope failure');
            });
            $this->fail('The callback should throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('expected permission-scope failure', $e->getMessage());
        }
        $this->assertSame($previousDatabase, config('database.connections.tenant.database'));
        $this->assertFalse(app(TenantContext::class)->hasTenant());
        $this->assertBaseAccess();
    }

    public static function entryPoints(): array
    {
        return ['web' => ['web'], 'job' => ['job']];
    }

    public function test_nested_job_scopes_restore_the_outer_tenants_loaded_permissions_after_failure(): void
    {
        $context = app(TenantScopedContext::class);
        $context->run($this->tenants['normal'], function () use ($context): void {
            $this->assertTrue(User::findOrFail(1)->can('students.view'));
            try {
                $context->run($this->tenants['imported'], function (): void {
                    $this->assertTrue(User::findOrFail(2)->can('memorization.view'));
                    throw new RuntimeException('nested failure');
                });
            } catch (RuntimeException $e) {
                $this->assertSame('nested failure', $e->getMessage());
            }
            $this->assertSame($this->tenants['normal']->database_name, DB::connection()->getDatabaseName());
            $this->assertSame(app(TenantPermissionCache::class)->keyFor($this->tenants['normal']), config('permission.cache.key'));
            $this->assertFalse(User::findOrFail(1)->can('memorization.view'));
        });
        $this->assertBaseAccess();
    }

    public function test_new_teacher_accounts_use_the_imported_tenants_grants_after_other_caches_are_warm(): void
    {
        $this->assertBaseAccess();
        $this->within('job', $this->tenants['normal'], fn () => $this->assertTrue(User::findOrFail(1)->can('students.view')));
        $this->within('job', $this->tenants['imported'], function (): void {
            $this->assertTrue(User::findOrFail(1)->can('students.view'));
            DB::table('users')->insert(['id' => 3, 'name' => 'New imported teacher', 'is_active' => true]);
            $teacher = User::findOrFail(3);
            $teacher->assignRole('ImportedTeacher');
            $this->assertTrue($teacher->can('students.view'));
            $this->assertFalse($teacher->can('memorization.view'));
        });
        $this->assertBaseAccess();
    }

    public function test_permission_updates_invalidate_only_the_current_tenants_cache(): void
    {
        foreach ($this->tenants as $tenant) {
            $this->within('job', $tenant, fn () => $this->assertTrue(User::findOrFail(1)->can('students.view')));
        }
        $importedKey = app(TenantPermissionCache::class)->keyFor($this->tenants['imported']);
        $this->within('job', $this->tenants['normal'], function () use ($importedKey): void {
            Role::findById(4, 'web')->givePermissionTo('memorization.view');
            $this->assertTrue(User::findOrFail(1)->can('memorization.view'));
            $this->assertTrue(Cache::store('array')->has($importedKey));
        });
        $this->within('job', $this->tenants['imported'], fn () => $this->assertFalse(User::findOrFail(1)->can('memorization.view')));
        $this->assertBaseAccess();
    }

    private function within(string $entryPoint, Tenant $tenant, callable $callback): void
    {
        if ($entryPoint === 'job') {
            app(TenantScopedContext::class)->run($tenant, $callback);

            return;
        }
        $response = app(ResolveTenantFromHost::class)->handle(Request::create('http://'.$tenant->slug.'.example.test/dashboard'), function () use ($callback) {
            $callback();

            return response()->noContent();
        });
        $this->assertSame(204, $response->getStatusCode());
    }

    private function assertBaseAccess(): void
    {
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame($this->baseKey, config('permission.cache.key'));
        $user = User::findOrFail(1);
        $this->assertFalse($user->can('students.view'));
        $this->assertTrue($user->can('memorization.view'));
    }

    private function permissionSchema(string $connection, bool $imported, bool $base = false): void
    {
        $db = DB::connection($connection);
        $schema = $db->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active');
        });
        foreach (['roles', 'permissions'] as $table) {
            $schema->create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }
        $schema->create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
        });
        foreach (['roles' => 'role_id', 'permissions' => 'permission_id'] as $relation => $foreignKey) {
            $schema->create('model_has_'.$relation, function (Blueprint $table) use ($foreignKey): void {
                $table->unsignedBigInteger($foreignKey);
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
            });
        }
        $db->table('permissions')->insert([
            ['id' => $imported ? 20 : 10, 'name' => 'students.view', 'guard_name' => 'web'],
            ['id' => $imported ? 10 : 20, 'name' => 'memorization.view', 'guard_name' => 'web'],
        ]);
        $db->table('users')->insert(['id' => 1, 'name' => 'Teacher', 'is_active' => true]);
        $db->table('roles')->insert(['id' => $imported ? 8 : 4, 'name' => $imported ? 'ImportedTeacher' : 'teacher', 'guard_name' => 'web']);
        $db->table('model_has_roles')->insert(['role_id' => $imported ? 8 : 4, 'model_type' => User::class, 'model_id' => 1]);
        $db->table('role_has_permissions')->insert(['role_id' => $imported ? 8 : 4, 'permission_id' => $base || $imported ? 20 : 10]);
        if ($imported) {
            $db->table('users')->insert(['id' => 2, 'name' => 'Other imported staff', 'is_active' => true]);
            $db->table('roles')->insert(['id' => 4, 'name' => 'ImportedSupervisor', 'guard_name' => 'web']);
            $db->table('model_has_roles')->insert(['role_id' => 4, 'model_type' => User::class, 'model_id' => 2]);
            $db->table('role_has_permissions')->insert(['role_id' => 4, 'permission_id' => 10]);
        }
    }
}
