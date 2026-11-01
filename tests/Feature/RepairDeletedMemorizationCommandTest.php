<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantScopedContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepairDeletedMemorizationCommandTest extends TestCase
{
    private array $databasePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        config()->set('database.connections.landlord', $connection);
        config()->set('database.connections.tenant', $connection);
        DB::purge('landlord');
        DB::purge('tenant');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        DB::purge('landlord');
        foreach ($this->databasePaths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_all_tenants_preview_apply_and_repeat_are_isolated_and_preserve_ended_courses(): void
    {
        $first = $this->tenant('first');
        $second = $this->tenant('second', Tenant::STATUS_TRIAL);
        $suspended = $this->tenant('suspended', Tenant::STATUS_SUSPENDED);
        Tenant::create(['uuid' => (string) Str::uuid(), 'name' => 'Unprovisioned', 'slug' => 'unprovisioned', 'status' => Tenant::STATUS_ACTIVE]);
        $previous = $this->contextState();

        $this->assertSame(0, Artisan::call('points:repair-deleted-memorization', ['--all-tenants' => true, '--json' => true]));
        $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['first', 'second'], array_column($preview['tenants'], 'tenant'));
        foreach ($preview['tenants'] as $result) {
            $this->assertSame(1, $result['candidate_count']);
            $this->assertSame(1, $result['skipped_count']);
            $this->assertSame(0, $result['enrollment_balances'][0]['after']);
        }
        foreach ([$first, $second, $suspended] as $tenant) {
            app(TenantScopedContext::class)->run($tenant, function (): void {
                $this->assertSame(0, DB::table('point_transactions')->whereNotNull('voided_at')->count());
                $this->assertSame(50, DB::table('enrollments')->where('id', 1)->value('final_points_cached'));
            });
        }

        $this->assertSame(0, Artisan::call('points:repair-deleted-memorization', ['--all-tenants' => true, '--apply' => true, '--json' => true]));
        foreach ([$first, $second] as $tenant) {
            app(TenantScopedContext::class)->run($tenant, function (): void {
                $this->assertNotNull(DB::table('point_transactions')->where('id', 1)->value('voided_at'));
                $this->assertNull(DB::table('point_transactions')->where('id', 2)->value('voided_at'));
                $this->assertSame(0, DB::table('enrollments')->where('id', 1)->value('final_points_cached'));
                $this->assertSame(77, DB::table('enrollments')->where('id', 2)->value('final_points_cached'));
                $this->assertSame(123, DB::table('enrollments')->where('id', 1)->value('memorized_pages_cached'));
            });
        }
        app(TenantScopedContext::class)->run($suspended, function (): void {
            $this->assertSame(0, DB::table('point_transactions')->whereNotNull('voided_at')->count());
        });
        $this->assertSame(0, Artisan::call('points:repair-deleted-memorization', ['--all-tenants' => true, '--apply' => true, '--json' => true]));
        $repeat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([0, 0], array_column($repeat['tenants'], 'candidate_count'));
        $this->assertSame($previous, $this->contextState());
    }

    public function test_command_requires_an_explicit_scope_and_can_limit_to_one_tenant(): void
    {
        $selected = $this->tenant('selected');
        $other = $this->tenant('other');
        $this->assertSame(1, Artisan::call('points:repair-deleted-memorization', ['--apply' => true]));
        $this->assertSame(1, Artisan::call('points:repair-deleted-memorization', ['--all-tenants' => true, '--tenant' => 'selected']));
        $this->assertSame(1, Artisan::call('points:repair-deleted-memorization', ['--tenant' => 'missing']));
        $this->assertSame(0, Artisan::call('points:repair-deleted-memorization', ['--tenant' => 'selected', '--apply' => true, '--json' => true]));
        app(TenantScopedContext::class)->run($selected, function (): void {
            $this->assertSame(1, DB::table('point_transactions')->whereNotNull('voided_at')->count());
        });
        app(TenantScopedContext::class)->run($other, function (): void {
            $this->assertSame(0, DB::table('point_transactions')->whereNotNull('voided_at')->count());
        });
    }

    public function test_failed_tenant_does_not_prevent_other_repairs_or_leak_context(): void
    {
        $broken = $this->tenant('broken');
        $healthy = $this->tenant('healthy');
        app(TenantScopedContext::class)->run($broken, fn () => DB::connection()->getSchemaBuilder()->drop('point_transactions'));
        $previous = $this->contextState();
        $this->assertSame(1, Artisan::call('points:repair-deleted-memorization', ['--all-tenants' => true, '--apply' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['error', 'ok'], array_column($report['tenants'], 'status'));
        $this->assertSame($previous, $this->contextState());
        app(TenantScopedContext::class)->run($healthy, function (): void {
            $this->assertNotNull(DB::table('point_transactions')->where('id', 1)->value('voided_at'));
            $this->assertNull(DB::table('point_transactions')->where('id', 2)->value('voided_at'));
        });
    }

    private function contextState(): array
    {
        return [DB::getDefaultConnection(), config('database.connections.tenant.database'), config('permission.cache.key'),
            config('filesystems.disks.public.root'), config('filesystems.disks.local.root')];
    }

    private function tenant(string $slug, string $status = Tenant::STATUS_ACTIVE): Tenant
    {
        $path = tempnam(sys_get_temp_dir(), 'alkhair-point-repair-');
        $this->databasePaths[] = $path;
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug, 'status' => $status, 'database_name' => $path]);
        app(TenantScopedContext::class)->run($tenant, function (): void {
            $schema = DB::connection()->getSchemaBuilder();
            $schema->create('courses', function (Blueprint $table): void {
                $table->id();
                $table->boolean('is_active')->default(true);
                $table->boolean('awards_points')->default(true);
                $table->timestamp('finished_at')->nullable();
                $table->date('ends_on')->nullable();
                $table->softDeletes();
            });
            $schema->create('groups', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('course_id');
                $table->timestamp('course_finished_at')->nullable();
                $table->softDeletes();
            });
            $schema->create('enrollments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('group_id');
                $table->unsignedBigInteger('student_id');
                $table->integer('final_points_cached');
                $table->integer('memorized_pages_cached')->default(123);
                $table->timestamp('course_finished_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
            $schema->create('memorization_sessions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('enrollment_id');
            });
            $schema->create('point_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('enrollment_id');
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->integer('points');
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->text('void_reason')->nullable();
                $table->timestamps();
            });
            DB::table('courses')->insert([['id' => 1, 'is_active' => true], ['id' => 2, 'is_active' => false]]);
            DB::table('groups')->insert([['id' => 1, 'course_id' => 1], ['id' => 2, 'course_id' => 2]]);
            DB::table('enrollments')->insert([
                ['id' => 1, 'group_id' => 1, 'student_id' => 1, 'final_points_cached' => 50],
                ['id' => 2, 'group_id' => 2, 'student_id' => 1, 'final_points_cached' => 77],
            ]);
            DB::table('point_transactions')->insert([
                ['id' => 1, 'student_id' => 1, 'enrollment_id' => 1, 'source_type' => 'memorization_session', 'source_id' => 999, 'points' => 50],
                ['id' => 2, 'student_id' => 1, 'enrollment_id' => 2, 'source_type' => 'memorization_session', 'source_id' => 998, 'points' => 77],
            ]);
        });

        return $tenant;
    }
}
