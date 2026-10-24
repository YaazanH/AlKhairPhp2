<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantScopedContext;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneCourselessAttendanceTest extends TestCase
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
        $this->seed(LandlordCatalogSeeder::class);
        $this->attendanceSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        DB::purge('landlord');
        DB::purge('sqlite');
        foreach ($this->databasePaths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_cleanup_runs_in_each_operational_tenant_and_keeps_other_databases_untouched(): void
    {
        $first = $this->tenant('first');
        $second = $this->tenant('second', Tenant::STATUS_TRIAL);
        $suspended = $this->tenant('suspended', Tenant::STATUS_SUSPENDED);
        $this->tenant('unprovisioned', provisioned: false);
        DB::table('teacher_attendance_days')->insert(['id' => 1, 'course_id' => null]);
        $previousConnection = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        $previousPublicRoot = config('filesystems.disks.public.root');
        $previousPrivateRoot = config('filesystems.disks.local.root');

        $this->artisan('attendance:prune-courseless')->assertSuccessful();

        $this->assertSame($previousConnection, DB::getDefaultConnection());
        $this->assertSame($previousDatabase, config('database.connections.tenant.database'));
        $this->assertSame($previousPublicRoot, config('filesystems.disks.public.root'));
        $this->assertSame($previousPrivateRoot, config('filesystems.disks.local.root'));
        $this->assertSame(1, DB::table('teacher_attendance_days')->count());
        foreach ([$first, $second] as $tenant) {
            app(TenantScopedContext::class)->run($tenant, function (): void {
                $this->assertSame([3], DB::table('teacher_attendance_days')->pluck('id')->all());
                $this->assertSame([3], DB::table('teacher_attendance_records')->pluck('id')->all());
            });
        }
        app(TenantScopedContext::class)->run($suspended, function (): void {
            $this->assertSame(3, DB::table('teacher_attendance_days')->count());
            $this->assertSame(3, DB::table('teacher_attendance_records')->count());
        });
        $this->artisan('attendance:prune-courseless')
            ->expectsOutput('Deleted 0 course-less attendance day(s) for [first].')
            ->expectsOutput('Deleted 0 course-less attendance day(s) for [second].')
            ->assertSuccessful();
    }

    public function test_cleanup_can_target_one_tenant_and_rejects_unknown_or_ineligible_tenants(): void
    {
        $this->tenant('selected');
        $other = $this->tenant('other');
        $this->tenant('suspended', Tenant::STATUS_SUSPENDED);
        $this->tenant('unprovisioned', provisioned: false);

        $this->artisan('attendance:prune-courseless', ['--tenant' => 'selected'])->assertSuccessful();
        app(TenantScopedContext::class)->run($other, function (): void {
            $this->assertSame(3, DB::table('teacher_attendance_days')->count());
        });
        foreach (['missing', 'suspended', 'unprovisioned'] as $slug) {
            $this->artisan('attendance:prune-courseless', ['--tenant' => $slug])->assertFailed();
        }
    }

    public function test_unavailable_tenant_does_not_prevent_other_cleanup_or_leak_its_connection(): void
    {
        $broken = $this->tenant('broken');
        app(TenantScopedContext::class)->run($broken, function (): void {
            DB::connection()->getSchemaBuilder()->drop('teacher_attendance_records');
        });
        $healthy = $this->tenant('healthy');
        $previousConnection = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        $previousPublicRoot = config('filesystems.disks.public.root');
        $previousPrivateRoot = config('filesystems.disks.local.root');

        $this->artisan('attendance:prune-courseless')->assertFailed();

        $this->assertSame($previousConnection, DB::getDefaultConnection());
        $this->assertSame($previousDatabase, config('database.connections.tenant.database'));
        $this->assertSame($previousPublicRoot, config('filesystems.disks.public.root'));
        $this->assertSame($previousPrivateRoot, config('filesystems.disks.local.root'));
        app(TenantScopedContext::class)->run($healthy, function (): void {
            $this->assertSame([3], DB::table('teacher_attendance_days')->pluck('id')->all());
        });
        app(TenantScopedContext::class)->run($broken, function (): void {
            $this->assertSame(3, DB::table('teacher_attendance_days')->count());
        });
    }

    public function test_empty_tenant_catalog_does_not_clean_the_default_database(): void
    {
        DB::table('teacher_attendance_days')->insert(['id' => 1, 'course_id' => null]);
        $this->artisan('attendance:prune-courseless')->assertSuccessful();
        $this->assertSame(1, DB::table('teacher_attendance_days')->count());
    }

    public function test_attendance_without_classes_is_valid_and_never_pruned(): void
    {
        $tenant = $this->tenant('attendance-only');
        $plan = Plan::create(['code' => 'attendance-only', 'name' => 'Attendance only', 'is_active' => true]);
        $plan->features()->sync([Feature::where('code', 'teacher_attendance')->sole()->id]);
        $tenant->subscription()->update(['plan_id' => $plan->id]);

        $this->artisan('attendance:prune-courseless')->assertSuccessful();

        app(TenantScopedContext::class)->run($tenant, function (): void {
            $this->assertSame(3, DB::table('teacher_attendance_days')->count());
            $this->assertSame(3, DB::table('teacher_attendance_records')->count());
        });
    }

    public function test_disabled_teacher_attendance_module_is_not_cleaned(): void
    {
        $tenant = $this->tenant('classes-only');
        $plan = Plan::create(['code' => 'classes-only', 'name' => 'Classes only', 'is_active' => true]);
        $plan->features()->sync([Feature::where('code', 'classes')->sole()->id]);
        $tenant->subscription()->update(['plan_id' => $plan->id]);

        $this->artisan('attendance:prune-courseless')->assertSuccessful();

        app(TenantScopedContext::class)->run($tenant, function (): void {
            $this->assertSame(3, DB::table('teacher_attendance_days')->count());
            $this->assertSame(3, DB::table('teacher_attendance_records')->count());
        });
    }

    public function test_cleanup_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'attendance:prune-courseless'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function tenant(string $slug, string $status = Tenant::STATUS_ACTIVE, bool $provisioned = true): Tenant
    {
        $path = $provisioned ? tempnam(sys_get_temp_dir(), 'alkhair-attendance-') : null;
        if ($path !== null) {
            $this->databasePaths[] = $path;
        }
        $tenant = Tenant::create([
            'uuid' => (string) Str::uuid(), 'name' => $slug, 'slug' => $slug,
            'status' => $status, 'database_name' => $path,
        ]);
        $tenant->subscription()->create(['plan_id' => Plan::where('code', 'core')->sole()->id, 'status' => 'active', 'starts_at' => now()->subDay()]);
        if ($provisioned) {
            app(TenantScopedContext::class)->run($tenant, function (): void {
                $this->attendanceSchema();
                DB::table('teacher_attendance_days')->insert([
                    ['id' => 1, 'course_id' => null], ['id' => 2, 'course_id' => null], ['id' => 3, 'course_id' => 1],
                ]);
                DB::table('teacher_attendance_records')->insert([
                    ['id' => 1, 'teacher_attendance_day_id' => 1],
                    ['id' => 2, 'teacher_attendance_day_id' => 2],
                    ['id' => 3, 'teacher_attendance_day_id' => 3],
                ]);
            });
        }

        return $tenant;
    }

    private function attendanceSchema(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('teacher_attendance_days', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id')->nullable();
        });
        $schema->create('teacher_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_attendance_day_id')->constrained();
        });
    }
}
