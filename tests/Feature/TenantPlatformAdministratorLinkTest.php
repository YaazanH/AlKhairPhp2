<?php

namespace Tests\Feature;

use App\Models\TenantPlatformAdministratorLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TenantPlatformAdministratorLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_user_can_be_identified_as_the_protected_platform_administrator(): void
    {
        $user = User::factory()->create();

        TenantPlatformAdministratorLink::query()->create([
            'user_id' => $user->getKey(),
            'platform_administrator_uuid' => (string) Str::uuid(),
        ]);

        $this->assertTrue($user->isPlatformAdministrator());
        $this->assertFalse(User::factory()->create()->isPlatformAdministrator());
    }

    public function test_platform_support_identity_is_hidden_from_tenant_user_management(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create(['name' => 'Tenant Admin']);
        $admin->assignRole('admin');
        $regularUser = User::factory()->create(['name' => 'Visible Tenant User']);
        $platformUser = User::factory()->create(['name' => 'Internal Platform Support']);
        TenantPlatformAdministratorLink::query()->create([
            'user_id' => $platformUser->id,
            'platform_administrator_uuid' => (string) Str::uuid(),
        ]);
        $dualRoleOwner = User::factory()->create([
            'name' => 'Tenant Owner',
            'is_tenant_administrator' => true,
        ]);
        TenantPlatformAdministratorLink::query()->create([
            'user_id' => $dualRoleOwner->id,
            'platform_administrator_uuid' => (string) Str::uuid(),
        ]);

        $this->actingAs($admin);

        Volt::test('users.index')
            ->assertSee($regularUser->name)
            ->assertSee($dualRoleOwner->name)
            ->assertDontSee($platformUser->name);

        $this->assertFalse(User::tenantManaged()->whereKey($platformUser->id)->exists());
    }
}
