<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantBackup;
use App\Services\Landlord\TenantBackupCoordinator;
use App\Services\Landlord\TenantStorageUsage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PlatformOperationsAccessWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_backup_history_is_paginated_filterable_and_verified_counts_exclude_unverified_archives(): void
    {
        $owner = $this->user();
        $tenant = $this->tenant('Search centre');
        for ($i = 0; $i < 22; $i++) {
            $this->backup($tenant, ['trigger' => $i === 21 ? 'scheduled' : 'manual']);
        }
        $this->backup($tenant, ['verified_at' => null]);
        $this->backup($tenant, ['status' => 'failed', 'verified_at' => null, 'file_path' => '']);
        $this->actingAs($owner, 'platform')->get(route('platform.backups.index'))->assertOk()->assertViewHas('backups', fn ($rows) => $rows->total() === 24 && $rows->count() === 20)->assertViewHas('backupStats', fn ($stats) => $stats['verified'] === 22 && $stats['failed'] === 1);
        $this->get(route('platform.backups.index', ['search' => 'Search', 'state' => 'verified', 'trigger' => 'scheduled']))->assertOk()->assertViewHas('backups', fn ($rows) => $rows->total() === 1);
        $this->get(route('platform.backups.index', ['search' => 'No match']))->assertOk()->assertViewHas('backups', fn ($rows) => $rows->total() === 0);
    }

    public function test_backup_viewer_sees_downloads_but_not_creation_restore_or_restricted_tabs(): void
    {
        $this->user();
        $viewer = $this->user(['view.backups']);
        $backup = $this->backup($this->tenant('Readonly'));
        $this->actingAs($viewer, 'platform')->get(route('platform.backups.index'))->assertOk()->assertSee(route('platform.backups.download', $backup), false)->assertDontSee('action="'.route('platform.backups.restore', $backup).'"', false)->assertDontSee('method="POST" action="'.route('platform.backups.create').'"', false)->assertDontSee('href="'.route('platform.storage.index').'"', false);
        $this->post(route('platform.backups.restore', $backup), ['confirmation' => $backup->tenant->slug])->assertForbidden();
        $this->post(route('platform.backups.create'), ['tenant_id' => $backup->tenant_id])->assertForbidden();
    }

    public function test_restore_requires_exact_tenant_confirmation_and_calls_the_existing_service_only_after_validation(): void
    {
        $owner = $this->user();
        $tenant = $this->tenant('Restore target');
        $backup = $this->backup($tenant);
        $coordinator = Mockery::mock(TenantBackupCoordinator::class);
        $coordinator->shouldReceive('restore')->once()->with(Mockery::on(fn ($target) => $target->is($tenant)), Mockery::on(fn ($archive) => $archive->is($backup)))->andReturn($backup);
        $this->app->instance(TenantBackupCoordinator::class, $coordinator);
        $this->actingAs($owner, 'platform')->from(route('platform.backups.index'))->post(route('platform.backups.restore', $backup), ['confirmation' => 'another-tenant', 'restore_backup_id' => $backup->id])->assertSessionHasErrors('confirmation');
        $this->get(route('platform.backups.index'))->assertOk()->assertSee('value="another-tenant"', false);
        $this->post(route('platform.backups.restore', $backup), ['confirmation' => $tenant->slug])->assertRedirect(route('platform.backups.index'));
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'tenant_backup_restored', 'tenant_id' => $tenant->id], 'landlord');
        $this->assertSame(1, TenantBackup::query()->count());
    }

    public function test_suspended_tenant_cannot_start_backup_or_restore_when_safety_backup_is_impossible(): void
    {
        $owner = $this->user();
        $tenant = $this->tenant('Suspended', ['status' => 'suspended']);
        $backup = $this->backup($tenant);
        $coordinator = Mockery::mock(TenantBackupCoordinator::class);
        $coordinator->shouldNotReceive('create');
        $coordinator->shouldNotReceive('restore');
        $this->app->instance(TenantBackupCoordinator::class, $coordinator);
        $this->actingAs($owner, 'platform')->get(route('platform.backups.index'))->assertOk()->assertDontSee('action="'.route('platform.backups.restore', $backup).'"', false);
        $this->post(route('platform.backups.create'), ['tenant_id' => $tenant->id])->assertSessionHasErrors('tenant_id');
        $this->post(route('platform.backups.restore', $backup), ['confirmation' => $tenant->slug])->assertSessionHasErrors('confirmation');
        $unverified = $this->backup($tenant, ['verified_at' => null]);
        $this->post(route('platform.backups.restore', $unverified), ['confirmation' => $tenant->slug])->assertStatus(422);
    }

    public function test_storage_keeps_unavailable_scans_distinct_and_filters_without_double_counting_other_files(): void
    {
        $this->user();
        $viewer = $this->user(['view.storage']);
        $good = $this->tenant('Good scan', ['storage_limit_bytes' => 100]);
        $this->tenant('Unavailable scan');
        $this->tenant('Empty database', ['database_name' => '']);
        $usage = Mockery::mock(TenantStorageUsage::class);
        $usage->shouldReceive('for')->with(Mockery::on(fn ($tenant) => $tenant->is($good)))->andReturn(['media' => 10, 'documents' => 20, 'other_files' => 30, 'database' => 40, 'backups' => 50, 'total' => 150]);
        $usage->shouldReceive('for')->with(Mockery::on(fn ($tenant) => $tenant->name === 'Unavailable scan'))->andThrow(new RuntimeException('Do not expose this server error'));
        $this->app->instance(TenantStorageUsage::class, $usage);
        $this->actingAs($viewer, 'platform')->get(route('platform.storage.index'))->assertOk()->assertViewHas('total', 150)->assertViewHas('unavailable', 1)->assertViewHas('scanned', 1)->assertViewHas('overLimit', 1)->assertSee('Other files')->assertDontSee('Do not expose this server error')->assertDontSee('href="'.route('platform.backups.index').'"', false);
        $this->get(route('platform.storage.index', ['attention' => 'limit']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows[0]['tenant']->is($good))->assertViewHas('total', 150);
        $this->get(route('platform.storage.index', ['attention' => 'unavailable']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows[0]['usage'] === null);
        $this->get(route('platform.storage.index', ['search' => 'Good']))->assertOk()->assertViewHas('unavailable', 0)->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    public function test_storage_paginates_sorted_results_and_handles_a_zero_limit(): void
    {
        $owner = $this->user();
        for ($i = 0; $i < 22; $i++) {
            $this->tenant('Centre '.$i, ['storage_limit_bytes' => 0]);
        }
        $this->mock(TenantStorageUsage::class)->shouldReceive('for')->andReturn(['media' => 0, 'documents' => 0, 'other_files' => 0, 'database' => 0, 'backups' => 0, 'total' => 0]);
        $this->actingAs($owner, 'platform')->get(route('platform.storage.index'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 22 && $rows->count() === 20)->assertSee('Zero limit configured');
        $this->get(route('platform.storage.index', ['page' => 2]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 2);
    }

    public function test_access_has_addressable_user_and_owner_only_role_workspaces(): void
    {
        $owner = $this->user();
        $manager = $this->user(['manage.platform-users']);
        $target = $this->user([]);
        $role = $manager->roles()->firstOrFail();
        $this->actingAs($owner, 'platform')->get(route('platform.access.index'))->assertOk()->assertSee(route('platform.access.roles.index'), false)->assertSee(route('platform.access.users.edit', $target), false)->assertDontSee('action="'.route('platform.access.roles.store').'"', false);
        $this->get(route('platform.access.roles.index'))->assertOk()->assertSee(route('platform.access.roles.edit', $role), false);
        $this->get(route('platform.access.roles.edit', $owner->roles()->first()))->assertOk()->assertSee('The Owner role is protected')->assertDontSee('Delete role');
        $this->actingAs($manager, 'platform')->get(route('platform.access.users.edit', $target))->assertOk()->assertDontSee('action="'.route('platform.access.users.roles', $target).'"', false)->assertDontSee('href="'.route('platform.access.roles.index').'"', false);
        $this->get(route('platform.access.roles.index'))->assertForbidden();
        $this->get(route('platform.access.roles.edit', $role))->assertForbidden();
        $this->actingAs($target, 'platform')->get(route('platform.access.users.edit', $manager))->assertForbidden();
    }

    public function test_user_errors_stay_on_the_addressable_form_and_never_flash_passwords(): void
    {
        $owner = $this->user();
        $target = $this->user([]);
        $url = route('platform.access.users.edit', $target);
        $this->actingAs($owner, 'platform')->from($url)->put(route('platform.access.users.update', $target), ['name' => 'Retained name', 'email' => 'invalid'])->assertSessionHasErrors('email');
        $this->get($url)->assertOk()->assertSee('value="Retained name"', false)->assertSee('value="invalid"', false);
        $this->from($url)->put(route('platform.access.users.password', $target), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->get($url)->assertOk()->assertDontSee('value="short"', false);
    }

    public function test_role_editor_preserves_an_empty_selection_after_errors_and_saves_existing_permission_codes(): void
    {
        $owner = $this->user();
        $role = PlatformRole::query()->create(['name' => 'Review role']);
        $permission = PlatformPermission::query()->where('code', 'view.backups')->sole();
        $role->permissions()->sync([$permission->id]);
        $url = route('platform.access.roles.edit', $role);
        $this->actingAs($owner, 'platform')->from($url)->put(route('platform.access.roles.update', $role), ['name' => '', 'workspace_form' => 'role_permissions'])->assertSessionHasErrors('name');
        $this->get($url)->assertOk()->assertSee('value="'.$permission->id.'"', false)->assertDontSee('value="'.$permission->id.'" checked', false);
        $this->put(route('platform.access.roles.update', $role), ['name' => 'Reviewed role', 'permissions' => [$permission->id]])->assertRedirect();
        $this->assertSame(['view.backups'], $role->fresh()->permissions->pluck('code')->all());
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'platform_role_updated'], 'landlord');
    }

    public function test_arabic_workspaces_and_new_route_guest_redirects(): void
    {
        $owner = $this->user();
        $this->actingAs($owner, 'platform')->withSession(['locale' => 'ar', 'locale_user_selected' => true])->get(route('platform.access.roles.index'))->assertOk()->assertSee('أدوار المنصة')->assertSee('dir="rtl"', false);
        $this->get(route('platform.backups.index'))->assertOk()->assertSee('أرشيفات تم التحقق منها');
        auth('platform')->logout();
        $this->get(route('platform.access.users.edit', $owner))->assertRedirect(route('platform.login'));
        $this->get(route('platform.access.roles.index'))->assertRedirect(route('platform.login'));
        $this->get(route('platform.access.roles.edit', $owner->roles()->first()))->assertRedirect(route('platform.login'));
    }

    private function user(?array $permissions = null): PlatformAdministrator
    {
        $user = PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Platform staff', 'email' => Str::uuid().'@example.test', 'password' => 'test-password', 'is_active' => true]);
        if ($permissions !== null) {
            $role = PlatformRole::query()->create(['name' => 'Role '.Str::uuid()]);
            $role->permissions()->sync(PlatformPermission::query()->whereIn('code', $permissions)->pluck('id'));
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }

    private function tenant(string $name, array $attributes = []): Tenant
    {
        return Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => $name, 'slug' => 'centre-'.Str::lower(Str::random(8)), 'status' => 'active', 'database_name' => 'test_only_'.Str::lower(Str::random(12)), ...$attributes]);
    }

    private function backup(Tenant $tenant, array $attributes = []): TenantBackup
    {
        return TenantBackup::query()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'disk' => 'local', 'file_path' => 'test-only.zip', 'filename' => 'test-only.zip', 'trigger' => 'manual', 'status' => 'completed', 'size_bytes' => 100, 'verified_at' => now(), ...$attributes]);
    }
}
