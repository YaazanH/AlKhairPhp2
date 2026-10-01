<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_first_platform_administrator_is_owner_and_can_manage_access(): void
    {
        $owner = $this->administrator('owner@example.test');
        $this->assertTrue($owner->isPlatformOwner());
        $this->actingAs($owner, 'platform')->get(route('platform.access.index'))->assertOk()->assertSee('Roles and users');

        $this->actingAs($owner, 'platform')->post(route('platform.access.roles.store'), ['name' => 'Backup manager', 'permissions' => [PlatformPermission::query()->where('code', 'view.backups')->sole()->id]])->assertRedirect();
        $this->assertDatabaseHas('platform_roles', ['name' => 'Backup manager'], 'landlord');
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'platform_role_created'], 'landlord');
    }

    public function test_owner_cannot_remove_the_final_active_owner_role_or_deactivate_them(): void
    {
        $owner = $this->administrator('owner@example.test');
        $this->actingAs($owner, 'platform')->put(route('platform.access.users.roles', $owner), ['roles' => []])->assertSessionHasErrors('roles');
        $this->actingAs($owner, 'platform')->patch(route('platform.access.users.status', $owner), ['is_active' => '0'])->assertSessionHasErrors('status');
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertTrue($owner->fresh()->isPlatformOwner());
    }

    public function test_new_platform_user_must_change_temporary_password_before_using_platform(): void
    {
        $owner = $this->administrator('owner@example.test');
        $this->actingAs($owner, 'platform')->post(route('platform.access.users.store'), ['name' => 'Backup staff', 'email' => 'staff@example.test', 'password' => 'temporary-password', 'password_confirmation' => 'temporary-password'])->assertRedirect();
        $staff = PlatformAdministrator::query()->where('email', 'staff@example.test')->sole();
        $this->assertTrue($staff->must_change_password);
        $this->actingAs($staff, 'platform')->get(route('platform.dashboard'))->assertRedirect(route('platform.password-change.show'));
        $this->actingAs($staff, 'platform')->put(route('platform.password-change.update'), ['current_password' => 'temporary-password', 'password' => 'changed-password-123', 'password_confirmation' => 'changed-password-123'])->assertRedirect(route('platform.dashboard'));
        $this->assertFalse($staff->fresh()->must_change_password);
    }

    public function test_non_owner_cannot_change_platform_roles_even_when_user_management_is_granted(): void
    {
        $owner = $this->administrator('owner@example.test');
        $role = PlatformRole::query()->create(['name' => 'User manager']);
        $role->permissions()->sync([PlatformPermission::query()->where('code', 'manage.platform-users')->sole()->id]);
        $manager = $this->administrator('manager@example.test');
        $manager->roles()->sync([$role->id]);
        $this->actingAs($manager, 'platform')->get(route('platform.access.index'))->assertOk();
        $this->actingAs($manager, 'platform')->post(route('platform.access.roles.store'), ['name' => 'Nope'])->assertForbidden();
    }

    private function administrator(string $email): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Platform Admin', 'email' => $email, 'password' => 'secret-password', 'is_active' => true]);
    }
}
