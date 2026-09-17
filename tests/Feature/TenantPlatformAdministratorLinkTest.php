<?php

namespace Tests\Feature;

use App\Models\TenantPlatformAdministratorLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
}
