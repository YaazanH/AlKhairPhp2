<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\TenantPlatformAdministratorLink;
use App\Models\User;
use App\Services\Landlord\TenantAdministratorProvisioner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantAdministratorProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_platform_administrator_with_the_same_email_share_one_tenant_account(): void
    {
        $this->seed(RoleSeeder::class);

        $platform = new PlatformAdministrator([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'platform-password',
            'is_active' => true,
        ]);

        app(TenantAdministratorProvisioner::class)->provision(
            ownerName: 'Tenant Owner',
            ownerEmail: 'PLATFORM@example.test',
            ownerPassword: 'temporary-password',
            platform: $platform,
        );

        $user = User::query()->whereRaw('lower(email) = ?', ['platform@example.test'])->sole();

        $this->assertSame('platform-admin', $user->username);
        $this->assertTrue($user->hasAllRoles(['admin', 'super_admin']));
        $this->assertTrue(Hash::check('platform-password', $user->password));
        $this->assertTrue($user->is_tenant_administrator);
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('tenant_platform_administrator_links', [
            'user_id' => $user->id,
            'platform_administrator_uuid' => $platform->uuid,
        ]);
        $this->assertSame(1, TenantPlatformAdministratorLink::query()->count());
    }

    public function test_separate_tenant_administrator_receives_a_temporary_password_requirement(): void
    {
        $this->seed(RoleSeeder::class);

        $platform = new PlatformAdministrator([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => 'platform@example.test',
            'password' => 'platform-password',
            'is_active' => true,
        ]);

        app(TenantAdministratorProvisioner::class)->provision(
            ownerName: 'Tenant Administrator',
            ownerEmail: 'tenant-admin@example.test',
            ownerPassword: 'temporary-password',
            platform: $platform,
        );

        $owner = User::query()->where('email', 'tenant-admin@example.test')->sole();
        $support = User::query()->where('email', 'like', '%@support.invalid')->sole();

        $this->assertSame('tenant-admin@example.test', $owner->username);
        $this->assertTrue($owner->is_tenant_administrator);
        $this->assertTrue($owner->must_change_password);
        $this->assertNull($owner->password_changed_at);
        $this->assertSame('temporary-password', $owner->issued_password);
        $this->assertFalse($support->is_tenant_administrator);
        $this->assertSame('Platform Management', $support->name);
        $this->assertFalse(Hash::check('platform-password', $support->password));
        $this->assertFalse($support->must_change_password);
        $this->assertNotNull($support->password_changed_at);
    }
}
