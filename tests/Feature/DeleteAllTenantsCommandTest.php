<?php

namespace Tests\Feature;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantDatabaseName;
use App\Services\Landlord\TenantResources;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DeleteAllTenantsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.landlord', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('landlord');

        Schema::connection('landlord')->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('database_name')->nullable()->unique();
            $table->string('status');
            $table->string('learning_path_type')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');

        parent::tearDown();
    }

    public function test_dry_run_lists_targets_without_deleting_them(): void
    {
        $tenant = $this->tenant('preview');
        $resources = Mockery::mock(TenantResources::class);
        $resources->shouldNotReceive('delete');
        $this->app->instance(TenantResources::class, $resources);

        $this->artisan('saas:delete-all-tenants', ['--dry-run' => true])
            ->expectsOutputToContain('preview')
            ->expectsOutputToContain('Dry run complete. 1 tenant(s) would be permanently deleted.')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id], 'landlord');
    }

    public function test_command_requires_force_and_exact_confirmation(): void
    {
        $tenant = $this->tenant('guarded');
        $resources = Mockery::mock(TenantResources::class);
        $resources->shouldNotReceive('delete');
        $this->app->instance(TenantResources::class, $resources);

        $this->artisan('saas:delete-all-tenants', [
            '--force' => true,
            '--confirm' => 'delete all tenants',
        ])->assertFailed();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id], 'landlord');
    }

    public function test_command_deletes_every_tenant_after_confirmation(): void
    {
        $first = $this->tenant('first');
        $second = $this->tenant('second');
        $resources = Mockery::mock(TenantResources::class);
        $resources->shouldReceive('delete')->once()->with(Mockery::on(fn (Tenant $tenant): bool => $tenant->is($first)));
        $resources->shouldReceive('delete')->once()->with(Mockery::on(fn (Tenant $tenant): bool => $tenant->is($second)));
        $this->app->instance(TenantResources::class, $resources);

        $this->artisan('saas:delete-all-tenants', [
            '--force' => true,
            '--confirm' => 'DELETE ALL TENANTS',
        ])
            ->expectsOutputToContain('Permanently deleted 2 tenant(s), their databases, and tenant storage.')
            ->assertSuccessful();

        $this->assertDatabaseCount('tenants', 0, 'landlord');
    }

    public function test_preflight_rejects_a_database_not_derived_from_the_tenant_uuid(): void
    {
        $tenant = $this->tenant('unsafe');
        $tenant->forceFill(['database_name' => 'alkhair_tenant_'.str_repeat('f', 32)])->save();
        $resources = Mockery::mock(TenantResources::class);
        $resources->shouldNotReceive('delete');
        $this->app->instance(TenantResources::class, $resources);

        $this->artisan('saas:delete-all-tenants', [
            '--force' => true,
            '--confirm' => 'DELETE ALL TENANTS',
        ])
            ->expectsOutputToContain('Refusing to continue')
            ->assertFailed();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id], 'landlord');
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => Tenant::STATUS_ACTIVE,
            'learning_path_type' => Tenant::LEARNING_PATH_QURAN,
        ]);

        $tenant->forceFill([
            'database_name' => app(TenantDatabaseName::class)->for($tenant),
        ])->save();

        return $tenant;
    }
}
